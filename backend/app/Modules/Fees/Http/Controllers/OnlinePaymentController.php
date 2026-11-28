<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Fees\Domain\Cheques;
use App\Modules\Fees\Domain\Gateway\FakeGateway;
use App\Modules\Fees\Domain\Gateway\Gateways;
use App\Modules\Fees\Domain\OnlinePayments;
use App\Modules\Fees\Models\FeeReconciliation;
use App\Modules\Fees\Models\GatewayAccount;
use App\Modules\Fees\Models\GatewayOrder;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Online payments.
 *
 *   GET  /fees/gateway                 the school's gateway (keys masked; secrets never sent)
 *   PUT  /fees/gateway                 the Chief enters or changes the keys (stored encrypted)
 *   POST /fees/checkout { student_id, demand_ids[] }   the server sets the amount from the dues
 *   GET  /fees/checkout/{id}           its status (the screen waits for the webhook)
 *   POST /fees/checkout/{id}/fake { outcome }          local/testing only: simulate checkout
 *   POST /webhooks/payments/{gateway}  the gateway's callback (no sign-in; decision 0015)
 *   GET  /fees/reconciliations         the nightly checks (the office)
 */
class OnlinePaymentController
{
    public function __construct(private readonly OnlinePayments $online, private readonly Gateways $gateways) {}

    public function gateway(): JsonResponse
    {
        Gate::authorize('fees.gateway-view');

        return response()->json(['data' => self::account(GatewayAccount::query()->first()) + ['drivers' => $this->gateways->available(), 'webhook_path' => '/api/v1/webhooks/payments/{gateway}']]);
    }

    public function updateGateway(Request $request): JsonResponse
    {
        Gate::authorize('fees.gateway-edit');
        $user = StudentFeesController::user($request);
        $existing = GatewayAccount::query()->first();
        $input = $request->validate([
            'provider' => ['required', Rule::in($this->gateways->available())],
            'key_id' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_]+$/'],
            'key_secret' => [$existing ? 'nullable' : 'required', 'string', 'min:8', 'max:120'],
            'webhook_secret' => [$existing ? 'nullable' : 'required', 'string', 'min:8', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'off'])],
        ]);
        if ($input['provider'] === 'razorpay' && ! str_starts_with($input['key_id'], 'rzp_test_')) {
            throw new ApiException('gateway_test_mode_only', 'During the build only Razorpay test-mode keys (starting rzp_test_) are accepted.', 422);
        }
        if ($input['provider'] === 'fake' && ! FakeGateway::allowed()) {
            throw new ApiException('gateway_unknown', 'That payment gateway is not available here.', 422);
        }
        $account = $existing ?? new GatewayAccount;
        $account->fill(array_filter([
            'provider' => $input['provider'], 'key_id' => $input['key_id'], 'status' => $input['status'] ?? 'active',
            'key_secret' => $input['key_secret'] ?? null, 'webhook_secret' => $input['webhook_secret'] ?? null,
            'updated_by' => Cheques::who($user),
        ], fn ($v) => $v !== null && $v !== ''))->save();

        return response()->json(['data' => self::account($account)]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = StudentFeesController::user($request);
        $input = $request->validate(['student_id' => ['required', 'string', 'max:30'], 'demand_ids' => ['sometimes', 'array', 'max:24'], 'demand_ids.*' => ['string', 'max:30']]);
        $student = Student::query()->find($input['student_id']);
        if (! $student instanceof Student || ! Gate::allows('fees.pay', $student)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => $this->online->checkout($user, $student, array_values($input['demand_ids'] ?? []))], 201);
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $user = StudentFeesController::user($request);
        $order = GatewayOrder::query()->find($id);
        if (! $order instanceof GatewayOrder || $order->created_by !== (string) $user->getKey()) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => ['id' => (string) $order->getKey(), 'status' => $order->status, 'receipt_no' => $order->receipt_no, 'amount_paise' => $order->amount_paise]]);
    }

    public function fake(Request $request, string $id): JsonResponse
    {
        $user = StudentFeesController::user($request);
        $outcome = $request->validate(['outcome' => ['required', Rule::in(['paid', 'failed'])]])['outcome'];
        $order = GatewayOrder::query()->find($id) ?? throw new ApiException('not_found', 'The record was not found.', 404);
        $this->online->simulate($user, $order, $outcome === 'paid');

        return response()->json(['data' => ['queued' => true]], 202);
    }

    /** The gateway's callback: the raw body as sent, never re-encoded. */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        [$status, $outcome] = $this->online->webhook($gateway, $request->getContent(), $signature);

        return response()->json(['data' => ['outcome' => $outcome]], $status);
    }

    public function reconciliations(): JsonResponse
    {
        Gate::authorize('fees.office');

        return response()->json(['data' => FeeReconciliation::query()->orderBy('date', 'desc')->limit(60)->get()->map(fn (FeeReconciliation $r) => [
            'id' => (string) $r->getKey(), 'date' => $r->date, 'provider' => $r->provider, 'matched' => $r->matched, 'mismatches' => $r->mismatches, 'status' => $r->status, 'error' => $r->error,
        ])->values()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function account(?GatewayAccount $a): array
    {
        if (! $a instanceof GatewayAccount) {
            return ['configured' => false, 'provider' => null, 'key_id' => null, 'status' => null, 'updated_by' => null, 'updated_at' => null];
        }

        return [
            'configured' => true, 'provider' => $a->provider, 'key_id' => Str::mask($a->key_id, '•', 8), 'status' => $a->status,
            'has_secret' => $a->key_secret !== '', 'has_webhook_secret' => $a->webhook_secret !== '',
            'updated_by' => $a->updated_by['name'] ?? null, 'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
