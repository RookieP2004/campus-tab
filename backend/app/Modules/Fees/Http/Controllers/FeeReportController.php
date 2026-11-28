<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Fees\Domain\FeeReports;
use App\Modules\Fees\Domain\Money;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\People\Domain\Import\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fee reports (fees.reports: school, or own collections for a Fee Collector).
 *
 *   GET /fees/reports/{type}?from=&to=&section_id=
 *   GET /fees/reports/{type}/export   Excel; step-up "export", audited (owner's rule 6)
 */
class FeeReportController
{
    public function __construct(private readonly FeeReports $reports, private readonly AccessControl $access) {}

    public function show(Request $request, string $type): JsonResponse
    {
        Gate::authorize('fees.reports');

        return response()->json(['data' => ['type' => $type] + $this->build($request, $type)]);
    }

    public function export(Request $request, string $type, AuditRecorder $audit): BinaryFileResponse
    {
        Gate::authorize('fees.reports');
        $report = $this->build($request, $type);
        $columns = array_column($report['columns'], 'key');
        $money = array_column(array_filter($report['columns'], fn ($c) => $c['money'] ?? false), 'key');
        $headers = array_map(fn (string $k) => Str::of($k)->replace('_paise', '')->replace('_', ' ')->ucfirst()->toString().(in_array($k, $money, true) ? ' (Rs)' : ''), $columns);
        $rows = array_map(fn (array $row) => array_map(fn (string $k) => in_array($k, $money, true) ? Money::format((int) $row[$k]) : (is_scalar($row[$k] ?? null) ? (string) $row[$k] : null), $columns), $report['rows']);

        $path = tempnam(sys_get_temp_dir(), 'ctab-fees-').'.xlsx';
        Spreadsheet::write($path, $headers, $rows);
        $audit->record('fees.report_exported', 'fees', 'fee_report', $type, null, null, ['rows' => count($rows), 'filters' => $request->only(['from', 'to', 'section_id'])]);

        return response()->download($path, 'fees-'.$type.'-'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    /**
     * @return array{columns: list<array{key: string, money?: bool}>, rows: list<array<string, mixed>>}
     */
    private function build(Request $request, string $type): array
    {
        $user = StudentFeesController::user($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'section_id' => ['nullable', 'string', 'max:30'],
        ]);
        validator(['type' => $type], ['type' => [Rule::in((array) config('fees.reports.types'))]])->validate();
        $own = ! $this->access->holdsAt($user, 'fees.reports', 'school');

        return $this->reports->build($type, $filters, $own ? (string) $user->getKey() : null);
    }
}
