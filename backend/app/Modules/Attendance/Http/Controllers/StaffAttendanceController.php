<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Domain\AttendanceSettings;
use App\Modules\Attendance\Domain\StaffAttendanceService;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\People\Models\Staff;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff attendance.
 *
 *   POST /attendance/staff/check-in   { method: app|qr, lat?, lng?, qr? }   lat/lng only compared, never stored
 *   POST /attendance/staff/check-out  { method: app|qr, lat?, lng?, qr? }
 *   GET  /attendance/staff/me ?month                                      my own days
 *   GET  /attendance/staff ?date                                          everyone's day (office)
 *   POST /attendance/staff/{staffId}/manual { date, status, in?, out?, reason }   for someone below you, never yourself
 *   GET  /attendance/staff/qr                                             the office screen's current QR code
 *   POST /attendance/staff/import    { file, commit }                      a biometric device's CSV
 */
class StaffAttendanceController
{
    public function __construct(
        private readonly StaffAttendanceService $service,
        private readonly AttendanceSettings $settings,
    ) {}

    public function checkIn(Request $request): JsonResponse
    {
        $input = $this->checkInput($request);
        $record = $this->service->checkIn($this->user($request), $input['method'], $this->location($input), $input['qr'] ?? null);

        return response()->json(['data' => self::present($record)], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $input = $this->checkInput($request);
        $record = $this->service->checkOut($this->user($request), $input['method'], $this->location($input), $input['qr'] ?? null);

        return response()->json(['data' => self::present($record)]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $staff = Staff::query()->where('user_id', (string) $user->getKey())->first();
        $month = (string) $request->query('month', substr($this->settings->today(), 0, 7));

        $records = $staff instanceof Staff
            ? StaffAttendance::query()->where('staff_id', (string) $staff->getKey())->where('date', 'regex', '/^'.preg_quote($month, '/').'/')->orderBy('date')->get()
            : collect();

        $today = $records->firstWhere('date', $this->settings->today());

        return response()->json(['data' => [
            'is_staff' => $staff instanceof Staff,
            'today' => $today instanceof StaffAttendance ? self::present($today) : null,
            'late_after' => $this->settings->all()['staff']['late_after'],
            'geofence' => (bool) ($this->settings->all()['staff']['geofence']['enabled'] ?? false),
            'days' => $records->map(fn (StaffAttendance $r) => self::present($r))->values(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewStaffDay', StaffAttendance::class);
        $date = (string) $request->query('date', $this->settings->today());
        /** @var User $actor */
        $actor = $request->user();
        $records = StaffAttendance::query()->where('date', $date)->get()->keyBy('staff_id');

        $users = User::query()->whereIn('_id', Staff::query()->whereNotNull('user_id')->pluck('user_id')->all())->get()->keyBy(fn (User $u) => (string) $u->getKey());

        return response()->json(['data' => [
            'date' => $date,
            'staff' => Staff::query()->where('status', Staff::STATUS_ACTIVE)->orderBy('name')->get()->map(fn (Staff $s) => [
                'staff_id' => (string) $s->getKey(),
                'name' => $s->name,
                'designation' => $s->designation,
                'roles' => isset($users[$s->user_id ?? '']) ? $users[$s->user_id]->roles : [],
                'can_enter' => $this->service->canEnterFor($actor, $users[$s->user_id ?? ''] ?? null),
                'record' => isset($records[(string) $s->getKey()]) ? self::present($records[(string) $s->getKey()]) : null,
            ])->values(),
        ]]);
    }

    public function manual(Request $request, string $staffId): JsonResponse
    {
        $staff = Staff::query()->findOrFail($staffId);
        Gate::authorize('enterManual', StaffAttendance::class);

        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['P', 'A', 'L', 'HD', 'LV'])],
            'in' => ['nullable', 'date_format:H:i'],
            'out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        return response()->json(['data' => self::present($this->service->manual($this->user($request), $staff, $input))], 201);
    }

    public function qr(): JsonResponse
    {
        Gate::authorize('manage', StaffAttendance::class);

        return response()->json(['data' => $this->service->qrToken()]);
    }

    public function import(Request $request): JsonResponse
    {
        Gate::authorize('manage', StaffAttendance::class);
        $input = $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt,xlsx'], 'commit' => ['sometimes', 'boolean']]);
        $file = $request->file('file');
        $rows = Spreadsheet::read((string) $file?->getRealPath(), (string) $file?->getClientOriginalName());

        return response()->json(['data' => $this->service->importBiometric($rows, (bool) ($input['commit'] ?? false))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(StaffAttendance $r): array
    {
        return [
            'id' => (string) $r->getKey(),
            'staff_id' => $r->staff_id,
            'date' => $r->date,
            'status' => $r->status,
            'in' => $r->in,
            'out' => $r->out,
            'source' => $r->source,
            'inside_area' => $r->inside_area,
            'late' => $r->late,
            'entered_by' => $r->entered_by['name'] ?? null,
            'reason' => $r->reason,
        ];
    }

    /**
     * @return array{method: string, lat?: float, lng?: float, qr?: string}
     */
    private function checkInput(Request $request): array
    {
        /** @var array{method: string, lat?: float, lng?: float, qr?: string} */
        return $request->validate([
            'method' => ['required', Rule::in(['app', 'qr'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'qr' => ['nullable', 'string', 'max:64', 'required_if:method,qr'],
        ]);
    }

    /**
     * @param  array{lat?: float, lng?: float}  $input
     * @return array{lat: float, lng: float}|null
     */
    private function location(array $input): ?array
    {
        return isset($input['lat'], $input['lng']) ? ['lat' => (float) $input['lat'], 'lng' => (float) $input['lng']] : null;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
