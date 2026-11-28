<?php

namespace App\Modules\Fees\Jobs;

use App\Modules\Fees\Domain\Gateway\FakeGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The fake gateway's webhook (local and testing only): POSTs the signed
 * body to our own webhook route over HTTP, from the queue worker, exactly
 * as the real gateway would. The URL is the API as the worker reaches it
 * (FAKE_GATEWAY_WEBHOOK_URL; http://api:8000/... in Docker).
 */
class SendFakeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 2;

    public function __construct(public readonly string $body, public readonly string $signature) {}

    public function handle(): void
    {
        if (! FakeGateway::allowed()) {
            throw new LogicException('The fake payment gateway is only for local development and tests.');
        }
        Http::withBody($this->body, 'application/json')
            ->withHeaders(['X-Razorpay-Signature' => $this->signature])
            ->timeout(15)
            ->post((string) config('fees.gateway.fake_webhook_url'))
            ->throw();
    }
}
