<?php

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notification;
use App\Modules\Fees\Domain\Gateway\FakeGateway;
use App\Modules\Fees\Domain\Gateway\Gateways;
use App\Modules\Fees\Domain\Payments;
use App\Modules\Fees\Jobs\SendFakeWebhook;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Fees\Models\GatewayAccount;
use App\Modules\Fees\Models\GatewayOrder;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 8 "done when": an online test payment marks the due paid with no
| manual step, a replayed webhook changes nothing, a counter payment and its
| reversal both show in the ledger with who and why, and a waiver cannot
| apply without the Principal's approval. Plus the owner's rules: whole
| paise (1), receipt numbers from an atomic counter in the payment
| transaction (2), a server-set amount, a signed and idempotent webhook (3),
| the fake driver (4), nobody approves their own fee action (5), step-up on
| approvals and exports (6), notifications without amounts or names, and
| class teachers see paid or pending only (7).
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 10:00', 'Asia/Kolkata'));
    $this->school = $this->activeSchool('FEES-BPL', ['modules' => ['fees']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $sections = $this->makeClass($this->tenant, '6', ['A', 'B']);
    $this->section = $sections['A'];
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->clerk = $this->staffMember($this->tenant, Role::Clerk);
    $this->clerk2 = $this->staffMember($this->tenant, Role::Clerk);
    $this->classTeacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->section, $this->classTeacher['staff']);
    $collector = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false, extraRoles: ['fee_collector']);
    $this->collector = ['user' => $collector];

    $this->aarav = $this->enrol($this->tenant, $this->section, 'Aarav Patel', '+919811100101', ['admission_no' => 'A-001']);
    $this->diya = $this->enrol($this->tenant, $this->section, 'Diya Shah', null, ['admission_no' => 'A-002']);
    // Aarav's younger brother: same guardian, later admission number (sibling discount).
    $this->kabir = $this->enrol($this->tenant, $sections['B'], 'Kabir Patel', null, ['admission_no' => 'A-003']);
    $this->inSchool($this->tenant, fn () => Student::query()->findOrFail($this->kabir->getKey())->fill(['guardian_ids' => Student::query()->findOrFail($this->aarav->getKey())->guardian_ids])->save());
    $this->parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $this->guardianOf($this->tenant, $this->aarav)));

    $heads = [];
    foreach (['Tuition', 'Annual charges', 'Transport'] as $name) {
        $heads[$name] = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/heads', ['name' => $name])->assertCreated()->json('data.id');
    }
    $this->heads = $heads;
    $this->plan = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/plans', [
        'name' => 'Class 6 regular', 'grades' => ['6'],
        'instalments' => [
            ['label' => 'Quarter 1', 'due_on' => '2026-04-10', 'lines' => [['head_id' => $heads['Tuition'], 'amount' => '1500.50'], ['head_id' => $heads['Annual charges'], 'amount' => '2,000'], ['head_id' => $heads['Transport'], 'amount' => '800']]],
            ['label' => 'Quarter 2', 'due_on' => '2026-07-10', 'lines' => [['head_id' => $heads['Tuition'], 'amount' => '1500.50'], ['head_id' => $heads['Transport'], 'amount' => '800']]],
            ['label' => 'Quarter 3', 'due_on' => '2026-10-10', 'lines' => [['head_id' => $heads['Tuition'], 'amount' => '1500.50'], ['head_id' => $heads['Transport'], 'amount' => '800']]],
        ],
        'fine' => ['mode' => 'flat', 'amount' => '50', 'grace_days' => 5],
        'discounts' => [['label' => 'Sibling', 'condition' => 'sibling', 'kind' => 'percent', 'value' => '10', 'head_ids' => [$heads['Tuition']]]],
    ])->assertCreated()->json('data');
    // Diya's transport starts in August.
    $this->actingWithToken($this->clerk['user'])->putJson('/api/v1/fees/students/'.$this->diya->getKey().'/setup', ['overrides' => [['head_id' => $heads['Transport'], 'to' => '2026-07-31', 'amount' => '0']]])->assertOk();
    $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/demands/raise')->assertOk()->assertJsonPath('data.raised', 3);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function feeDues(object $test, Student $student, $as = null): array
{
    return $test->actingWithToken($as ?? $test->clerk['user'])->getJson('/api/v1/students/'.$student->getKey().'/fees')->assertOk()->json('data');
}

