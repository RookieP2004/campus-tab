<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\People\Models\Student;
use App\Modules\Privacy\Domain\DataRequests;
use App\Modules\Privacy\Domain\Retention;
use App\Modules\Privacy\Domain\StudentData;
use App\Modules\Privacy\Models\DataRequest;
use App\Modules\Privacy\Models\Incident;
use App\Modules\Privacy\Models\RetentionRun;
use App\Support\Exports\ExportGuard;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The office side of privacy.
 *
 *   GET  /privacy/requests                       the inbox (privacy.requests), late first
 *   POST /privacy/requests/{id}/move             {status, outcome?}
 *   GET  /privacy/requests/{id}/copy             a child's data for an access request (step-up export, watermarked)
 *   POST /privacy/requests/{id}/erase            erase the child's personal details (Chief: privacy.erase, step-up permanent_delete)
 *   GET  /privacy/retention                      rules and recent runs (privacy.retention)
 *   POST /privacy/retention/{kind}/dry-run       list what is past its time; deletes nothing
 *   POST /privacy/retention/runs/{id}/confirm    delete exactly that list (step-up permanent_delete)
 *   GET  /privacy/incidents                      the incident log (privacy.incidents)
 *   POST /privacy/incidents                      record a suspected breach
 *   POST /privacy/incidents/{id}/entries         add a timeline step (and the notified-at dates, status)
 */
class PrivacyOfficeController
{
    public function __construct(private readonly DataRequests $requests, private readonly Retention $retention, private readonly StudentData $data) {}

    public function inbox(): JsonResponse
    {
        $all = DataRequest::query()->orderBy('created_at', 'desc')->limit(500)->get()->map(fn (DataRequest $r) => DataRequests::present($r, true));
        $sorted = $all->sortBy(fn (array $r) => [in_array($r['status'], DataRequest::OPEN, true) ? 0 : 1, $r['late'] ? 0 : 1, $r['due_on']])->values();

        return response()->json(['data' => $sorted->all(), 'meta' => [
            'open' => $all->filter(fn ($r) => in_array($r['status'], DataRequest::OPEN, true))->count(),
            'late' => $all->filter(fn ($r) => $r['late'])->count(),
        ]]);
    }

    public function move(Request $request, string $id): JsonResponse
    {
        /** @var array{status: string, outcome?: string|null} $input */
        $input = $request->validate(['status' => ['required', Rule::in(['in_progress', 'completed', 'rejected'])], 'outcome' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => DataRequests::present($this->requests->move(PrivacyController::user($request), DataRequest::query()->findOrFail($id), $input), true)]);
    }

    public function copy(Request $request, string $id, ExportGuard $exports): JsonResponse
    {
        $student = $this->studentOf(DataRequest::query()->findOrFail($id));
        $user = PrivacyController::user($request);
        $exports->record($user, 'privacy_access_copy', 'json', 1, ['request_id' => $id]);

        return response()->json(['data' => ['watermark' => $exports->watermark($user)['text']] + $this->data->copy($student)])
            ->header('Content-Disposition', 'attachment; filename="data-copy-'.$student->admission_no.'.json"');
    }

    public function erase(Request $request, string $id, AuditRecorder $audit): JsonResponse
    {
        $dr = DataRequest::query()->findOrFail($id);
        if ($dr->type !== 'erasure' || ! in_array($dr->status, DataRequest::OPEN, true)) {
            throw new ApiException('not_an_open_erasure', 'Only an open erasure request can erase a record.', 409);
        }
        $request->validate(['confirm' => ['required', 'string']]);
        $student = $this->studentOf($dr);
        if ($request->input('confirm') !== $student->admission_no) {
            throw new ApiException('confirm_mismatch', 'Type the admission number exactly to confirm.', 422);
        }
        $result = $this->data->erase($student);
        $audit->record('privacy.student_erased', 'security', 'student', (string) $student->getKey(), null, $result + ['request_id' => $id]);
        $moved = $this->requests->move(PrivacyController::user($request), $dr, [
            'status' => 'completed',
            'outcome' => 'The child\'s personal details were erased. School records the law requires (attendance, marks, report cards, fees) and the audit log are kept, without the details that were erased where possible.',
        ]);

        return response()->json(['data' => DataRequests::present($moved, true)]);
    }

