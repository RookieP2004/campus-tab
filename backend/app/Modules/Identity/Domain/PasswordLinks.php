<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Mail\PasswordChangedMail;
use App\Modules\Identity\Mail\PasswordLinkMail;
use App\Modules\Identity\Models\PasswordToken;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Passwords are never typed in or seen by anyone but their owner.
 *
 *  - Set (new account, status "invited"): a one-time link by email, or by
 *    SMS through the driver interface; 48 hours. With no email or phone the
 *    Principal or Chief gets a one-time setup code instead, shown once.
 *  - Reset (forgot password, or sent by the Principal or Chief to someone
 *    below them): a one-time link; 30 minutes. Same reply whether or not an
 *    account matches; limits per account and per IP.
 *  - A newer link or code cancels any older unused one. Only a SHA-256 hash
 *    is stored. The audit log records the event, never the link or code.
 *  - Using one sets the password (not the person's login or name, not a
 *    common one), activates an invited account, unlocks it, ends every
 *    session and token of that user, keeps MFA as it is, and tells the user
 *    their password was changed.
 */
class PasswordLinks
{
    public function __construct(
        private readonly SmsSender $sms,
        private readonly SchoolUserLocator $locator,
        private readonly TenantContext $context,
        private readonly SessionIssuer $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Sends a set link (invited account) or a reset link (active account).
     * Runs inside the school.
     *
     * channel is email, sms or code; the code is returned only for "code" and
     * shown once. Null when the person cannot be reached and nobody asked.
     *
     * @return array{channel: string, to: string|null, code: string|null, expires_at: string}|null
     */
    public function send(Tenant $tenant, User $user, ?User $by = null): ?array
    {
        $purpose = $user->isInvited() ? PasswordToken::PURPOSE_SET : PasswordToken::PURPOSE_RESET;
        $minutes = $purpose === PasswordToken::PURPOSE_SET
            ? (int) config('identity.passwords.set_link_hours', 48) * 60
            : (int) config('identity.passwords.reset_link_minutes', 30);

        $hasEmail = is_string($user->email) && $user->email !== '';
        $hasPhone = is_string($user->phone) && $user->phone !== '';

        // No way to reach the person, and nobody at the desk to hand a code to.
        if (! $hasEmail && ! $hasPhone && $by === null) {
            return null;
        }

        $this->cancelOpenLinks($user);

        $useCode = ! $hasEmail && ! $hasPhone;
        $secret = $useCode ? self::newSetupCode() : Str::random(64);
        $expiresAt = now()->addMinutes($minutes);

        PasswordToken::create([
            'user_id' => (string) $user->getKey(),
            'purpose' => $purpose,
            'token_hash' => PasswordToken::hash($useCode ? self::normaliseCode($secret) : $secret),
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_by' => $by === null ? null : ['id' => (string) $by->getKey(), 'name' => $by->name],
        ]);

        $validFor = $minutes >= 120 ? intdiv($minutes, 60).' hours' : $minutes.' minutes';
        $url = config('identity.frontend_url').'/password/'.$purpose.'?'.http_build_query(['school' => $tenant->code, 'token' => $secret]);

        $result = match (true) {
            $useCode => ['channel' => 'code', 'to' => null, 'code' => $secret],
            $hasEmail => $this->mail((string) $user->email, new PasswordLinkMail($url, $tenant->name, $purpose, $validFor)),
            default => $this->text((string) $user->phone, ($purpose === PasswordToken::PURPOSE_SET ? 'Set' : 'Reset')." your Campus TAB password for {$tenant->name}: {$url} (works once, for {$validFor})."),
        };

        $this->audit->record('password.'.$purpose.'_link_sent', 'security', 'users', (string) $user->getKey(), meta: [
            'channel' => $result['channel'],
            'valid_minutes' => $minutes,
        ], actor: $by ?? $user);

        return array_merge($result, ['expires_at' => $expiresAt->toIso8601String()]);
    }

    /** Forgot password: always the same reply; nothing tells whether the account exists. */
    public function forgot(Request $request, string $schoolCode, string $login): void
    {
        $ipKey = 'password-forgot-ip:'.hash('sha256', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('identity.passwords.reset_requests_per_ip_per_15_minutes', 100))) {
            throw new ApiException('too_many_attempts', 'Too many requests from this network. Wait a few minutes and try again.', 429);
        }

        RateLimiter::hit($ipKey, 900);

        $found = $this->locator->find($schoolCode, $login);

        // Parents sign in by code and have no password to reset.
        if ($found === null || $found[1]->isParentOnly()) {
            return;
        }

        [$tenant, $user] = $found;
        $accountKey = 'password-forgot:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($accountKey, (int) config('identity.passwords.reset_requests_per_hour', 3))) {
            return;
        }

