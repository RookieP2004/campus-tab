<?php

namespace App\Modules\People\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\Import\Importer;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\People\Jobs\RunImport;
use App\Modules\People\Models\ImportBatch;
use App\Support\Exports\ExportGuard;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel import for students and staff.
 *
 *   GET  /imports/{entity}/template          the .xlsx template
 *   POST /imports/{entity}  (file)            upload; the dry run starts on the queue
 *   GET  /imports/{id}                        status, progress, summary and row report
 *   GET  /imports/{id}/report                 the dry-run report as .xlsx
 *   POST /imports/{id}/commit                 create the ok rows (queued)
 *
 * entity is students or staff (imports.students / imports.staff).
 */
class ImportController
{
    public function __construct(
        private readonly Importer $importer,
        private readonly TenantContext $context,
    ) {}

    public function template(Request $request, string $entity): BinaryFileResponse
    {
        Gate::authorize('import', [ImportBatch::class, $entity]);
        $template = $this->importer->template($this->user($request), $entity);

        $path = tempnam(sys_get_temp_dir(), 'ctab-tpl-').'.xlsx';
        $exports = app(ExportGuard::class);
        Spreadsheet::write($path, $template['headers'], [$template['example']], $exports->watermark($this->user($request)));
        $exports->record($this->user($request), "{$entity}_import_template", 'xlsx', 1);

        return response()->download($path, "campus-tab-{$entity}-template.xlsx")->deleteFileAfterSend();
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        Gate::authorize('import', [ImportBatch::class, $entity]);

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt']]);
        $file = $request->file('file');
        $user = $this->user($request);

        $batch = ImportBatch::create([
            'entity' => $entity,
            'status' => 'queued',
            'file_path' => '',
            'file_name' => mb_substr((string) $file?->getClientOriginalName(), 0, 120),
            'created_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
        ]);

        $extension = str_ends_with(mb_strtolower($batch->file_name), '.csv') ? 'csv' : 'xlsx';
        $path = $file?->storeAs('tenants/'.$this->context->require().'/imports', $batch->getKey().'.'.$extension, 'local');
        $batch->forceFill(['file_path' => (string) $path, 'file_name' => preg_replace('/\.[^.]+$/', '', $batch->file_name).'.'.$extension])->save();

        RunImport::dispatch($this->context->require(), (string) $batch->getKey(), false);

        return response()->json(['data' => $this->present(ImportBatch::query()->findOrFail($batch->getKey()))], 202);
    }

    public function show(string $id): JsonResponse
    {
        $batch = ImportBatch::query()->findOrFail($id);
        Gate::authorize('import', [ImportBatch::class, $batch->entity]);

        return response()->json(['data' => $this->present($batch)]);
    }

    public function report(Request $request, string $id): BinaryFileResponse
    {
        $batch = ImportBatch::query()->findOrFail($id);
        Gate::authorize('import', [ImportBatch::class, $batch->entity]);

        if (! in_array($batch->status, ['validated', 'committing', 'committed'], true)) {
            throw new ApiException('import_not_checked', 'The check has not finished yet.', 409);
        }

        $summary = $batch->summary ?? [];
        $rows = array_map(fn (string $m) => ['', 'summary', '', $m], $summary['messages'] ?? []);

        foreach ($batch->rows as $row) {
            $rows[] = [$row['row'], $row['status'], $row['label'], implode(' ', $row['messages'])];
        }

        $path = tempnam(sys_get_temp_dir(), 'ctab-rep-').'.xlsx';
        $exports = app(ExportGuard::class);
        Spreadsheet::write($path, ['Row', 'Result', 'Record', 'What to fix'], $rows, $exports->watermark($this->user($request)));
        $exports->record($this->user($request), "{$batch->entity}_import_report", 'xlsx', count($rows));

        return response()->download($path, "campus-tab-{$batch->entity}-import-report.xlsx")->deleteFileAfterSend();
    }

    public function commit(string $id): JsonResponse
    {
        $batch = ImportBatch::query()->findOrFail($id);
        Gate::authorize('import', [ImportBatch::class, $batch->entity]);

        if ($batch->status !== 'validated') {
            throw new ApiException('import_not_ready', 'Only a checked file can be imported, once.', 409);
        }

        if (! ($batch->summary['can_commit'] ?? false)) {
            throw new ApiException('import_not_ready', $batch->summary['messages'][0] ?? 'Nothing in this file can be imported.', 422, ['summary' => $batch->summary]);
        }

        $batch->forceFill(['status' => 'committing'])->save();
        RunImport::dispatch($this->context->require(), (string) $batch->getKey(), true);

        return response()->json(['data' => $this->present(ImportBatch::query()->findOrFail($id))], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ImportBatch $b): array
    {
        return [
            'id' => (string) $b->getKey(),
            'entity' => $b->entity,
            'status' => $b->status,
            'file_name' => $b->file_name,
            'progress' => $b->progress,
            'summary' => $b->summary,
            'rows' => $b->rows,
            'result' => $b->result,
            'error' => $b->error,
            'created_by' => $b->created_by,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