    public function retention(): JsonResponse
    {
        $rules = [];
        foreach (Retention::kinds() as $kind) {
            $rules[] = ['kind' => $kind, 'label' => config("privacy.retention.{$kind}.label"), 'days' => (int) config("privacy.retention.{$kind}.days")];
        }
        $runs = RetentionRun::query()->orderBy('created_at', 'desc')->limit(50)->get()->map(fn (RetentionRun $r) => Retention::present($r))->values()->all();

        return response()->json(['data' => ['rules' => $rules, 'runs' => $runs, 'never' => ['audit log', 'fee ledger']]]);
    }

    public function dryRun(Request $request, string $kind): JsonResponse
    {
        return response()->json(['data' => Retention::present($this->retention->dryRun($kind, PrivacyController::user($request)))], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => Retention::present($this->retention->confirm(PrivacyController::user($request), RetentionRun::query()->findOrFail($id)))]);
    }

    public function incidents(): JsonResponse
    {
        return response()->json(['data' => Incident::query()->orderBy('detected_at', 'desc')->get()->map(fn (Incident $i) => self::incident($i))->values()->all()]);
    }

    public function report(Request $request): JsonResponse
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'detected_at' => ['required', 'date'],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'data_affected' => ['sometimes', 'array', 'max:20'],
            'data_affected.*' => ['string', 'max:60'],
            'people_affected' => ['nullable', 'integer', 'min:0'],
        ]);
        $user = PrivacyController::user($request);
        $incident = Incident::create($input + [
            'detected_at' => CarbonImmutable::parse($input['detected_at'])->toIso8601String(),
            'reported_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            'timeline' => [['at' => now()->toIso8601String(), 'by' => $user->name, 'note' => 'Incident recorded']],
        ]);

        return response()->json(['data' => self::incident($incident)], 201);
    }

    public function addEntry(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
            'status' => ['sometimes', Rule::in(Incident::STATUSES)],
            'board_notified' => ['sometimes', 'boolean'],
            'people_notified' => ['sometimes', 'boolean'],
        ]);
        $incident = Incident::query()->findOrFail($id);
        $user = PrivacyController::user($request);
        $now = now()->toIso8601String();
        $changes = ['timeline' => [...$incident->timeline, ['at' => $now, 'by' => $user->name, 'note' => trim($input['note'])]]];
        if (isset($input['status'])) {
            $changes['status'] = $input['status'];
        }
        if (($input['board_notified'] ?? false) && $incident->board_notified_at === null) {
            $changes['board_notified_at'] = $now;
        }
        if (($input['people_notified'] ?? false) && $incident->people_notified_at === null) {
            $changes['people_notified_at'] = $now;
        }
        $incident->fill($changes)->save();

        return response()->json(['data' => self::incident($incident)]);
    }

    /** @return array<string, mixed> */
    private static function incident(Incident $i): array
    {
        $deadline = CarbonImmutable::parse($i->detected_at)->addHours((int) config('privacy.breach_report_hours', 72));

        return [
            'id' => (string) $i->getKey(), 'title' => $i->title, 'detected_at' => $i->detected_at, 'severity' => $i->severity, 'status' => $i->status,
            'description' => $i->description, 'data_affected' => $i->data_affected ?? [], 'people_affected' => $i->people_affected,
            'board_notified_at' => $i->board_notified_at, 'people_notified_at' => $i->people_notified_at,
            'board_deadline' => $deadline->toIso8601String(),
            'board_overdue' => $i->board_notified_at === null && $deadline->isPast(),
            'reported_by' => $i->reported_by['name'] ?? null, 'timeline' => $i->timeline,
        ];
    }

    private function studentOf(DataRequest $request): Student
    {
        if ($request->student_id === null) {
            throw new ApiException('not_about_a_child', 'This request is about the person\'s own account: answer it from their staff or parent record.', 422);
        }

        return Student::query()->findOrFail($request->student_id);
    }
}
