<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Attendance\Domain\AttendanceRegister;
use App\Modules\Attendance\Domain\AttendanceReports;
use App\Modules\Attendance\Domain\AttendanceSettings;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\People\Domain\StudentRecords;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Exports\ExportGuard;
use App\Support\Http\ApiException;
use App\Support\Privacy\Aadhaar;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Student attendance.
 *
 *   GET  /attendance/sections/{id}/days/{date} ?period      the register: roll, marks, holiday, what you may do
 *   PUT  /attendance/sections/{id}/days/{date}              { period?, statuses{student_id: P|A|L|HD}, reason? }
 *   GET  /attendance/sections/{id}/template ?date           .xlsx to fill from the paper register
 *   POST /attendance/sections/{id}/days/{date}/import       { file, reason, period?, commit } dry run, then commit
 *   POST /attendance/days/{id}/photo                        { photo } a picture of the paper page (late entries)
 *   POST /attendance/days/{id}/photo/link                   a link to that picture, valid a few minutes
 *   GET  /attendance/summary ?date                          the daily summary for the sections you may see
 *   GET  /attendance/register ?section_id &month            the monthly register (printable)
 *   GET  /attendance/students/{id} ?month                   one student's days and % (parents: their own child)
 *   GET  /attendance/below-threshold ?section_id            students under the school's threshold
 *   GET  /attendance/settings                               the school's attendance settings (for the screens)
 */
class AttendanceController
{
    public function __construct(
        private readonly AttendanceRegister $register,
        private readonly AttendanceReports $reports,
        private readonly AttendanceSettings $settings,
    ) {}

    public function show(Request $request, string $id, string $date): JsonResponse
    {
        $section = $this->section($id);
        Gate::authorize('viewRegister', [AttendanceDay::class, $section]);

        return response()->json(['data' => $this->register->day($this->user($request), $section, $this->date($date), (int) $request->query('period', 0))]);
    }

