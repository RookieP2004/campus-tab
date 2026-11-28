<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Fees\Domain\Gateway\FakeGateway;
use App\Modules\Fees\Domain\Gateway\Gateways;
use App\Modules\Fees\Jobs\SendFakeWebhook;
use App\Modules\Fees\Models\FeeReconciliation;
use App\Modules\Fees\Models\GatewayAccount;
use App\Modules\Fees\Models\GatewayEvent;
use App\Modules\Fees\Models\GatewayOrder;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\CommandException;
use Throwable;

/**
 * Online payments (owner's rule 3).
 *
 *  Checkout   The family chooses dues; the SERVER works out the amount from
 *             them and creates the order at the gateway. Nothing about the
 *             amount is taken from the browser.
 *  Webhook    No signed-in user. The school is found from OUR order record
 *             (the second documented tenant-scope exception, decision 0015);
 *             then, inside that school only:
 *               1. the signature is checked on the raw body with the school's
 *                  webhook secret,
 *               2. the amount and currency must equal the order's,
 *               3. the payment is taken in one transaction together with a
 *                  GatewayEvent whose unique index (tenant, provider, gateway
 *                  payment id) makes a replay change nothing.
 *  Fake       A local driver (APP_ENV local or testing only) that sends a
 *             correctly signed webhook through the same route.
 */
