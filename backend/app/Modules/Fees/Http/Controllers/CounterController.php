<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Fees\Domain\Cheques;
use App\Modules\Fees\Domain\Closings;
use App\Modules\Fees\Domain\Money;
use App\Modules\Fees\Domain\Payments;
use App\Modules\Fees\Models\CashClosing;
use App\Modules\Fees\Models\Payment;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The fee counter (fees.collect).
 *
 *   POST /fees/payments { student_id, mode, amount ("1250.50"), reference?, bank?, demand_ids? }
 *   POST /fees/payments/{id}/clear            a cheque or DD cleared
 *   POST /fees/payments/{id}/bounce { reason } it bounced: the payment is reversed
 *   GET  /fees/closings                       my open payments and recent closings (confirmers: waiting ones)
 *   POST /fees/closings { note? }             close my day
 *   POST /fees/closings/{id}/confirm { counted_cash, note? }   someone else confirms
 */
class CounterController
{
    public function __construct(private readonly Payments $payments, private readonly Cheques $cheques, private readonly Closings $closings) {}

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('fees.collect');
        $user = StudentFeesController::user($request);
        $counterModes = array_values(array_diff((array) config('fees.modes'), ['online']));
        $input = $request->validate([
            'student_id' => ['required', 'string', 'max:30'], 'mode' => ['required', Rule::in($counterModes)], 'amount' => ['required', 'string', 'max:15'],
            'reference' => [Rule::requiredIf(fn () => in_array($request->input('mode'), ['cheque', 'dd', 'bank_transfer', 'upi_counter'], true)), 'nullable', 'string', 'max:60'],
            'bank' => ['nullable', 'string', 'max:60'], 'demand_ids' => ['sometimes', 'array', 'max:24'], 'demand_ids.*' => ['string', 'max:30'],
        ]);
        $student = Student::query()->find($input['student_id']) ?? throw new ApiException('not_found', 'The record was not found.', 404);
        $payment = $this->payments->take($student, [
            'mode' => $input['mode'], 'amount_paise' => Money::parse($input['amount']), 'reference' => $input['reference'] ?? null, 'bank' => $input['bank'] ?? null,
            'demand_ids' => $input['demand_ids'] ?? null,
        ], Cheques::who($user), null, $user);

        return response()->json(['data' => self::payment($payment)], 201);
    }

    public function clear(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.collect');

        return response()->json(['data' => self::payment($this->cheques->clear(StudentFeesController::user($request), Payment::query()->findOrFail($id)))]);
    }

    public function bounce(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.collect');
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:300']])['reason'];

        return response()->json(['data' => self::payment($this->cheques->bounce(StudentFeesController::user($request), Payment::query()->findOrFail($id), $reason))]);
    }

    public function closings(Request $request): JsonResponse
    {
        $user = StudentFeesController::user($request);
        $open = Gate::allows('fees.collect') ? $this->closings->open($user) : collect();
        $mine = CashClosing::query()->where('collector_id', (string) $user->getKey())->orderBy('created_at', 'desc')->limit(30)->get();
        $waiting = CashClosing::query()->where('status', 'submitted')->orderBy('created_at')->get()->filter(fn ($c) => $this->closings->mayConfirm($user, $c))->values();

        $totals = [];
        foreach ($open as $p) {
            $totals[$p->mode] = ($totals[$p->mode] ?? 0) + $p->amount_paise;
        }

        return response()->json(['data' => [
            'open' => ['count' => $open->count(), 'totals' => (object) $totals, 'total_paise' => array_sum($totals), 'payments' => $open->map(fn ($p) => self::payment($p))->values()],
            'mine' => $mine->map(fn ($c) => self::closing($c))->values(),
            'to_confirm' => $waiting->map(fn ($c) => self::closing($c))->values(),
        ]]);
    }

    public function close(Request $request): JsonResponse
    {
        Gate::authorize('fees.collect');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? null;

        return response()->json(['data' => self::closing($this->closings->close(StudentFeesController::user($request), $note))], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['counted_cash' => ['required', 'string', 'max:15'], 'note' => ['nullable', 'string', 'max:300']]);
        $closing = CashClosing::query()->findOrFail($id);

        return response()->json(['data' => self::closing($this->closings->confirm(StudentFeesController::user($request), $closing, Money::parse($input['counted_cash'], 'counted_cash'), $input['note'] ?? null))]);
    }

    public function showClosing(Request $request, string $id): JsonResponse
    {
        $user = StudentFeesController::user($request);
        $closing = CashClosing::query()->findOrFail($id);
        if (($closing->collector['id'] ?? null) !== (string) $user->getKey() && ! Gate::allows('fees.closing-confirm')) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => self::closing($closing) + [
            'payments' => Payment::query()->whereIn('_id', $closing->payment_ids)->get()->map(fn ($p) => self::payment($p))->values(),
            'can' => ['confirm' => $this->closings->mayConfirm($user, $closing) && $closing->status === 'submitted'],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $p): array
    {
        return [
            'id' => (string) $p->getKey(), 'student_id' => $p->student_id, 'mode' => $p->mode, 'amount_paise' => $p->amount_paise, 'reference' => $p->reference, 'bank' => $p->bank,
            'status' => $p->status, 'receipt_id' => $p->receipt_id, 'receipt_no' => $p->receipt_no, 'received_on' => $p->received_on, 'allocations' => $p->allocations,
            'collector' => $p->collector, 'outcome' => $p->outcome,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function closing(CashClosing $c): array
    {
        return [
            'id' => (string) $c->getKey(), 'collector' => ['id' => $c->collector['id'] ?? null, 'name' => $c->collector['name'] ?? null], 'date' => $c->date,
            'totals' => (object) $c->totals, 'total_paise' => $c->total_paise, 'cash_paise' => $c->cash_paise, 'counted_cash_paise' => $c->counted_cash_paise,
            'count' => count($c->payment_ids), 'status' => $c->status, 'confirmed_by' => $c->confirmed_by, 'confirmed_at' => $c->confirmed_at, 'note' => $c->note,
        ];
    }
}
