<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Auth\SchoolUserProvider;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Short, stable names in tokenable_type instead of PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'platform_admin' => PlatformAdmin::class,
        ]);

        Auth::provider('school-users', function (Application $app, array $config): SchoolUserProvider {
            return new SchoolUserProvider($app['hash'], $config['model']);
        });
    }
}