function feeCollect(object $test, Student $student, string $amount, array $extra = [], $as = null)
{
    return $test->actingWithToken($as ?? $test->collector['user'])->postJson('/api/v1/fees/payments', array_merge(['student_id' => (string) $student->getKey(), 'mode' => 'cash', 'amount' => $amount], $extra));
}

function feeWebhook(object $test, string $body, string $signature, string $gateway = 'fake')
{
    app('auth')->forgetGuards();

    return $test->withoutToken()->call('POST', "/api/v1/webhooks/payments/{$gateway}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature], $body);
}

function feeGateway(object $test, string $secret = 'whsec-test-123456'): void
{
    $test->actingWithToken($test->chief)->putJson('/api/v1/fees/gateway', ['provider' => 'fake', 'key_id' => 'fake_key_001', 'key_secret' => 'fake-secret-abcdef', 'webhook_secret' => $secret])->assertOk();
}

function feeLedgerCount(object $test): int
{
    return $test->inSchool($test->tenant, fn () => FeeLedgerEntry::query()->count());
}

it('raises dues from the plan in whole paise, with overrides, sibling discounts and only instalments coming due', function () {
    $a = feeDues($this, $this->aarav);
    expect($a['demands'])->toHaveCount(1)
        ->and($a['demands'][0])->toMatchArray(['label' => 'Quarter 1', 'total_paise' => 430050, 'due_paise' => 430050, 'balance_paise' => 430050, 'status' => 'due'])
        ->and(collect($a['upcoming'])->pluck('label')->all())->toBe(['Quarter 2', 'Quarter 3']);

    // Diya: no transport until August. Kabir: 10% off tuition (150.05, exact: 150050 x 10% = 15005).
    expect(feeDues($this, $this->diya)['demands'][0]['total_paise'])->toBe(350050);
    $kabir = feeDues($this, $this->kabir)['demands'][0];
    expect($kabir['discounts'])->toBe([['label' => 'Sibling', 'head_id' => $this->heads['Tuition'], 'amount_paise' => 15005]])->and($kabir['total_paise'])->toBe(415045);

    // Running again raises nothing twice; in July the second quarter comes due.
    $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/demands/raise')->assertOk()->assertJsonPath('data.raised', 0);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:00', 'Asia/Kolkata'));
    $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/demands/raise')->assertOk()->assertJsonPath('data.raised', 3);

    // A plan change affects only what is not raised yet.
    $plan = $this->plan;
    $plan['instalments'][2]['lines'][0]['amount'] = '1600';
    $this->actingWithToken($this->clerk['user'])->putJson('/api/v1/fees/plans/'.$plan['id'], ['instalments' => array_map(fn ($i) => ['label' => $i['label'], 'due_on' => $i['due_on'],
        'lines' => array_map(fn ($l) => ['head_id' => $l['head_id'], 'amount' => $l['amount'] ?? number_format($l['amount_paise'] / 100, 2, '.', '')], $i['lines'])], $plan['instalments'])])->assertOk();
    $after = feeDues($this, $this->aarav);
    expect(collect($after['demands'])->pluck('total_paise')->all())->toBe([430050, 230050])->and($after['upcoming'][0]['total_paise'])->toBe(240000);

    // A mid-year joiner is charged from the joining month.
    $late = $this->enrol($this->tenant, $this->section, 'Meera Iyer', null, ['admission_no' => 'A-009']);
    $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/demands/raise')->assertOk();
    expect(collect(feeDues($this, $late)['demands'])->pluck('label')->all())->toBe(['Quarter 2']);
});