    public function update(Request $request, string $id, string $date): JsonResponse
    {
        $section = $this->section($id);
        Gate::authorize('viewRegister', [AttendanceDay::class, $section]);

        $input = $request->validate([
            'period' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'statuses' => ['present', 'array'],
            'statuses.*' => ['string', 'max:3'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $this->register->save($this->user($request), $tenant, $section, $this->date($date), (int) ($input['period'] ?? 0), $input['statuses'], $input['reason'] ?? null);

        return response()->json(['data' => $this->register->day($this->user($request), $section, $this->date($date), (int) ($input['period'] ?? 0))]);
    }

    public function template(Request $request, string $id): BinaryFileResponse
    {
        $section = $this->section($id);
        Gate::authorize('viewRegister', [AttendanceDay::class, $section]);
        $date = $this->date((string) $request->query('date', $this->settings->today()));

        $rows = array_map(fn (array $s) => [$s['roll_no'], $s['admission_no'], $s['name'], 'P'], $this->register->roll($section));
        $path = tempnam(sys_get_temp_dir(), 'ctab-att-').'.xlsx';
        $exports = app(ExportGuard::class);
        Spreadsheet::write($path, ['roll_no', 'admission_no', 'name', 'status (P, A, L or HD)'], $rows, $exports->watermark($this->user($request)));
        $exports->record($this->user($request), 'attendance_template', 'xlsx', count($rows), ['section' => $section->label, 'date' => $date]);

        return response()->download($path, "attendance-{$section->label}-{$date}.xlsx")->deleteFileAfterSend();
    }

    public function import(Request $request, string $id, string $date): JsonResponse
    {
        $section = $this->section($id);
        Gate::authorize('viewRegister', [AttendanceDay::class, $section]);
        $date = $this->date($date);

        $input = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt'],
            'reason' => ['nullable', 'string', 'max:500'],
            'period' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'commit' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('file');
        $rows = Spreadsheet::read((string) $file?->getRealPath(), (string) $file?->getClientOriginalName());
        $roll = collect($this->register->roll($section))->keyBy('admission_no');
        $report = [];
        $statuses = [];

        foreach ($rows as $i => $row) {
            $messages = [];
            $student = $roll[$row['admission_no'] ?? ''] ?? null;
            $status = strtoupper(trim($row['status'] ?? 'P')) ?: 'P';

            if (Aadhaar::findIn($row) !== []) {
                $messages[] = 'A full Aadhaar number is not allowed in any column.';
            }
            if ($student === null) {
                $messages[] = 'admission_no '.($row['admission_no'] ?? '').' is not in '.$section->label.'.';
            }
            if (! in_array($status, AttendanceDay::MARKABLE, true)) {
                $messages[] = 'status must be P, A, L or HD.';
            }

            if ($messages === [] && $student !== null) {
                $statuses[$student['id']] = $status;
            }

            $report[] = ['row' => $i + 2, 'status' => $messages === [] ? 'ok' : 'error', 'label' => trim(($row['name'] ?? '').' '.($row['admission_no'] ?? '')), 'messages' => $messages];
        }

        $errors = count(array_filter($report, fn ($r) => $r['status'] === 'error'));
        $rules = $this->register->rules($this->user($request), $section, $date, $this->register->find($section, $date, (int) ($input['period'] ?? 0)), false);
        $result = [
            'rows' => $report,
            'ok' => count($report) - $errors,
            'errors' => $errors,
            'absent' => count(array_filter($statuses, fn ($s) => $s === 'A')),
            'rules' => $rules,
            'committed' => false,
        ];

        if (($input['commit'] ?? false) && $errors === 0) {
            /** @var Tenant $tenant */
            $tenant = $request->attributes->get('tenant');
            $this->register->save($this->user($request), $tenant, $section, $date, (int) ($input['period'] ?? 0), $statuses, $input['reason'] ?? null, 'excel');
            $result['committed'] = true;
        }

        return response()->json(['data' => $result]);
    }

    public function uploadPhoto(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $day = AttendanceDay::query()->findOrFail($id);
        Gate::authorize('addPhoto', $day);

        $request->validate(['photo' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp']]);
        $file = $request->file('photo');
        $extension = $file?->extension() === 'jpeg' ? 'jpg' : (string) $file?->extension();
        $path = $file?->storeAs('tenants/'.$context->require().'/attendance/'.$day->getKey(), 'register.'.$extension, 'local');

        $late = (array) $day->late_entry;
        $late['photo_path'] = (string) $path;
        $day->forceFill(['late_entry' => $late])->save();

        return response()->json(['data' => ['has_photo' => true]], 201);
    }

    public function photoLink(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $day = AttendanceDay::query()->findOrFail($id);
        Gate::authorize('view', $day);

        if (! is_string($day->late_entry['photo_path'] ?? null)) {
            throw new ApiException('not_found', 'This day has no photo of the paper register.', 404);
        }

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('files.attendance-photo', now()->addMinutes((int) config('communication.notices.link_minutes', 5)), [
                'tenant' => $context->require(), 'day' => (string) $day->getKey(),
            ], absolute: false),
        ]]);
    }

    /** Only through the signed link above. */
    public function photo(string $tenant, string $day, TenantContext $context): Response
    {
        return $context->run($tenant, function () use ($day): Response {
            $record = AttendanceDay::query()->find($day);
            $path = $record?->late_entry['photo_path'] ?? null;

            if (($record?->late_entry['photo_removed'] ?? false) === true) {
                throw new ApiException('removed_under_retention', 'This photo was deleted under the school\'s data retention rules.', 410);
            }
            if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                throw new ApiException('not_found', 'The record was not found.', 404);
            }

            return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewReports', AttendanceDay::class);
        $date = $this->date((string) $request->query('date', $this->settings->today()));

        return response()->json(['data' => $this->reports->daily($this->user($request), $date)]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $input = $request->validate(['section_id' => ['required', 'string', 'max:30'], 'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $section = $this->section($input['section_id']);
        Gate::authorize('viewRegister', [AttendanceDay::class, $section]);

        return response()->json(['data' => $this->reports->monthly($section, $input['month'])]);
    }

    /** One student's attendance: the parent, the student, and those who may see that student. */
    public function student(Request $request, string $id, AccessControl $access, StudentRecords $records): JsonResponse
    {
        $student = Student::query()->find($id) ?? throw new ApiException('not_found', 'The record was not found.', 404);
        $student = $records->withEnrolment($student);

        if (! $access->allows($this->user($request), 'attendance.view', $student)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        $month = (string) $request->query('month', substr($this->settings->today(), 0, 7));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new ApiException('month_invalid', 'Use a month like 2026-12.', 422);
        }

        return response()->json(['data' => $this->reports->student($student, $month)]);
    }

    public function belowThreshold(Request $request): JsonResponse
    {
        Gate::authorize('viewReports', AttendanceDay::class);
        $section = $request->query('section_id');

        return response()->json(['data' => $this->reports->belowThreshold($this->user($request), is_string($section) && $section !== '' ? $section : null)]);
    }

    public function settings(AccessControl $access, Request $request): JsonResponse
    {
        $settings = $this->settings->all();
        // The geofence centre is the school's own location; screens only need to know it is on.
        $settings['staff']['geofence'] = ['enabled' => (bool) ($settings['staff']['geofence']['enabled'] ?? false)];

        return response()->json(['data' => array_merge($settings, ['today' => $this->settings->today(), 'timezone' => $this->settings->timezone()])]);
    }

    private function section(string $id): Section
    {
        return Section::query()->find($id) ?? throw new ApiException('not_found', 'The record was not found.', 404);
    }

    private function date(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new ApiException('date_invalid', 'Use a date like 2026-12-03.', 422);
        }

        return $date;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