        RateLimiter::hit($accountKey, 3600);

        $this->context->run($tenant->id, fn () => $this->send($tenant, $user));
    }

    /** Uses a link or setup code: sets the password and ends every session. */
    public function consume(string $schoolCode, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $password): User
    {
        $invalid = new ApiException('password_link_invalid', 'This link or code has expired or was already used. Ask for a new one.', 422);
        $tenant = Tenant::findActiveByCode($schoolCode) ?? throw $invalid;

        return $this->context->run($tenant->id, function () use ($tenant, $secret, $password, $invalid): User {
            /** @var PasswordToken|null $token */
            $token = PasswordToken::query()
                ->whereIn('token_hash', array_values(array_unique([PasswordToken::hash($secret), PasswordToken::hash(self::normaliseCode($secret))])))
                ->whereNull('used_at')
                ->first();

            if ($token === null || $token->expires_at->isPast()) {
                throw $invalid;
            }

            $user = User::query()->find($token->user_id);

            if (! $user instanceof User || ! ($user->isActive() || $user->isInvited())) {
                throw $invalid;
            }

            PasswordPolicy::assertNotPersonal($password, $user->personalWords());

            $token->forceFill(['used_at' => now()])->save();
            $user->forceFill([
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'failed_sign_ins' => 0,
                'locked_until' => null,
            ])->save();

            $this->afterChange($tenant, $user, 'password.'.$token->purpose);

            return $user;
        });
    }

    /**
     * After any set, change or reset: every session and token of the user
     * ends, MFA stays as it is, and the user is told. Runs inside the school.
     */
    public function afterChange(Tenant $tenant, User $user, string $action): int
    {
        $ended = $this->sessions->revokeAllFor($user, 'password_changed');
        $this->notifyChanged($tenant, $user);
        $this->audit->record($action, 'security', 'users', (string) $user->getKey(), meta: ['sessions_ended' => $ended], actor: $user);

        return $ended;
    }

    private function notifyChanged(Tenant $tenant, User $user): void
    {
        if (is_string($user->email) && $user->email !== '') {
            Mail::to($user->email)->send(new PasswordChangedMail($tenant->name));
        } elseif (is_string($user->phone) && $user->phone !== '') {
            $this->sms->send($user->phone, "Your Campus TAB password for {$tenant->name} was just changed. If this was not you, tell your school office at once.");
        }
    }

    private function cancelOpenLinks(User $user): void
    {
        PasswordToken::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }

    /**
     * @return array{channel: string, to: string, code: null}
     */
    private function mail(string $email, PasswordLinkMail $mail): array
    {
        Mail::to($email)->send($mail);

        return ['channel' => 'email', 'to' => self::maskEmail($email), 'code' => null];
    }

    /**
     * @return array{channel: string, to: string, code: null}
     */
    private function text(string $phone, string $message): array
    {
        $this->sms->send($phone, $message);

        return ['channel' => 'sms', 'to' => self::maskPhone($phone), 'code' => null];
    }

    /** Twelve characters without look-alikes, shown as XXXX-XXXX-XXXX. */
    private static function newSetupCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($code, 4));
    }

    private static function normaliseCode(#[SensitiveParameter] string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($code)));
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).str_repeat('*', max(mb_strlen($name) - 1, 3)).'@'.$domain;
    }

    public static function maskPhone(string $phone): string
    {
        return str_repeat('*', max(strlen($phone) - 4, 0)).substr($phone, -4);
    }
}
