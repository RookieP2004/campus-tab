<?php

namespace App\Modules\Fees\Domain\Gateway;

use App\Modules\Fees\Models\GatewayAccount;
use App\Modules\Fees\Models\GatewayOrder;
use Illuminate\Support\Str;
use LogicException;

/**
 * A local stand-in for a gateway, so online payments can be tried with no
 * internet and no tunnel. ONLY when APP_ENV is local or testing: the
 * constructor refuses anywhere else, and the gateway settings refuse to
 * save it.
 *
 * Checkout is a dialog in our own app; "Pay" builds a webhook body in
 * Razorpay's shape and signs it with the school's webhook secret exactly as
 * Razorpay would (HMAC-SHA256 of the raw body), then sends it to the same
 * webhook route the real gateway uses. Settlements are every payment it
 * made, as the real report would list them.
 */
class FakeGateway implements PaymentGateway
{
    public function __construct()
    {
        if (! self::allowed()) {
            throw new LogicException('The fake payment gateway is only for local development and tests.');
        }
    }

    public static function allowed(): bool
    {
        return app()->environment((array) config('fees.gateway.fake_environments', ['local', 'testing']));
    }

    public function name(): string
    {
        return 'fake';
    }

    public function createOrder(GatewayAccount $account, int $amountPaise, string $currency, string $reference): array
    {
        return ['order_id' => 'order_fake'.Str::random(14)];
    }

    public function verifySignature(GatewayAccount $account, string $rawBody, string $signature): bool
    {
        return $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $account->webhook_secret), $signature);
    }

    public function parse(string $rawBody): array
    {
        return (new RazorpayGateway)->parse($rawBody);
    }

    public function orderIdOf(string $rawBody): ?string
    {
        $body = json_decode($rawBody, true);
        $id = is_array($body) ? ($body['payload']['payment']['entity']['order_id'] ?? null) : null;

        return is_string($id) && preg_match('/^order_fake[A-Za-z0-9]{14}$/', $id) === 1 ? $id : null;
    }

    /**
     * The webhook the fake checkout sends: body and signature.
     *
     * @return array{body: string, signature: string, payment_id: string}
     */
    public function simulate(GatewayAccount $account, GatewayOrder $order, bool $success): array
    {
        $paymentId = 'pay_fake'.Str::random(14);
        $body = (string) json_encode([
            'entity' => 'event',
            'event' => $success ? 'payment.captured' : 'payment.failed',
            'payload' => ['payment' => ['entity' => [
                'id' => $paymentId, 'order_id' => $order->order_id, 'amount' => $order->amount_paise, 'currency' => $order->currency,
                'status' => $success ? 'captured' : 'failed', 'method' => 'upi',
            ]]],
            'created_at' => now()->getTimestamp(),
        ], JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'signature' => hash_hmac('sha256', $body, $account->webhook_secret), 'payment_id' => $paymentId];
    }

    public function settlements(GatewayAccount $account, string $date): array
    {
        return GatewayOrder::query()->where('provider', 'fake')->where('status', 'paid')->get()
            ->filter(fn (GatewayOrder $o) => $o->updated_at?->timezone('Asia/Kolkata')->toDateString() === $date)
            ->map(fn (GatewayOrder $o) => ['payment_id' => (string) $o->gateway_payment_id, 'order_id' => $o->order_id, 'amount_paise' => $o->amount_paise])
            ->values()->all();
    }

    /** @return array<string, mixed> what the browser needs to open checkout (no secrets) */
    public function checkoutFields(GatewayAccount $account): array
    {
        return ['key_id' => $account->key_id, 'fake' => true];
    }
}