it('takes a counter payment with a receipt number from the counter, and shows the payment and its reversal in the ledger with who and why', function () {
    $first = feeCollect($this, $this->aarav, '1,000.25')->assertCreated()->json('data');
    $second = feeCollect($this, $this->diya, '500')->assertCreated()->json('data');
    expect($first['receipt_no'])->toBe('2026-27/000001')->and($second['receipt_no'])->toBe('2026-27/000002')->and($first['amount_paise'])->toBe(100025);

    // Never more than is owed, never a float.
    feeCollect($this, $this->aarav, '9999')->assertStatus(422)->assertJsonPath('error.code', 'amount_above_dues');
    feeCollect($this, $this->aarav, '10.555')->assertStatus(422)->assertJsonPath('error.code', 'amount_invalid');
    expect(feeDues($this, $this->aarav)['balance_paise'])->toBe(430050 - 100025);

    // The family is told only that a payment was received.
    $note = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $this->parent->getKey())->where('type', 'fees.payment_received')->firstOrFail());
    expect($note->title)->toBe('A payment was received')->and($note->body)->toBeNull()
        ->and(json_encode($note->toArray()))->not->toContain('1000')->not->toContain('Aarav');
    expect(config('communication.generic.fees.payment_received') ?? config('communication.messages.fees.payment_received') ?? 'A payment was received at :school. Open Campus TAB for the receipt.')
        ->not->toContain('amount');

    // The collector asks to cancel; the Clerk forwards; the collector cannot approve; the Principal approves with step-up.
    $cancel = $this->actingWithToken($this->collector['user'])->postJson('/api/v1/receipts/'.$first['receipt_id'].'/cancel', ['reason' => 'Entered against the wrong student'])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
    $this->actingWithToken($this->collector['user'], steppedUp: true)->postJson("/api/v1/fees/cancellations/{$cancel}/approve", ['reason' => 'Trying my own'])->assertForbidden();
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/fees/cancellations/{$cancel}/forward")->assertOk()->assertJsonPath('data.status', 'proposed');
    $this->actingWithToken($this->clerk['user'], steppedUp: true)->postJson("/api/v1/fees/cancellations/{$cancel}/approve", ['reason' => 'Forwarded it myself'])->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/fees/cancellations/{$cancel}/approve", ['reason' => 'Checked the counter slip'])->assertForbidden()->assertJsonPath('error.code', 'step_up_required');
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/fees/cancellations/{$cancel}/approve", ['reason' => 'Checked the counter slip'])->assertOk()->assertJsonPath('data.status', 'approved');

    // The receipt keeps its number; the ledger has the payment and its reversal, with who and why.
    $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/receipts/'.$first['receipt_id'])->assertOk()->assertJsonPath('data.number', '2026-27/000001')->assertJsonPath('data.status', 'cancelled');
    $ledger = collect(feeDues($this, $this->aarav)['ledger']);
    $payment = $ledger->where('type', 'payment');
    $reversal = $ledger->where('type', 'reversal');
    expect((int) $payment->sum('amount_paise'))->toBe(-100025)->and((int) $reversal->sum('amount_paise'))->toBe(100025)
        ->and($payment->first()['by']['name'])->toBe($this->collector['user']->name)
        ->and($reversal->first()['by']['name'])->toBe($this->principal['user']->name)
        ->and($reversal->first()['reason'])->toContain('Entered against the wrong student')
        ->and(feeDues($this, $this->aarav)['balance_paise'])->toBe(430050);

    // The next receipt does not reuse the cancelled number.
    expect(feeCollect($this, $this->aarav, '100')->assertCreated()->json('data.receipt_no'))->toBe('2026-27/000003');
});

