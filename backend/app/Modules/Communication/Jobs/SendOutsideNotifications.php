<?php

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Domain\Notifier;
use App\Modules\Communication\Mail\NotificationMail;
use App\Modules\Communication\Models\PushDevice;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Push\PushSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Push and email for a batch of in-app notifications, on the queue, with the
 * generic text only. Each person's preferences decide: push on by default,
 * email off by default, and any type can be muted.
 */
class SendOutsideNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<string>  $userIds
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array $userIds,
        public readonly string $type,
    ) {}

    public function handle(TenantContext $context, PushSender $push): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $text = Notifier::genericText($this->type, $tenant->name);

        $context->run($tenant->id, function () use ($push, $text, $tenant): void {
            foreach (User::query()->whereIn('_id', $this->userIds)->get() as $user) {
                $prefs = Notifier::preferences($user);

                if (in_array($this->type, $prefs['muted'], true)) {
                    continue;
                }

                if ($prefs['push']) {
                    $devices = PushDevice::query()->where('user_id', (string) $user->getKey())->get();

                    if ($devices->isNotEmpty()) {
                        $gone = $push->send($devices->map(fn (PushDevice $d) => ['token' => $d->token, 'platform' => $d->platform])->values()->all(), 'Campus TAB', $text);
                        // Uninstalled apps and expired tokens are forgotten.
                        foreach ($gone as $token) {
                            PushDevice::query()->where('token_hash', PushDevice::hashToken($token))->delete();
                        }
                    }
                }

                if ($prefs['email'] && is_string($user->email) && $user->email !== '') {
                    Mail::to($user->email)->send(new NotificationMail($text, $tenant->name));
                }
            }
        });
    }
}