class OnlinePayments
{
    public function __construct(
        private readonly Gateways $gateways,
        private readonly Payments $payments,
        private readonly Ledger $ledger,
        private readonly TenantContext $tenant,
        private readonly SchoolConfigService $configs,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    public function account(): ?GatewayAccount
    {
        return GatewayAccount::query()->where('status', 'active')->first();
    }

    /**
     * @param  list<string>  $demandIds
     * @return array<string, mixed>
     */
    public function checkout(User $user, Student $student, array $demandIds): array
    {
        $account = $this->account() ?? throw new ApiException('gateway_off', 'This school does not take online payments yet. Pay at the school office.', 409);
        $driver = $this->gateways->driver($account->provider);
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $demands = $this->payments->openDemands((string) $student->getKey(), (string) $year->getKey(), $demandIds === [] ? null : $demandIds);
        $states = $this->ledger->demandStates($demands);
        $chosen = $demands->filter(fn ($d) => $states[(string) $d->getKey()]['balance'] > 0)->values();
        $amount = $chosen->sum(fn ($d) => $states[(string) $d->getKey()]['balance']);
        if ($amount <= 0) {
            throw new ApiException('nothing_due', 'Nothing is due on the chosen instalments.', 409);
        }
        $ids = $chosen->map(fn ($d) => (string) $d->getKey())->sort()->values()->all();
        $currency = (string) config('fees.gateway.currency', 'INR');

        // The same choice within a few minutes reuses its order (a double tap, a retry).
        $order = GatewayOrder::query()->where('student_id', (string) $student->getKey())->where('status', 'created')->where('provider', $account->provider)
            ->where('amount_paise', $amount)->where('created_at', '>=', now()->subMinutes((int) config('fees.gateway.order_minutes', 30)))->get()
            ->first(fn (GatewayOrder $o) => $o->demand_ids === $ids);

        if (! $order instanceof GatewayOrder) {
            $created = $driver->createOrder($account, $amount, $currency, 'ctab-'.Str::lower(Str::random(12)));
            $order = GatewayOrder::create([
                'provider' => $account->provider, 'order_id' => $created['order_id'], 'student_id' => (string) $student->getKey(), 'year_id' => (string) $year->getKey(),
                'demand_ids' => $ids, 'amount_paise' => $amount, 'currency' => $currency, 'created_by' => (string) $user->getKey(),
            ]);
        }
        $tenant = Tenant::query()->findOrFail($this->tenant->require());

        return [
            'id' => (string) $order->getKey(), 'provider' => $order->provider, 'order_id' => $order->order_id, 'amount_paise' => $order->amount_paise, 'currency' => $order->currency,
            'school' => (string) ($this->configs->current()->body['school']['name'] ?? $tenant->name), 'status' => $order->status,
        ] + $driver->checkoutFields($account);
    }

    /**
     * The fake checkout's "Pay" or "Fail": queues a correctly signed webhook.
     */
    public function simulate(User $user, GatewayOrder $order, bool $success): void
    {
        if (! FakeGateway::allowed() || $order->provider !== 'fake') {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }
        if ($order->created_by !== (string) $user->getKey() || $order->status !== 'created') {
            throw new ApiException('order_not_open', 'This payment is not waiting any more.', 409);
        }
        $account = $this->account() ?? throw new ApiException('gateway_off', 'This school does not take online payments yet.', 409);
        $signed = (new FakeGateway)->simulate($account, $order, $success);
        SendFakeWebhook::dispatch($signed['body'], $signed['signature']);
    }

    /**
     * Handles a gateway webhook. Returns [http status, short outcome].
     *
     * @return array{0: int, 1: string}
     */
    public function webhook(string $provider, string $rawBody, string $signature): array
    {
        $driver = $this->gateways->driver($provider);
        $orderId = $driver->orderIdOf($rawBody);
        if ($orderId === null) {
            return [400, 'unknown_order'];
        }

        // Decision 0015: before the signature can be checked we need the
        // school's webhook secret, so the school is found from our own order
        // record, read inside a documented bypass. Only tenant_id is taken
        // from it; everything else runs inside that school.
        $tenantId = $this->tenant->bypass(
            'payment webhook: find the school from our order record (decision 0015)',
            fn () => GatewayOrder::query()->where('provider', $provider)->where('order_id', $orderId)->value('tenant_id'),
        );
        if (! is_string($tenantId) || $tenantId === '') {
            return [404, 'unknown_order'];
        }

        return $this->tenant->run($tenantId, function () use ($driver, $provider, $orderId, $rawBody, $signature): array {
            $order = GatewayOrder::query()->where('provider', $provider)->where('order_id', $orderId)->firstOrFail();
            $account = GatewayAccount::query()->where('provider', $provider)->first();
            if (! $account instanceof GatewayAccount || ! $driver->verifySignature($account, $rawBody, $signature)) {
                $this->audit->record('fees.webhook_rejected', 'fees', 'gateway_order', (string) $order->getKey(), null, null, ['reason' => 'signature']);

                return [401, 'bad_signature'];
            }
            $event = $driver->parse($rawBody);
            if ($event['payment_id'] === null) {
                return [200, 'ignored'];
            }
            if (! $event['captured']) {
                $this->record($provider, $event, 'failed', []);
                // The family's screen stops waiting; trying again makes a new order.
                if ($order->status === 'created') {
                    $order->fill(['status' => 'failed'])->save();
                }

                return [200, 'failed'];
            }
            if (GatewayEvent::query()->where('provider', $provider)->where('gateway_payment_id', $event['payment_id'])->where('outcome', 'paid')->exists() || $order->status === 'paid') {
                return [200, 'replayed'];
            }
            if ($event['amount_paise'] !== $order->amount_paise || $event['currency'] !== $order->currency) {
                return $this->mismatch($order, $provider, $event, 'amount_or_currency');
            }

            try {
                $student = Student::query()->findOrFail($order->student_id);
                $this->payments->take($student, ['mode' => 'online', 'amount_paise' => $order->amount_paise, 'reference' => $event['payment_id'], 'demand_ids' => $order->demand_ids],
                    null, ['provider' => $provider, 'order_id' => $order->order_id, 'payment_id' => (string) $event['payment_id']], ['id' => 'gateway', 'name' => 'Online payment'],
                    function (Payment $payment) use ($order, $provider, $event): void {
                        GatewayEvent::create(['provider' => $provider, 'gateway_payment_id' => $event['payment_id'], 'order_id' => $order->order_id, 'event' => $event['event'], 'outcome' => 'paid',
                            'details' => ['amount_paise' => $event['amount_paise'], 'currency' => $event['currency']]]);
                        $order->fill(['status' => 'paid', 'gateway_payment_id' => $event['payment_id'], 'payment_id' => (string) $payment->getKey(), 'receipt_no' => $payment->receipt_no])->save();
                    });
            } catch (BulkWriteException|CommandException $e) {
                if (str_contains($e->getMessage(), 'E11000')) {
                    return [200, 'replayed'];
                }
                throw $e;
            } catch (ApiException $e) {
                // The dues changed since checkout (paid at the counter meanwhile): flag it.
                return $this->mismatch(GatewayOrder::query()->findOrFail($order->getKey()), $provider, $event, $e->errorCode);
            }

            return [200, 'paid'];
        });
    }

    /**
     * Nightly: the gateway's settlement report for a day against our paid
     * orders. Mismatches are flagged to the Clerk (generic notification).
     */
    public function reconcile(string $date): ?FeeReconciliation
    {
        $account = $this->account();
        if (! $account instanceof GatewayAccount) {
            return null;
        }
        try {
            $theirs = collect($this->gateways->driver($account->provider)->settlements($account, $date))->keyBy('payment_id');
        } catch (Throwable $e) {
            report($e);

            return FeeReconciliation::create(['date' => $date, 'provider' => $account->provider, 'status' => 'failed', 'error' => 'The settlement report could not be read.']);
        }
        $ours = Payment::query()->where('mode', 'online')->where('received_on', $date)->whereNotNull('online')->get()->keyBy(fn ($p) => (string) ($p->online['payment_id'] ?? ''));
        $mismatches = [];
        $matched = 0;
        foreach ($ours as $id => $p) {
            $t = $theirs[$id] ?? null;
            if ($t === null) {
                $mismatches[] = ['gateway_payment_id' => $id, 'order_id' => $p->online['order_id'] ?? null, 'reason' => 'missing_there', 'ours_paise' => $p->amount_paise, 'theirs_paise' => null];
            } elseif ((int) $t['amount_paise'] !== $p->amount_paise) {
                $mismatches[] = ['gateway_payment_id' => $id, 'order_id' => $p->online['order_id'] ?? null, 'reason' => 'amount', 'ours_paise' => $p->amount_paise, 'theirs_paise' => (int) $t['amount_paise']];
            } else {
                $matched++;
            }
        }
        foreach ($theirs as $id => $t) {
            if (! isset($ours[$id])) {
                $mismatches[] = ['gateway_payment_id' => (string) $id, 'order_id' => $t['order_id'], 'reason' => 'missing_here', 'ours_paise' => null, 'theirs_paise' => (int) $t['amount_paise']];
            }
        }
        $run = FeeReconciliation::create(['date' => $date, 'provider' => $account->provider, 'matched' => $matched, 'mismatches' => $mismatches, 'status' => $mismatches === [] ? 'ok' : 'mismatch']);
        if ($mismatches !== []) {
            $this->tellClerks('fees.reconciliation', 'Online payments need checking');
        }

        return $run;
    }

    /**
     * @param  array{event: string, order_id: string|null, payment_id: string|null, amount_paise: int|null, currency: string|null, captured: bool}  $event
     * @return array{0: int, 1: string}
     */
    private function mismatch(GatewayOrder $order, string $provider, array $event, string $reason): array
    {
        $this->record($provider, $event, 'mismatch', ['reason' => $reason, 'expected_paise' => $order->amount_paise]);
        if ($order->status === 'created') {
            $order->fill(['status' => 'mismatch', 'gateway_payment_id' => $event['payment_id']])->save();
        }
        $this->audit->record('fees.webhook_mismatch', 'fees', 'gateway_order', (string) $order->getKey(), null, null, ['reason' => $reason]);
        $this->tellClerks('fees.reconciliation', 'Online payments need checking');

        return [200, 'mismatch'];
    }

    /**
     * @param  array{event: string, order_id: string|null, payment_id: string|null, amount_paise: int|null, currency: string|null, captured: bool}  $event
     * @param  array<string, mixed>  $details
     */
    private function record(string $provider, array $event, string $outcome, array $details): void
    {
        try {
            GatewayEvent::create(['provider' => $provider, 'gateway_payment_id' => (string) $event['payment_id'], 'order_id' => (string) $event['order_id'], 'event' => $event['event'], 'outcome' => $outcome, 'details' => $details]);
        } catch (BulkWriteException) {
            // Already recorded: a repeat of the same notification.
        }
    }

    private function tellClerks(string $type, string $title): void
    {
        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant) {
            $clerks = User::query()->where('roles', Role::Clerk->value)->where('status', User::STATUS_ACTIVE)->get();
            $this->notifier->notify($tenant, $clerks, $type, $title, null, '/fees/online', []);
        }
    }
}
