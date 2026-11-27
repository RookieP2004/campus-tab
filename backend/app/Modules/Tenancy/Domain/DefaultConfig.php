<?php

namespace App\Modules\Tenancy\Domain;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The first config version a school gets when it is activated. Values follow
 * the example in docs/BLUEPRINT.md; the setup wizard (Phase 2) changes them.
 */
class DefaultConfig
{
    /**
     * @param  list<string>  $licensedModules
     * @return array<string, mixed>
     */
    public static function for(Tenant $tenant, array $licensedModules): array
    {
        $modules = [];

        foreach (config('licensing.modules') as $module) {
            $modules[$module] = in_array($module, $licensedModules, true);
        }

        return [
            'school' => [
                'name' => $tenant->name,
                'short_name' => Str::limit(Str::before($tenant->code, '-'), 20, ''),
                'logo_url' => null,
                'board' => 'CBSE',
                'affiliation_no' => null,
            ],
            'branding' => ['accent' => '#0F766E'],
            'locale' => [
                'default_language' => 'en',
                'featured_languages' => ['en', 'hi'],
                'timezone' => 'Asia/Kolkata',
                'date_format' => 'DD-MM-YYYY',
            ],
            'academic' => ['year_start_month' => 4, 'terms' => ['Term 1', 'Term 2']],
            'modules' => $modules,
            'sign_in' => ['methods' => ['password', 'parent_otp']],
            'policies' => [
                'attendance' => ['student_mode' => 'daily', 'edit_window_hours' => 4, 'min_percent_alert' => 75],
                'leave' => ['chain' => ['clerk', 'principal'], 'clerk_can_reject' => false, 'remind_after_hours' => 24, 'escalate_after_hours' => 48],
                'marks' => ['auto_publish_class_tests' => true],
                'fees' => ['gateway' => 'none', 'waiver_by' => 'principal', 'receipt_prefix' => null],
            ],
        ];
    }
}
