<?php

namespace App\Modules\Fees\Domain\Gateway;

use App\Support\Http\ApiException;

/** Picks the driver by name: razorpay, or fake (local and testing only). */
class Gateways
{
    public function driver(string $name): PaymentGateway
    {
        return match ($name) {
            'razorpay' => new RazorpayGateway,
            'fake' => FakeGateway::allowed() ? new FakeGateway : throw new ApiException('gateway_unknown', 'That payment gateway is not available here.', 404),
            default => throw new ApiException('gateway_unknown', 'That payment gateway is not available here.', 404),
        };
    }

    /** @return list<string> the drivers this server may use */
    public function available(): array
    {
        return FakeGateway::allowed() ? ['razorpay', 'fake'] : ['razorpay'];
    }
}