it('keeps the ledger append-only and never hands out a number from a payment that failed', function () {
    feeCollect($this, $this->aarav, '100')->assertCreated();
    $this->inSchool($this->tenant, function () {
        $entry = FeeLedgerEntry::query()->firstOrFail();
        expect(fn () => $entry->fill(['amount_paise' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);

        // A payment that fails inside its transaction rolls its receipt number back with it.
        $student = Student::query()->findOrFail($this->diya->getKey());
        expect(fn () => app(Payments::class)->take($student, ['mode' => 'cash', 'amount_paise' => 100], ['id' => 'x', 'name' => 'X'], null, null, fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class);
        expect(Receipt::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
    });
    expect(feeCollect($this, $this->diya, '100')->assertCreated()->json('data.receipt_no'))->toBe('2026-27/000002');
});

it('posts the late fine once, at the first payment after the due date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 09:00', 'Asia/Kolkata'));
    $d = feeDues($this, $this->aarav)['demands'][0];
    expect($d)->toMatchArray(['fine_paise' => 5000, 'fine_posted' => false, 'balance_paise' => 435050, 'status' => 'overdue']);
    $paid = feeCollect($this, $this->aarav, '4350.50')->assertCreated()->json('data');
    expect($paid['allocations'][0])->toMatchArray(['head_id' => 'fine', 'amount_paise' => 5000]);
    expect(feeDues($this, $this->aarav)['demands'][0])->toMatchArray(['fine_paise' => 5000, 'fine_posted' => true, 'balance_paise' => 0, 'status' => 'paid']);
});

it('applies a waiver only after the Principal approves it, never by whoever proposed it', function () {
    $id = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/adjustments', [
        'student_id' => (string) $this->aarav->getKey(), 'kind' => 'waiver', 'amount' => '1000', 'reason' => 'Family hardship, letter on file',
    ])->assertCreated()->assertJsonPath('data.status', 'proposed')->json('data.id');
    expect(feeDues($this, $this->aarav)['balance_paise'])->toBe(430050);

    // In the Principal's approvals inbox, not the Clerk's.
    expect(collect($this->actingWithToken($this->principal['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->json('data'))->pluck('id')->all())->toContain('adjustment:'.$id);
    expect(collect($this->actingWithToken($this->clerk['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->json('data'))->pluck('id')->all())->not->toContain('adjustment:'.$id);

    $this->actingWithToken($this->clerk['user'], steppedUp: true)->postJson("/api/v1/fees/adjustments/{$id}/approve")->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/fees/adjustments/{$id}/approve")->assertForbidden()->assertJsonPath('error.code', 'step_up_required');
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/fees/adjustments/{$id}/approve", ['reason' => 'Agreed'])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(feeDues($this, $this->aarav)['balance_paise'])->toBe(330050);

    // The Chief's own proposal is approved by someone else.
    $diyaDemand = feeDues($this, $this->diya)['demands'][0]['id'];
    $own = $this->actingWithToken($this->chief)->postJson('/api/v1/fees/adjustments', ['student_id' => (string) $this->diya->getKey(), 'kind' => 'rebate', 'percent' => '10',
        'demand_id' => $diyaDemand, 'reason' => 'Staff ward concession this year'])->assertCreated();
    expect($own->json('data.amount_paise'))->toBe(35005);
    $this->actingWithToken($this->chief, steppedUp: true)->postJson('/api/v1/fees/adjustments/'.$own->json('data.id').'/approve')->assertForbidden()->assertJsonPath('error.code', 'own_proposal');

    // A waiver above the school's threshold alerts the Chief, without the amount or the name.
    $big = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/adjustments', ['student_id' => (string) $this->kabir->getKey(), 'kind' => 'waiver', 'amount' => '4150.45', 'reason' => 'Full waiver, scholarship board'])->json('data.id');
    $this->inSchool($this->tenant, function () {
        $service = app(SchoolConfigService::class);
        $body = $service->current()->body;
        $body['policies']['fees']['chief_alert_paise'] = 300000;
        $service->save($this->chief, $body, $service->current()->version);
    });
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/fees/adjustments/{$big}/approve")->assertOk();
    $alert = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $this->chief->getKey())->where('type', 'fees.waiver_alert')->firstOrFail());
    expect($alert->title)->toBe('A large fee waiver was approved')->and(json_encode($alert->toArray()))->not->toContain('4150')->not->toContain('Kabir');

    // When the school says waivers are the Chief's, the Principal cannot approve.
    $this->inSchool($this->tenant, function () {
        $service = app(SchoolConfigService::class);
        $body = $service->current()->body;
        $body['policies']['fees']['waiver_by'] = 'chief';
        $service->save($this->chief, $body, $service->current()->version);
    });
    $next = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/adjustments', ['student_id' => (string) $this->diya->getKey(), 'kind' => 'waiver', 'amount' => '10', 'reason' => 'Rounding off the dues'])->json('data.id');
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/fees/adjustments/{$next}/approve")->assertForbidden();
    $this->actingWithToken($this->chief, steppedUp: true)->postJson("/api/v1/fees/adjustments/{$next}/approve")->assertOk();
});

it('lets nobody confirm their own day-end closing, and only the Principal confirm a Clerk\'s', function () {
    feeCollect($this, $this->aarav, '500')->assertCreated();
    feeCollect($this, $this->diya, '250', ['mode' => 'upi_counter', 'reference' => 'UPI-445566'])->assertCreated();
    $closing = $this->actingWithToken($this->collector['user'])->postJson('/api/v1/fees/closings')->assertCreated()->json('data');
    expect($closing['total_paise'])->toBe(75000)->and($closing['cash_paise'])->toBe(50000);
    $this->actingWithToken($this->collector['user'])->postJson('/api/v1/fees/closings')->assertStatus(409);
    $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/closings/'.$closing['id'].'/confirm', ['counted_cash' => '500'])->assertOk()->assertJsonPath('data.status', 'confirmed');

    // The Clerk collects too: another Clerk cannot confirm it, the Clerk cannot confirm their own, the Principal can.
    feeCollect($this, $this->kabir, '300', [], $this->clerk['user'])->assertCreated();
    $own = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/fees/closings')->assertCreated()->json('data.id');
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/fees/closings/{$own}/confirm", ['counted_cash' => '300'])->assertForbidden()->assertJsonPath('error.code', 'own_closing');
    $this->actingWithToken($this->clerk2['user'])->postJson("/api/v1/fees/closings/{$own}/confirm", ['counted_cash' => '300'])->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/fees/closings/{$own}/confirm", ['counted_cash' => '300'])->assertOk();
});

it('keeps a cheque pending until it clears, and reverses it when it bounces', function () {
    $p = feeCollect($this, $this->aarav, '2000', ['mode' => 'cheque', 'reference' => '004512', 'bank' => 'State Bank'])->assertCreated()->assertJsonPath('data.status', 'pending_clearance')->json('data');
    $this->actingWithToken($this->collector['user'])->postJson('/api/v1/fees/payments/'.$p['id'].'/bounce', ['reason' => 'Returned: insufficient funds'])->assertOk()->assertJsonPath('data.status', 'bounced');
    expect(feeDues($this, $this->aarav)['balance_paise'])->toBe(430050);
    $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/receipts/'.$p['receipt_id'])->assertJsonPath('data.status', 'bounced')->assertJsonPath('data.number', '2026-27/000001');
    $this->actingWithToken($this->collector['user'])->postJson('/api/v1/fees/payments/'.$p['id'].'/clear')->assertStatus(409);
});

it('marks the due paid from a signed fake-gateway webhook with no manual step, and a replay changes nothing', function () {
    Queue::fake();
    feeGateway($this);

    // The secrets are stored encrypted and never sent back.
    $raw = $this->inSchool($this->tenant, fn () => DB::connection('mongodb')->getCollection('gateway_accounts')->findOne(['tenant_id' => $this->tenant->id]));
    expect(json_encode($raw))->not->toContain('whsec-test-123456')->not->toContain('fake-secret-abcdef');
    $this->actingWithToken($this->principal['user'])->getJson('/api/v1/fees/gateway')->assertOk()->assertJsonMissingPath('data.key_secret')->assertJsonMissingPath('data.webhook_secret');
    $this->actingWithToken($this->principal['user'])->putJson('/api/v1/fees/gateway', ['provider' => 'fake', 'key_id' => 'x_1'])->assertForbidden();

    // The parent pays: the amount comes from the dues, whatever the browser sends.
    $demand = feeDues($this, $this->aarav, $this->parent)['demands'][0]['id'];
    app('auth')->forgetGuards();
    $order = $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout', ['student_id' => (string) $this->aarav->getKey(), 'demand_ids' => [$demand], 'amount' => '1'])->assertCreated()->json('data');
    expect($order['amount_paise'])->toBe(430050)->and($order['fake'])->toBeTrue()->and($order)->not->toHaveKey('key_secret');
    $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout', ['student_id' => (string) $this->diya->getKey()])->assertNotFound();

    $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout/'.$order['id'].'/fake', ['outcome' => 'paid'])->assertStatus(202);
    $job = null;
    Queue::assertPushed(SendFakeWebhook::class, function (SendFakeWebhook $j) use (&$job) {
        $job = $j;

        return true;
    });
    expect(hash_hmac('sha256', $job->body, 'whsec-test-123456'))->toBe($job->signature);

    // A forged signature changes nothing.
    feeWebhook($this, $job->body, str_repeat('0', 64))->assertStatus(401);
    expect(feeDues($this, $this->aarav, $this->parent)['balance_paise'])->toBe(430050);

    feeWebhook($this, $job->body, $job->signature)->assertOk()->assertJsonPath('data.outcome', 'paid');
    $dues = feeDues($this, $this->aarav, $this->parent);
    expect($dues['demands'][0]['status'])->toBe('paid')->and($dues['receipts'])->toHaveCount(1)->and($dues['receipts'][0]['mode'])->toBe('online');
    $this->actingWithToken($this->parent)->getJson('/api/v1/fees/checkout/'.$order['id'])->assertOk()->assertJsonPath('data.status', 'paid');

    // Replayed: nothing changes.
    $entries = feeLedgerCount($this);
    feeWebhook($this, $job->body, $job->signature)->assertOk()->assertJsonPath('data.outcome', 'replayed');
    feeWebhook($this, $job->body, $job->signature)->assertOk();
    expect(feeLedgerCount($this))->toBe($entries)->and($this->inSchool($this->tenant, fn () => Receipt::query()->count()))->toBe(1);
});

it('refuses a webhook whose amount or currency differs from the order, and flags it to the Clerk', function () {
    feeGateway($this);
    $order = $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout', ['student_id' => (string) $this->aarav->getKey()])->assertCreated()->json('data');
    $body = (string) json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
        'id' => 'pay_fake'.Str::random(14), 'order_id' => $order['order_id'], 'amount' => 100, 'currency' => 'INR', 'status' => 'captured',
    ]]]]);
    feeWebhook($this, $body, hash_hmac('sha256', $body, 'whsec-test-123456'))->assertOk()->assertJsonPath('data.outcome', 'mismatch');
    expect(feeDues($this, $this->aarav)['balance_paise'])->toBe(430050);
    $note = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $this->clerk['user']->getKey())->where('type', 'fees.reconciliation')->first());
    expect($note)->not->toBeNull();
});

