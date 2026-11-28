<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeHead;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Fees\Models\Payment;
use App\Support\Http\ApiException;

/**
 * Fee reports for the office and the school's accountant:
 *
 *   daily        collection by day, collector and mode
 *   dues         dues and defaulters by class (who has overdue instalments)
 *   heads        collection by fee head
 *   adjustments  rebates, waivers and other adjustments given
 *   cheques      cheques and DDs with their status
 *
 * Each is a list of columns and rows; amounts stay whole paise (the screen
 * and the Excel file show rupees). A Fee Collector (fees.reports:own) gets
 * only the daily report of their own collections.
 */
class FeeReports
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @param  array{from?: string|null, to?: string|null, section_id?: string|null}  $filters
     * @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>}
     */
    public function build(string $type, array $filters, ?string $onlyCollector = null): array
    {
        if ($onlyCollector !== null && $type !== 'daily') {
            throw new ApiException('permission_denied', 'You can see the report of your own collections only.', 403);
        }
        $from = $filters['from'] ?? now('Asia/Kolkata')->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now('Asia/Kolkata')->toDateString();

        return match ($type) {
            'daily' => $this->daily($from, $to, $onlyCollector),
            'dues' => $this->dues($filters['section_id'] ?? null),
            'heads' => $this->heads($from, $to),
            'adjustments' => $this->adjustments($from, $to),
            'cheques' => $this->cheques($from, $to),
            default => throw new ApiException('report_unknown', 'Unknown report.', 404),
        };
    }

    /** @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>} */
    private function daily(string $from, string $to, ?string $collector): array
    {
        $rows = [];
        $payments = Payment::query()->where('received_on', '>=', $from)->where('received_on', '<=', $to)
            ->when($collector !== null, fn ($q) => $q->where('collector_id', $collector))->get();
        foreach ($payments->groupBy(fn (Payment $p) => $p->received_on.'|'.($p->collector['name'] ?? 'Online').'|'.$p->mode) as $key => $group) {
            [$date, $who, $mode] = explode('|', (string) $key);
            $live = $group->reject(fn (Payment $p) => in_array($p->status, [Payment::CANCELLED, Payment::BOUNCED], true));
            $rows[] = ['date' => $date, 'collector' => $who, 'mode' => $mode, 'count' => $live->count(), 'amount_paise' => (int) $live->sum('amount_paise'),
                'reversed_count' => $group->count() - $live->count()];
        }
        usort($rows, fn ($a, $b) => [$a['date'], $a['collector'], $a['mode']] <=> [$b['date'], $b['collector'], $b['mode']]);

        return ['columns' => [['key' => 'date'], ['key' => 'collector'], ['key' => 'mode'], ['key' => 'count'], ['key' => 'amount_paise', 'money' => true], ['key' => 'reversed_count']], 'rows' => $rows];
    }

    /** @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>} */
    private function dues(?string $sectionId): array
    {
        $year = AcademicYear::current();
        if (! $year instanceof AcademicYear) {
            return ['columns' => [], 'rows' => []];
        }
        $demands = FeeDemand::query()->where('year_id', (string) $year->getKey())->when($sectionId, fn ($q) => $q->where('section_id', $sectionId))->get();
        $states = $this->ledger->demandStates($demands);
        $rows = [];
        foreach ($demands->groupBy('student_id') as $studentId => $group) {
            $first = $group->first();
            $sum = fn (string $k) => (int) $group->sum(fn ($d) => $states[(string) $d->getKey()][$k]);
            $overdue = $group->filter(fn ($d) => in_array($states[(string) $d->getKey()]['status'], ['overdue', 'part_paid'], true) && $d->due_on < now('Asia/Kolkata')->toDateString())->count();
            $rows[] = ['class' => $first?->section_label, 'student' => $first?->student_name, 'raised_paise' => $sum('due') + $sum('fine'), 'paid_paise' => $sum('paid'),
                'adjusted_paise' => $sum('adjusted'), 'balance_paise' => $sum('balance'), 'overdue' => $overdue, 'defaulter' => $overdue > 0 ? 'yes' : 'no', 'student_id' => (string) $studentId];
        }
        usort($rows, fn ($a, $b) => [(string) $a['class'], (string) $a['student']] <=> [(string) $b['class'], (string) $b['student']]);

        return ['columns' => [['key' => 'class'], ['key' => 'student'], ['key' => 'raised_paise', 'money' => true], ['key' => 'paid_paise', 'money' => true],
            ['key' => 'adjusted_paise', 'money' => true], ['key' => 'balance_paise', 'money' => true], ['key' => 'overdue'], ['key' => 'defaulter']], 'rows' => $rows];
    }

    /** @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>} */
    private function heads(string $from, string $to): array
    {
        $heads = FeeHead::query()->get()->mapWithKeys(fn ($h) => [(string) $h->getKey() => $h->name])->all() + ['fine' => 'Late fine', 'carry_forward' => 'Carried forward'];
        $entries = FeeLedgerEntry::query()->whereIn('type', ['payment', 'reversal'])->whereNotNull('payment_id')
            ->where('created_at', '>=', now('Asia/Kolkata')->parse($from)->startOfDay())->where('created_at', '<=', now('Asia/Kolkata')->parse($to)->endOfDay())->get();
        $rows = [];
        foreach ($entries->groupBy(fn ($e) => (string) $e->head_id) as $headId => $group) {
            $rows[] = ['head' => $heads[$headId] ?? (string) $headId, 'collected_paise' => -(int) $group->sum('amount_paise')];
        }
        usort($rows, fn ($a, $b) => strcmp($a['head'], $b['head']));

        return ['columns' => [['key' => 'head'], ['key' => 'collected_paise', 'money' => true]], 'rows' => $rows];
    }

    /** @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>} */
    private function adjustments(string $from, string $to): array
    {
        $rows = FeeAdjustment::query()->where('status', 'approved')->get()
            ->filter(fn (FeeAdjustment $a) => substr((string) $a->decided_at, 0, 10) >= $from && substr((string) $a->decided_at, 0, 10) <= $to)
            ->map(fn (FeeAdjustment $a) => ['date' => substr((string) $a->decided_at, 0, 10), 'student' => $a->student_name, 'kind' => $a->kind, 'amount_paise' => $a->amount_paise,
                'reason' => $a->reason, 'proposed_by' => $a->proposed_by['name'] ?? null, 'approved_by' => $a->decided_by['name'] ?? null])
            ->sortBy('date')->values()->all();

        return ['columns' => [['key' => 'date'], ['key' => 'student'], ['key' => 'kind'], ['key' => 'amount_paise', 'money' => true], ['key' => 'reason'], ['key' => 'proposed_by'], ['key' => 'approved_by']], 'rows' => $rows];
    }

    /** @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>} */
    private function cheques(string $from, string $to): array
    {
        $rows = Payment::query()->whereIn('mode', (array) config('fees.clearance_modes'))->where('received_on', '>=', $from)->where('received_on', '<=', $to)->orderBy('received_on')->get()
            ->map(fn (Payment $p) => ['date' => $p->received_on, 'receipt' => $p->receipt_no, 'mode' => $p->mode, 'reference' => $p->reference, 'bank' => $p->bank,
                'amount_paise' => $p->amount_paise, 'status' => $p->status, 'payment_id' => (string) $p->getKey()])
            ->values()->all();

        return ['columns' => [['key' => 'date'], ['key' => 'receipt'], ['key' => 'mode'], ['key' => 'reference'], ['key' => 'bank'], ['key' => 'amount_paise', 'money' => true], ['key' => 'status']], 'rows' => $rows];
    }
}
