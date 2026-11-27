<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\SupportGrant;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Support access ("The PowerSchool lesson": no standing access).
 *
 *  - The Chief creates a grant for one platform admin: areas, read-only by
 *    default, a reason and a fixed end time (15 minutes to 72 hours ahead).
 *    It cannot be extended; the Chief can revoke it early.
 *  - The agent opens a support session for that school only while the grant
 *    is active. The session's token names the school and the grant and ends
 *    with the grant; every request checks the grant again.
 *  - Everything done under it is in the school's audit log with the agent
 *    and the grant id, and the school can list it per grant.
 */
class SupportGrants
{
    public const TOKEN_ABILITY = 'support';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{agent_email: string, scope: list<string>, read_only?: bool|null, reason: string, ends_at: string}  $input
     */
    public function create(User $chief, array $input): SupportGrant
    {
        $agent = PlatformAdmin::query()->where('email', mb_strtolower($input['agent_email']))->where('status', 'active')->first();

        if (! $agent instanceof PlatformAdmin) {
            throw new ApiException('support_agent_not_found', 'There is no Campus TAB support account with this email.', 422);
        }

        $ends = CarbonImmutable::parse($input['ends_at']);
        $min = now()->addMinutes((int) config('identity.support.min_minutes', 15));
        $max = now()->addHours((int) config('identity.support.max_hours', 72));

        if ($ends->lessThan($min) || $ends->greaterThan($max)) {
            throw new ApiException('support_grant_window', 'Support access must end between 15 minutes and 72 hours from now.', 422, [
                'earliest' => $min->toIso8601String(),
                'latest' => $max->toIso8601String(),
            ]);
        }

        return SupportGrant::create([
            'granted_by' => ['id' => (string) $chief->getKey(), 'name' => $chief->name],
            'agent_id' => (string) $agent->getKey(),
            'agent_email' => $agent->email,
            'agent_name' => $agent->name,
            'scope' => array_values(array_unique($input['scope'])),
            'read_only' => $input['read_only'] ?? true,
            'reason' => $input['reason'],
            'starts_at' => now(),
            'expires_at' => $ends,
            'revoked_at' => null,
        ]);
    }

    public function revoke(User $chief, SupportGrant $grant): SupportGrant
    {
        if ($grant->revoked_at === null) {
            $grant->forceFill([
                'revoked_at' => now(),
                'revoked_by' => ['id' => (string) $chief->getKey(), 'name' => $chief->name],
            ])->save();
        }

        // Open support sessions end at once.
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', 'platform_admin')
            ->where('tokenable_id', $grant->agent_id)
            ->get();

        foreach ($tokens as $token) {
            if (in_array('support-grant:'.$grant->getKey(), (array) $token->abilities, true)) {
                $token->delete();
            }
        }

        return $grant;
    }

    /**
     * Platform side: open a support session for a school. Refused unless the
     * agent has an active grant there.
     *
     * @return array{token: string, expires_at: string, grant: SupportGrant, school: array{code: string, name: string}}
     */
    public function openSession(PlatformAdmin $agent, string $schoolCode): array
    {
        if (! (bool) ($agent->mfa['enabled'] ?? false)) {
            throw new ApiException('mfa_enrolment_required', 'Platform accounts must use two-step sign-in before opening support sessions.', 403);
        }

        $tenant = Tenant::findActiveByCode($schoolCode)
            ?? throw self::noGrant();

        return $this->context->run($tenant->id, function () use ($agent, $tenant): array {
            $grant = $this->activeGrantFor($agent);

            if ($grant === null) {
                throw self::noGrant();
            }

            $token = $agent->createToken('support:'.$tenant->code, [
                self::TOKEN_ABILITY,
                'support-tenant:'.$tenant->id,
                'support-grant:'.$grant->getKey(),
            ], $grant->expires_at);

            $this->audit->record('support.session_opened', 'security', 'support_grants', (string) $grant->getKey(), meta: [
                'agent_id' => (string) $agent->getKey(),
                'agent_name' => $agent->name,
            ], actor: SupportAgentUser::for($agent, $grant));

            return [
                'token' => $token->plainTextToken,
                'expires_at' => $grant->expires_at->toIso8601String(),
                'grant' => $grant,
                'school' => ['code' => $tenant->code, 'name' => $tenant->name],
            ];
        });
    }

    /**
     * School side, every request: the grant a support token names, if it is
     * still active for this agent. Runs inside the school.
     */
    public function grantForToken(PlatformAdmin $agent, string $grantId): ?SupportGrant
    {
        $grant = SupportGrant::query()->find($grantId);

        return $grant instanceof SupportGrant && $grant->isActive() && $grant->agent_id === (string) $agent->getKey() ? $grant : null;
    }

    private function activeGrantFor(PlatformAdmin $agent): ?SupportGrant
    {
        /** @var SupportGrant|null $grant */
        $grant = SupportGrant::query()
            ->where('agent_id', (string) $agent->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at', 'desc')
            ->first();

        return $grant;
    }

    public static function noGrant(): ApiException
    {
        return new ApiException('support_grant_required', 'There is no active support access for you at this school. The Chief Admin must grant it first.', 403);
    }
}
