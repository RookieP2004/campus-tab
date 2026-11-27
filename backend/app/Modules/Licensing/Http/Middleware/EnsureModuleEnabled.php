<?php

namespace App\Modules\Licensing\Http\Middleware;

use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `module:<name>`, e.g. ->middleware('module:fees'). Runs after
 * `licence`. A module works only when the licence includes it AND the
 * school's config has it switched on; otherwise 403 module_disabled.
 */
class EnsureModuleEnabled
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SchoolConfigService $configs,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! in_array($module, config('licensing.modules'), true)) {
            throw new LogicException("Unknown module [{$module}] in module middleware.");
        }

        $licence = $request->attributes->get('licence') ?? Licence::currentFor($this->context->require());
        $licensed = $licence instanceof Licence && in_array($module, $licence->licensedModules(), true);
        $switchedOn = (bool) ($this->configs->current()->body['modules'][$module] ?? false);

        if (! $licensed || ! $switchedOn) {
            throw new ApiException('module_disabled', 'This part of Campus TAB is not switched on for your school.', 403, ['module' => $module]);
        }

        return $next($request);
    }
}
