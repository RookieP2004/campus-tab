<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Platform\Domain\ChiefRecovery;
use App\Modules\Platform\Domain\SchoolConsole;
use App\Modules\Platform\Domain\SchoolStatsCollector;
use App\Modules\Platform\Models\SchoolClient;
use App\Modules\Platform\Models\SchoolPayment;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Schools and licences in the platform console.
 *
 *   GET   /platform/options                          plans and modules for the forms
 *   GET   /platform/schools                          every school with its licence
 *   POST  /platform/schools                          { code, name, email?, phone? }
 *   GET   /platform/schools/{code}
 *   POST  /platform/schools/{code}/licences          { plan, modules[], students, staff, valid_from?, valid_to? } -> key, shown once
 *   PATCH /platform/schools/{code}/licence           { plan?, modules?, students?, staff?, valid_to? }
 *   POST  /platform/schools/{code}/licence/renew     { valid_to }  (takes effect at once, both audit logs)
 *   POST  /platform/schools/{code}/licence/revoke    { confirm_code, reason }  (step-up)
 *   PUT   /platform/schools/{code}/client            our client record (contact, package, price, billing, paid until)
 *   POST  /platform/schools/{code}/payments          a payment entered by hand (append-only; corrections are negative)
 *   POST  /platform/schools/{code}/stats/refresh     work out the school's numbers now
 *   POST  /platform/schools/{code}/chief-recovery    (step-up) -> code sent to the registered contact
 *   POST  /platform/schools/{code}/chief-recovery/complete { code, reason } (step-up)
 */
class ConsoleSchoolController
{
    public function __construct(private readonly SchoolConsole $schools) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'plans' => config('licensing.plans'),
            'modules' => config('licensing.modules'),
            'grace_days' => (int) config('licensing.grace_days', 15),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $query = Tenant::query()->orderBy('code');

        if ($search !== '') {
            $pattern = '/'.preg_quote($search, '/').'/i';
            $query->where(fn ($q) => $q->where('code', 'regex', $pattern)->orWhere('name', 'regex', $pattern));
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (Tenant $t) => $this->schools->present($t))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $tenant = $this->schools->createSchool(ConsoleAuthController::admin($request), $input);

        return response()->json(['data' => $this->schools->present($tenant)], 201);
    }

    public function show(string $code): JsonResponse
    {
        return response()->json(['data' => $this->schools->details($this->tenant($code))]);
    }

    public function issueLicence(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'plan' => ['required', 'string', Rule::in(config('licensing.plans'))],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', Rule::in(config('licensing.modules'))],
            'students' => ['required', 'integer', 'min:1', 'max:100000'],
            'staff' => ['required', 'integer', 'min:1', 'max:10000'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $tenant = $this->tenant($code);
        $today = now('Asia/Kolkata');

        $result = $this->schools->issueLicence(ConsoleAuthController::admin($request), $tenant, [
            'plan' => $input['plan'],
            'modules' => array_values($input['modules']),
            'students' => (int) $input['students'],
            'staff' => (int) $input['staff'],
            'valid_from' => $input['valid_from'] ?? $today->toDateString(),
            'valid_to' => $input['valid_to'] ?? $today->copy()->addYear()->subDay()->toDateString(),
        ]);

        return response()->json(['data' => [
            // Shown once: only its hash is stored.
            'key' => $result['key'],
            'licence' => LicenceIssuer::summary($result['licence']),
            'activation_needed' => ! $tenant->isActive(),
        ]], 201);
    }

    public function amendLicence(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'plan' => ['sometimes', 'string', Rule::in(config('licensing.plans'))],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(config('licensing.modules'))],
            'students' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'staff' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'valid_to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if (isset($input['modules'])) {
            $input['modules'] = array_values($input['modules']);
        }

        $licence = $this->schools->amendLicence(ConsoleAuthController::admin($request), $this->tenant($code), $input);

        return response()->json(['data' => LicenceIssuer::summary($licence)]);
    }

    public function renewLicence(Request $request, string $code): JsonResponse
    {
        $input = $request->validate(['valid_to' => ['required', 'date_format:Y-m-d']]);
        $licence = $this->schools->renewLicence(ConsoleAuthController::admin($request), $this->tenant($code), $input['valid_to']);

        return response()->json(['data' => LicenceIssuer::summary($licence)]);
    }

    public function updateClient(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'package_name' => ['nullable', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'billing_period' => ['nullable', Rule::in(SchoolClient::BILLING_PERIODS)],
            'paid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $client = $this->schools->updateClient(ConsoleAuthController::admin($request), $this->tenant($code), $input);

        return response()->json(['data' => $client->present()]);
    }

    public function storePayment(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(SchoolPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ((float) $input['amount'] < 0 && trim((string) ($input['note'] ?? '')) === '') {
            throw new ApiException('note_required', 'A correction needs a note saying what it corrects.', 422);
        }

        $payment = $this->schools->recordPayment(ConsoleAuthController::admin($request), $this->tenant($code), $input);

        return response()->json(['data' => $payment->present()], 201);
    }

    public function refreshStats(Request $request, string $code, SchoolStatsCollector $collector, PlatformAuditRecorder $audit): JsonResponse
    {
        $tenant = $this->tenant($code);
        $collector->collect($tenant);
        $audit->record('stats.refreshed', ConsoleAuthController::admin($request), $tenant, 'school_stats', $tenant->id);

        return response()->json(['data' => $this->schools->details($tenant)]);
    }

    public function revokeLicence(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'confirm_code' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $licence = $this->schools->revokeLicence(ConsoleAuthController::admin($request), $this->tenant($code), $input['confirm_code'], $input['reason']);

        return response()->json(['data' => LicenceIssuer::summary($licence)]);
    }

    public function startChiefRecovery(Request $request, string $code, ChiefRecovery $recovery): JsonResponse
    {
        return response()->json(['data' => $recovery->start(ConsoleAuthController::admin($request), $this->tenant($code))], 202);
    }

    public function completeChiefRecovery(Request $request, string $code, ChiefRecovery $recovery): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $recovery->complete(ConsoleAuthController::admin($request), $this->tenant($code), $input['code'], $input['reason']);

        return response()->json(['data' => [
            'mfa_reset' => true,
            'message' => 'The Chief Admin\'s two-step sign-in has been reset. They sign in with their own password and set up a new authenticator.',
        ]]);
    }

    private function tenant(string $code): Tenant
    {
        return Tenant::findByCode($code) ?? throw new ApiException('not_found', 'No school has this code.', 404);
    }
}
