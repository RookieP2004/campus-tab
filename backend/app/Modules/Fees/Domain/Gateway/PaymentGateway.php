<?php

namespace App\Modules\Fees\Domain\Gateway;

use App\Modules\Fees\Models\GatewayAccount;

/**
 * One payment gateway. The school's own account (keys) is passed in; a
 * driver never holds keys of its own.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Creates the order at the gateway. The amount comes from our dues.
     *
     * @return array{order_id: string}
     */
    public function createOrder(GatewayAccount $account, int $amountPaise, string $currency, string $reference): array;

    /** Checks the webhook's signature over the RAW request body. */
    public function verifySignature(GatewayAccount $account, string $rawBody, string $signature): bool;

    /**
     * Reads a webhook body (only after the signature was checked, except the
     * order id, which finds the school; see decision 0015).
     *
     * @return array{event: string, order_id: string|null, payment_id: string|null, amount_paise: int|null, currency: string|null, captured: bool}
     */
    public function parse(string $rawBody): array;

    /** The order id in a webhook body, read before the signature can be checked. */
    public function orderIdOf(string $rawBody): ?string;

    /**
     * The payments the gateway settled for a day, for reconciliation.
     *
     * @return list<array{payment_id: string, order_id: string|null, amount_paise: int}>
     */
    public function settlements(GatewayAccount $account, string $date): array;

    /** @return array<string, mixed> what the browser needs to open checkout (no secrets) */
    public function checkoutFields(GatewayAccount $account): array;
}