it('finds the school from the order record only (decision 0015): another school\'s secret or an unknown order gets nothing', function () {
    feeGateway($this);
    $order = $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout', ['student_id' => (string) $this->aarav->getKey()])->assertCreated()->json('data');

    // A second school with its own gateway secret.
    $other = $this->activeSchool('OTHER-BPL', ['modules' => ['fees']]);
    $this->actingWithToken($other['chief'])->putJson('/api/v1/fees/gateway', ['provider' => 'fake', 'key_id' => 'fake_key_002', 'key_secret' => 'other-secret-abc', 'webhook_secret' => 'other-whsec-999'])->assertOk();

    $body = (string) json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
        'id' => 'pay_fake'.Str::random(14), 'order_id' => $order['order_id'], 'amount' => $order['amount_paise'], 'currency' => 'INR', 'status' => 'captured',
    ]]]]);
    // Signed with the OTHER school's secret: refused, because the order belongs to FEES-BPL.
    feeWebhook($this, $body, hash_hmac('sha256', $body, 'other-whsec-999'))->assertStatus(401);
    // An order id we never made.
    $unknown = str_replace($order['order_id'], 'order_fake'.Str::random(14), $body);
    feeWebhook($this, $unknown, hash_hmac('sha256', $unknown, 'whsec-test-123456'))->assertNotFound();
    // A body with no order id at all.
    feeWebhook($this, '{"event":"payment.captured"}', 'x')->assertStatus(400);
    expect(feeDues($this, $this->aarav)['balance_paise'])->toBe(430050);
});

