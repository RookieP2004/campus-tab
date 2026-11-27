<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Models\CalendarEvent;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The holiday calendar: the Clerk keeps it, the Principal approves.
 *
 *   GET    /calendar ?from &to                approved entries (plus pending ones for those who keep or approve it)
 *   POST   /calendar { date, end_date?, type, title }       pending until approved
 *   PATCH  /calendar/{id}                     a pending entry
 *   DELETE /calendar/{id}                     a pending entry
 *   POST   /calendar/{id}/approve · /reject { note? }
 */
class CalendarController
{
    public function index(Request $request, AccessControl $access): JsonResponse
    {
        Gate::authorize('viewAny', CalendarEvent::class);
        $user = $this->user($request);
        $from = (string) $request->query('from', now('Asia/Kolkata')->startOfMonth()->toDateString());
        $to = (string) $request->query('to', now('Asia/Kolkata')->addMonths(2)->endOfMonth()->toDateString());

        $query = CalendarEvent::query()->where('date', '<=', $to)->where('end_date', '>=', $from)->orderBy('date');

        if (! $access->allows($user, 'calendar.manage') && ! $access->allows($user, 'calendar.approve')) {
            $query->where('status', 'approved');
        }

        return response()->json([
            'data' => $query->get()->map(fn (CalendarEvent $e) => self::present($e))->values(),
            'can' => ['manage' => $access->allows($user, 'calendar.manage'), 'approve' => $access->allows($user, 'calendar.approve')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CalendarEvent::class);
        $input = $this->validated($request, true);
        $user = $this->user($request);

        $event = CalendarEvent::create(array_merge($input, [
            'end_date' => $input['end_date'] ?? $input['date'],
            'status' => 'pending',
            'created_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
        ]));

        return response()->json(['data' => self::present($event)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $event = $this->pending($id);
        Gate::authorize('update', $event);
        $event->fill($this->validated($request, false))->save();

        return response()->json(['data' => self::present($event)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $event = $this->pending($id);
        Gate::authorize('update', $event);
        $event->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'approved');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'rejected');
    }

    private function decide(Request $request, string $id, string $status): JsonResponse
    {
        $event = $this->pending($id);
        Gate::authorize('approve', $event);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? null;
        $user = $this->user($request);

        $event->fill(['status' => $status, 'decided_by' => ['id' => (string) $user->getKey(), 'name' => $user->name], 'decision_note' => $note])->save();

        return response()->json(['data' => self::present($event)]);
    }

    private function pending(string $id): CalendarEvent
    {
        $event = CalendarEvent::query()->findOrFail($id);

        if ($event->status !== 'pending') {
            throw new ApiException('calendar_not_pending', 'Only an entry waiting for approval can be changed.', 409);
        }

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'type' => [$required, Rule::in(['holiday', 'event'])],
            'title' => [$required, 'string', 'min:2', 'max:120'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CalendarEvent $e): array
    {
        return [
            'id' => (string) $e->getKey(), 'date' => $e->date, 'end_date' => $e->end_date, 'type' => $e->type, 'title' => $e->title,
            'status' => $e->status, 'created_by' => $e->created_by['name'] ?? null, 'decided_by' => $e->decided_by['name'] ?? null, 'decision_note' => $e->decision_note,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
