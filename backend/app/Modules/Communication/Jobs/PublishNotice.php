<?php

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Domain\NoticeAudience;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Communication\Models\Notice;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Notifies a notice's audience on the queue: in the app with the title
 * (read after sign-in), by push and email with the generic text only.
 */
class PublishNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $noticeId,
    ) {}

    public function handle(TenantContext $context, NoticeAudience $audience, Notifier $notifier): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $context->run($tenant->id, function () use ($tenant, $audience, $notifier): void {
            $notice = Notice::query()->find($this->noticeId);

            if (! $notice instanceof Notice) {
                return;
            }

            $recipients = $audience->recipients($notice)->reject(fn ($u) => (string) $u->getKey() === ($notice->sent_by['id'] ?? null));
            $notifier->notify($tenant, $recipients, 'notice', $notice->title, null, '/notices/'.$notice->getKey(), ['notice_id' => (string) $notice->getKey()]);
        });
    }
}