it('uses Razorpay test-mode keys only, and never the fake driver outside local or testing', function () {
    $this->actingWithToken($this->chief)->putJson('/api/v1/fees/gateway', ['provider' => 'razorpay', 'key_id' => 'rzp_live_abc123', 'key_secret' => 'live-secret-xyz', 'webhook_secret' => 'live-whsec-xyz'])
        ->assertStatus(422)->assertJsonPath('error.code', 'gateway_test_mode_only');
    $this->actingWithToken($this->chief)->putJson('/api/v1/fees/gateway', ['provider' => 'razorpay', 'key_id' => 'rzp_test_abc123', 'key_secret' => 'test-secret-xyz', 'webhook_secret' => 'test-whsec-xyz'])->assertOk();

    Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_RzpTest12345', 'amount' => 430050, 'currency' => 'INR'])]);
    $order = $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout', ['student_id' => (string) $this->aarav->getKey()])->assertCreated()->json('data');
    expect($order)->toMatchArray(['provider' => 'razorpay', 'order_id' => 'order_RzpTest12345', 'key_id' => 'rzp_test_abc123']);
    Http::assertSent(fn ($r) => $r['amount'] === 430050 && $r['currency'] === 'INR' && $r->hasHeader('Authorization'));
    // The fake checkout is refused for a Razorpay order.
    $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout/'.$order['id'].'/fake', ['outcome' => 'paid'])->assertNotFound();

    app()->detectEnvironment(fn () => 'production');
    try {
        expect(FakeGateway::allowed())->toBeFalse()
            ->and(fn () => new FakeGateway)->toThrow(LogicException::class)
            ->and(app(Gateways::class)->available())->toBe(['razorpay']);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('shows a class teacher paid or pending only, never an amount', function () {
    feeCollect($this, $this->diya, '3500.50')->assertCreated();
    $rows = $this->actingWithToken($this->classTeacher['user'])->getJson('/api/v1/fees/sections/'.$this->section->getKey().'/status')->assertOk()->json('data.students');
    expect(collect($rows)->pluck('status', 'name')->all())->toMatchArray(['Aarav Patel' => 'pending', 'Diya Shah' => 'paid'])
        ->and(array_keys($rows[0]))->toBe(['student_id', 'name', 'status'])
        ->and(json_encode($rows))->not->toContain('paise');
    $this->actingWithToken($this->classTeacher['user'])->getJson('/api/v1/students/'.$this->aarav->getKey().'/fees')->assertNotFound();
    // Another class's section: nothing.
    $other = $this->inSchool($this->tenant, fn () => Section::query()->where('name', 'B')->firstOrFail());
    $this->actingWithToken($this->classTeacher['user'])->getJson('/api/v1/fees/sections/'.$other->getKey().'/status')->assertNotFound();
    // A parent sees their own child only.
    $this->actingWithToken($this->parent)->getJson('/api/v1/students/'.$this->diya->getKey().'/fees')->assertNotFound();
});

it('reports collections and exports Excel only after step-up, with an audit entry', function () {
    feeCollect($this, $this->aarav, '500')->assertCreated();
    feeCollect($this, $this->diya, '250')->assertCreated();
    $daily = $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/fees/reports/daily')->assertOk()->json('data.rows');
    expect($daily)->toHaveCount(1)->and($daily[0])->toMatchArray(['mode' => 'cash', 'count' => 2, 'amount_paise' => 75000]);
    $heads = collect($this->actingWithToken($this->clerk['user'])->getJson('/api/v1/fees/reports/heads')->assertOk()->json('data.rows'))->pluck('collected_paise', 'head');
    expect($heads['Tuition'])->toBe(75000);
    $dues = $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/fees/reports/dues')->assertOk()->json('data.rows');
    expect($dues)->toHaveCount(3);

    // A Fee Collector sees only their own daily collections.
    $this->actingWithToken($this->collector['user'])->getJson('/api/v1/fees/reports/dues')->assertForbidden();
    $this->actingWithToken($this->collector['user'])->getJson('/api/v1/fees/reports/daily')->assertOk()->assertJsonCount(1, 'data.rows');

    $this->actingWithToken($this->clerk['user'])->get('/api/v1/fees/reports/daily/export')->assertForbidden();
    $file = $this->actingWithToken($this->clerk['user'], steppedUp: true)->get('/api/v1/fees/reports/daily/export')->assertOk();
    expect($file->headers->get('Content-Disposition'))->toContain('fees-daily-');
    expect($this->inSchool($this->tenant, fn () => AuditLog::query()->where('action', 'fees.report_exported')->exists()))->toBeTrue();
});

it('watermarks Excel and PDF exports with who, when and which school, and alerts the Chief on a large one', function () {
    feeCollect($this, $this->aarav, '500')->assertCreated();
    $clerk = $this->clerk['user'];

    // Excel: the first row, the printed footer and the file's properties.
    $xlsx = $this->actingWithToken($clerk, steppedUp: true)->get('/api/v1/fees/reports/dues/export')->assertOk();
    $path = $xlsx->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $props = (string) $zip->getFromName('docProps/core.xml');
    $zip->close();
    $school = $this->tenant->name;
    expect($sheet)->toContain('Campus TAB · '.$school.' · exported by '.$clerk->name.' (Clerk) on ')
        ->toContain('&amp;LCampus TAB')
        ->and($props)->toContain($clerk->name.' (Clerk)');
    // A template with the watermark still imports (the first row is skipped).
    expect(Spreadsheet::read($path, 'x.xlsx')[0])->toHaveKey('student');

    // PDF: the same facts on every page (diagonal and footer).
    $pdf = $this->actingWithToken($clerk, steppedUp: true)->get('/api/v1/fees/reports/dues/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF')->toContain($school.' · '.$clerk->name.' (Clerk)')->toContain('confidential');

    // Under the threshold: audited, no alert. Over it: the Chief is told.
    $this->inSchool($this->tenant, function () {
        expect(AuditLog::query()->where('action', 'export.made')->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'export.large_alert')->exists())->toBeFalse();
    });
    config(['exports.large_rows' => 2]);
    $this->actingWithToken($clerk, steppedUp: true)->get('/api/v1/fees/reports/dues/export')->assertOk();
    $this->inSchool($this->tenant, function () use ($clerk) {
        expect(AuditLog::query()->where('action', 'export.large_alert')->firstOrFail()->after)->toMatchArray(['rows' => 3, 'threshold' => 2, 'by' => $clerk->name]);
        $note = Notification::query()->where('user_id', (string) $this->school['chief']->getKey())->where('type', 'export.large')->firstOrFail();
        expect($note->title)->toContain('3 rows')->toContain($clerk->name);
    });
});

it('reconciles the day\'s online payments against the gateway\'s settlement report', function () {
    Queue::fake();
    feeGateway($this);
    $order = $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout', ['student_id' => (string) $this->aarav->getKey()])->assertCreated()->json('data');
    $this->actingWithToken($this->parent)->postJson('/api/v1/fees/checkout/'.$order['id'].'/fake', ['outcome' => 'paid'])->assertStatus(202);
    Queue::assertPushed(SendFakeWebhook::class, function (SendFakeWebhook $j) {
        feeWebhook($this, $j->body, $j->signature)->assertOk();

        return true;
    });
    $this->artisan('fees:reconcile', ['--date' => '2026-04-02', '--school' => 'FEES-BPL'])->assertSuccessful();
    $run = $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/fees/reconciliations')->assertOk()->json('data.0');
    expect($run)->toMatchArray(['status' => 'ok', 'matched' => 1, 'mismatches' => []]);

    // A payment the gateway does not list is flagged.
    $this->inSchool($this->tenant, fn () => GatewayOrder::query()->update(['status' => 'created']));
    $this->artisan('fees:reconcile', ['--date' => '2026-04-02', '--school' => 'FEES-BPL'])->assertSuccessful();
    expect($this->actingWithToken($this->clerk['user'])->getJson('/api/v1/fees/reconciliations')->json('data.0.mismatches.0.reason'))->toBe('missing_there');
});

it('needs Fees in the licence', function () {
    $plain = $this->activeSchool('NOFEE-BPL', ['modules' => ['attendance']]);
    $this->actingWithToken($plain['chief'])->getJson('/api/v1/fees/heads')->assertForbidden();
    expect($this->inSchool($this->tenant, fn () => GatewayAccount::query()->count()))->toBe(0)
        ->and($this->inSchool($this->tenant, fn () => FeeDemand::query()->count()))->toBe(3);
});
