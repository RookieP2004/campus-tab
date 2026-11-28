<?php

namespace App\Modules\Fees\Domain\Gateway;

use App\Modules\Fees\Models\GatewayAccount;
use App\Support\Http\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay (test mode during the build: keys starting rzp_test_).
 *
 *  - Orders: POST /v1/orders with the school's key id and secret.
 *  - Checkout runs in Razorpay's own page (card details never reach us).
 *  - Webhooks (payment.captured, order.paid): X-Razorpay-Signature is the
 *    HMAC-SHA256 of the raw body with the school's webhook secret.
 *  - Settlements: GET /v1/settlements/recon/combined for one day.
 */
class RazorpayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'razorpay';
    }

    public function createOrder(GatewayAccount $account, int $amountPaise, string $currency, string $reference): array
    {
        try {
            $response = Http::withBasicAuth($account->key_id, $account->key_secret)->timeout((int) config('fees.gateway.timeout_seconds', 15))
                ->post(rtrim((string) config('fees.gateway.razorpay_api'), '/').'/orders', ['amount' => $amountPaise, 'currency' => $currency, 'receipt' => $reference]);
        } catch (ConnectionException) {
            throw new ApiException('gateway_unreachable', 'The payment gateway could not be reached. Try again in a minute.', 502);
        }
        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new ApiException('gateway_refused', 'The payment gateway did not accept the order. The school office can check the gateway settings.', 502);
        }

        return ['order_id' => (string) $response->json('id')];
    }

    public function verifySignature(GatewayAccount $account, string $rawBody, string $signature): bool
    {
        return $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $account->webhook_secret), $signature);
    }

    public function parse(string $rawBody): array
    {
        $body = json_decode($rawBody, true);
        $payment = is_array($body) ? ($body['payload']['payment']['entity'] ?? []) : [];
        $event = is_array($body) ? (string) ($body['event'] ?? '') : '';

        return [
            'event' => $event,
            'order_id' => isset($payment['order_id']) ? (string) $payment['order_id'] : $this->orderIdOf($rawBody),
            'payment_id' => isset($payment['id']) ? (string) $payment['id'] : null,
            'amount_paise' => isset($payment['amount']) && is_int($payment['amount']) ? $payment['amount'] : null,
            'currency' => isset($payment['currency']) ? (string) $payment['currency'] : null,
            'captured' => in_array($event, ['payment.captured', 'order.paid'], true) && ($payment['status'] ?? 'captured') === 'captured',
        ];
    }

    public function orderIdOf(string $rawBody): ?string
    {
        $body = json_decode($rawBody, true);
        $id = is_array($body) ? ($body['payload']['payment']['entity']['order_id'] ?? $body['payload']['order']['entity']['id'] ?? null) : null;

        return is_string($id) && preg_match('/^order_[A-Za-z0-9]{6,40}$/', $id) === 1 ? $id : null;
    }

    public function settlements(GatewayAccount $account, string $date): array
    {
        [$y, $m, $d] = explode('-', $date);
        $response = Http::withBasicAuth($account->key_id, $account->key_secret)->timeout((int) config('fees.gateway.timeout_seconds', 15))
            ->get(rtrim((string) config('fees.gateway.razorpay_api'), '/').'/settlements/recon/combined', ['year' => (int) $y, 'month' => (int) $m, 'day' => (int) $d, 'count' => 1000]);
        if (! $response->successful()) {
            throw new ApiException('gateway_refused', 'The settlement report could not be read from the gateway.', 502);
        }
        $out = [];
        foreach ((array) $response->json('items', []) as $item) {
            if (($item['type'] ?? '') === 'payment' && isset($item['entity_id'])) {
                $out[] = ['payment_id' => (string) $item['entity_id'], 'order_id' => isset($item['order_id']) ? (string) $item['order_id'] : null, 'amount_paise' => (int) ($item['amount'] ?? 0)];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> what the browser needs to open checkout (no secrets) */
    public function checkoutFields(GatewayAccount $account): array
    {
        return ['key_id' => $account->key_id, 'script' => 'https://checkout.razorpay.com/v1/checkout.js'];
    }
}
