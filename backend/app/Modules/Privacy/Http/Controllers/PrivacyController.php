<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Privacy\Domain\Consents;
use App\Modules\Privacy\Domain\DataRequests;
use App\Modules\Privacy\Domain\Notices;
use App\Modules\Privacy\Models\DataRequest;
use App\Modules\Privacy\Models\PrivacyNotice;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Everyone's own privacy page (privacy.self) and the notice editor.
 *
 *   GET  /privacy                      the current notice, the grievance contact, my consents (parents), my requests
 *   POST /privacy/consents             {student_id, purpose, given, notice_version}   parents only, own children
 *   POST /privacy/requests             {type, student_id?, details}
 *   GET  /privacy/notices              every version (privacy.notices)
 *   PUT  /privacy/notices/draft        {title, body, summary_of_changes?}
 *   POST /privacy/notices/publish      the draft becomes the next version
 */
class PrivacyController
{
    public function __construct(
        private readonly Consents $consents,
        private readonly DataRequests $requests,
        private readonly Notices $notices,
        private readonly AccessControl $access,
    ) {}

    public function mine(Request $request, SchoolConfigService $configs): JsonResponse
    {
        $user = self::user($request);
        $notice = PrivacyNotice::current();
        $isParent = in_array('parent', (array) $user->roles, true);

        return response()->json(['data' => [
            'notice' => $notice instanceof PrivacyNotice ? Notices::present($notice) : null,
            'grievance' => $configs->current()->body['school']['grievance'] ?? null,
            'consents' => $isParent ? $this->consents->forParent($user) : null,
            'requests' => DataRequest::query()->where('requester_id', (string) $user->getKey())->orderBy('created_at', 'desc')->get()
                ->map(fn (DataRequest $r) => DataRequests::present($r, false))->values()->all(),
            'can' => [
                'notices' => $this->access->allows($user, 'privacy.notices'),
                'requests' => $this->access->allows($user, 'privacy.requests'),
                'retention' => $this->access->allows($user, 'privacy.retention'),
                'incidents' => $this->access->allows($user, 'privacy.incidents'),
            ],
        ]]);
    }

    public function answer(Request $request): JsonResponse
    {
        $input = $request->validate([
            'student_id' => ['required', 'string', 'max:30'],
            'purpose' => ['required', 'string', 'max:40'],
            'given' => ['required', 'boolean'],
            'notice_version' => ['required', 'integer', 'min:1'],
        ]);
        $user = self::user($request);
        if (! in_array('parent', (array) $user->roles, true)) {
            throw new ApiException('parents_only', 'A parent gives consent from their own account.', 403);
        }
        $this->consents->answer($user, $input['student_id'], $input['purpose'], (bool) $input['given'], (int) $input['notice_version']);

        return response()->json(['data' => $this->consents->forParent($user)]);
    }

    public function ask(Request $request): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', Rule::in(DataRequest::TYPES)],
            'student_id' => ['nullable', 'string', 'max:30'],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return response()->json(['data' => DataRequests::present($this->requests->create(self::user($request), $input), false)], 201);
    }

    public function notices(): JsonResponse
    {
        return response()->json(['data' => $this->notices->history()]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        /** @var array{title: string, body: string, summary_of_changes?: string|null} $input */
        $input = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:50', 'max:30000'],
            'summary_of_changes' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => Notices::present($this->notices->saveDraft($input))]);
    }

    public function publish(Request $request): JsonResponse
    {
        return response()->json(['data' => Notices::present($this->notices->publish(self::user($request)))]);
    }

    public static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
