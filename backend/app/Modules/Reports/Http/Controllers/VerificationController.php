<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Reports\Models\ReportCard;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /public/verify/{school}/{code} — the page a report card's QR code opens.
 *
 * Owner's rule 3: it shows only the school, the student's name, the class,
 * the year, the version, the issue date and whether this version is current
 * or replaced. No marks, no photo, nothing else.
 *
 * No tenant-scope exception is needed: the school code in the link finds
 * the school (a platform record), exactly as sign-in does, and the card is
 * then looked up inside that school by the SHA-256 of a 32-byte random
 * code, which cannot be guessed or listed. Rate-limited per address.
 */
class VerificationController
{
    public function __invoke(string $school, string $code, TenantContext $context, SchoolConfigService $configs): JsonResponse
    {
        $tenant = preg_match('/^[A-Za-z0-9_-]{40,64}$/', $code) === 1 ? Tenant::query()->where('code', strtoupper($school))->first() : null;
        if (! $tenant instanceof Tenant) {
            throw self::unknown();
        }

        return $context->run($tenant->id, function () use ($tenant, $code, $configs): JsonResponse {
            $card = ReportCard::query()->where('verify_hash', hash('sha256', $code))->first();
            if (! $card instanceof ReportCard || ! $card->isPublic()) {
                throw self::unknown();
            }

            return response()->json(['data' => [
                'school' => (string) ($configs->current()->body['school']['name'] ?? $tenant->name),
                'student' => $card->student_name,
                'class' => $card->section_label,
                'year' => $card->year_name,
                'version' => $card->version,
                'issued_on' => $card->issued_at?->timezone('Asia/Kolkata')->toDateString(),
                'status' => $card->status === ReportCard::PUBLISHED ? 'current' : 'replaced',
            ]])->header('Cache-Control', 'no-store');
        });
    }

    private static function unknown(): ApiException
    {
        return new ApiException('not_found', 'This report card could not be found. Check that the whole code was scanned.', 404);
    }
}
