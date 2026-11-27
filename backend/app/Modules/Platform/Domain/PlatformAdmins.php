<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Mail\ConsolePasswordLinkMail;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Platform admin accounts. Created only from the server's command line
 * (php artisan platform:create-admin), never by sign-up: the account starts
 * "invited" with no password, and its owner chooses one with a one-time
 * link (48 hours). Nobody else types or sees it. MFA enrolment follows at
 * the first console sign-in.
 */
class PlatformAdmins
{
    public function __construct(
        private readonly PlatformAuditRecorder $audit,
        private readonly ConsoleSessions $sessions,
    ) {}

    /**
     * @return array{admin: PlatformAdmin, link: string}
     */
    public function create(string $name, string $email): array
    {
        $email = mb_strtolower(trim($email));

        Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
        ])->after(function ($validator) use ($email): void {
            if (PlatformAdmin::query()->where('email', $email)->exists()) {
                $validator->errors()->add('email', 'A platform admin with this email already exists.');
            }
        })->validate();

        $admin = PlatformAdmin::create([
            'name' => trim($name),
            'email' => $email,
            'status' => PlatformAdmin::STATUS_INVITED,
        ]);

        $this->audit->record('platform_admin.created', null, entity: 'platform_admins', entityId: (string) $admin->getKey(), after: [
            'name' => $admin->name,
            'email' => $admin->email,
        ]);

        return ['admin' => $admin, 'link' => $this->sendLink($admin)];
    }

    /** A new one-time link; any earlier one stops working. */
    public function sendLink(PlatformAdmin $admin): string
    {
        $token = Str::random(64);
        $hours = (int) config('identity.platform.set_link_hours', 48);

        $admin->forceFill([
            'password_link_hash' => self::hash($token),
            'password_link_expires_at' => now()->addHours($hours),
        ])->save();

        $url = config('identity.frontend_url').'/console/set-password?token='.$token;
        Mail::to($admin->email)->send(new ConsolePasswordLinkMail($url, $hours));

        $this->audit->record('platform_admin.password_link_sent', null, entity: 'platform_admins', entityId: (string) $admin->getKey());

        return $url;
    }

    public function setPassword(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): PlatformAdmin
    {
        $admin = PlatformAdmin::query()->where('password_link_hash', self::hash($token))->first();

        if (! $admin instanceof PlatformAdmin || $admin->password_link_expires_at === null || $admin->password_link_expires_at->isPast()
            || $admin->status === PlatformAdmin::STATUS_INACTIVE) {
            throw new ApiException('password_link_invalid', 'This link has expired or has already been used. Ask for a new one.', 422);
        }

        PasswordPolicy::assertNotPersonal($password, $admin->personalWords());

        $admin->forceFill([
            'password' => $password,
            'password_link_hash' => null,
            'password_link_expires_at' => null,
            'status' => PlatformAdmin::STATUS_ACTIVE,
            'failed_sign_ins' => 0,
            'locked_until' => null,
        ])->save();

        $ended = $this->sessions->revokeAllFor($admin, 'password_set');
        $this->audit->record('platform_admin.password_set', $admin, entity: 'platform_admins', entityId: (string) $admin->getKey(), meta: ['sessions_ended' => $ended]);

        return $admin;
    }

    private static function hash(#[SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
