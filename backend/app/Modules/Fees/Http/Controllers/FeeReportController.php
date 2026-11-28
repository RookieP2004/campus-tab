<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Fees\Domain\FeeReports;
use App\Modules\Fees\Domain\Money;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\Reports\Domain\Pdf\PdfRenderer;
use App\Support\Exports\ExportGuard;
use App\Support\Exports\PdfTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fee reports (fees.reports: school, or own collections for a Fee Collector).
 *
 *   GET /fees/reports/{type}?from=&to=&section_id=
 *   GET /fees/reports/{type}/export?format=xlsx|pdf   step-up "export", audited (owner's rule 6);
 *        watermarked with who, when and which school; a large one alerts the Chief
 */
class FeeReportController
{
    public function __construct(private readonly FeeReports $reports, private readonly AccessControl $access) {}

    public function show(Request $request, string $type): JsonResponse
    {
        Gate::authorize('fees.reports');

        return response()->json(['data' => ['type' => $type] + $this->build($request, $type)]);
    }

    public function export(Request $request, string $type, AuditRecorder $audit, ExportGuard $exports, PdfRenderer $pdf): Response
    {
        Gate::authorize('fees.reports');
        $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'pdf'])]]);
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';
        $report = $this->build($request, $type);
        $columns = array_column($report['columns'], 'key');
        $money = array_column(array_filter($report['columns'], fn ($c) => $c['money'] ?? false), 'key');
        $headers = array_map(fn (string $k) => Str::of($k)->replace('_paise', '')->replace('_', ' ')->ucfirst()->toString().(in_array($k, $money, true) ? ' (Rs)' : ''), $columns);
        $rows = array_map(fn (array $row) => array_map(fn (string $k) => in_array($k, $money, true) ? Money::format((int) $row[$k]) : (is_scalar($row[$k] ?? null) ? (string) $row[$k] : null), $columns), $report['rows']);

        $user = StudentFeesController::user($request);
        $mark = $exports->watermark($user);
        $name = 'fees-'.$type.'-'.now('Asia/Kolkata')->format('Y-m-d').'.'.$format;
        $audit->record('fees.report_exported', 'fees', 'fee_report', $type, null, null, ['rows' => count($rows), 'format' => $format, 'filters' => $request->only(['from', 'to', 'section_id'])]);
        $exports->record($user, "fees_{$type}", $format, count($rows), ['filters' => $request->only(['from', 'to', 'section_id'])]);

        if ($format === 'pdf') {
            $title = 'Fee report: '.Str::of($type)->replace('_', ' ')->ucfirst();

            return response($pdf->render(PdfTable::html($title, $headers, $rows, $mark)), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'"',
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'ctab-fees-').'.xlsx';
        Spreadsheet::write($path, $headers, $rows, $mark);

        return response()->download($path, $name)->deleteFileAfterSend();
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
