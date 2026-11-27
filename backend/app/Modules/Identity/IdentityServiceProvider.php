<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Auth\SchoolUserProvider;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\SchoolRole;
use App\Modules\Identity\Models\SupportGrant;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\RolePolicy;
use App\Modules\Identity\Policies\SupportGrantPolicy;
use App\Modules\Identity\Policies\UserPolicy;
use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request (and per queued job): roles are cached only that long.
        $this->app->scoped(RoleRegistry::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Tokens of school users also stop after max_hours in total, even if
        // the device session check were skipped. Idle limits are enforced per
        // device session (TrackDeviceSession), by the user's level.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $created = $token->getAttribute('created_at');
            $hours = (int) config('identity.sessions.max_hours', 8);

            return $isValid && $created instanceof DateTimeInterface && $created->getTimestamp() > now()->subHours($hours)->getTimestamp();
        });

        // The cookie session's own lifetime is the longer idle limit; the
        // shorter one for MFA roles is enforced per device session.
        config(['session.lifetime' => (int) config('identity.sessions.idle_minutes', 30)]);

        // Short, stable names in tokenable_type instead of PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'platform_admin' => PlatformAdmin::class,
        ]);

        Auth::provider('school-users', function (Application $app, array $config): SchoolUserProvider {
            return new SchoolUserProvider($app['hash'], $config['model']);
        });

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(SchoolRole::class, RolePolicy::class);
        Gate::policy(SupportGrant::class, SupportGrantPolicy::class);
    }
}
