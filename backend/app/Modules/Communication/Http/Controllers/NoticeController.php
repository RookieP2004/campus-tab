<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Domain\Notices;
use App\Modules\Communication\Models\Notice;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notices.
 *
 *   GET  /notices                                  notices you may read, newest first, with "read"
 *   POST /notices (multipart)                      { title, body, audience[type], audience[refs][], attachments[] }
 *   GET  /notices/{id}                             one notice; marks it read for you
 *   GET  /notices/{id}/receipts                    how many read it, and who (sender or school-wide readers)
 *   POST /notices/{id}/attachments/{aid}/link      a download link that works for a few minutes
 *   GET  /files/notices/{tenant}/{notice}/{aid}    the file, through that signed link only
 */
class NoticeController
{
    public function __construct(private readonly Notices $notices) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notices->listFor($this->user($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $types = (array) config('communication.notices.attachment_types');
        $input = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', Rule::in(Notice::AUDIENCE_TYPES)],
            'audience.refs' => ['array', 'required_unless:audience.type,school'],
            'audience.refs.*' => ['string', 'max:40'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('communication.notices.attachments_per_notice', 5)],
            'attachments.*' => ['file', 'max:'.(int) config('communication.notices.attachment_max_kb', 5120), 'mimes:'.implode(',', $types)],
        ]);

        $notice = $this->notices->send(
            $this->user($request),
            $input['title'],
            $input['body'],
            ['type' => $input['audience']['type'], 'refs' => array_values($input['audience']['refs'] ?? [])],
            array_values($request->file('attachments', [])),
        );

        return response()->json(['data' => Notices::present($notice)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $notice = Notice::query()->findOrFail($id);
        $this->notices->open($this->user($request), $notice);

        return response()->json(['data' => array_merge(Notices::present($notice), ['read' => true])]);
    }

    public function receipts(Request $request, string $id, AccessControl $access): JsonResponse
    {
        $notice = Notice::query()->findOrFail($id);
        $user = $this->user($request);

        if (($notice->sent_by['id'] ?? null) !== (string) $user->getKey() && ! $access->holdsAt($user, 'notices.view', 'school')) {
            throw new ApiException('permission_denied', 'Only the sender and the school office can see who read a notice.', 403);
        }

        return response()->json(['data' => $this->notices->receipts($notice)]);
    }

    public function attachmentLink(Request $request, string $id, string $attachment): JsonResponse
    {
        $notice = Notice::query()->findOrFail($id);

        return response()->json(['data' => [
            'url' => $this->notices->attachmentLink($this->user($request), $notice, $attachment),
            'expires_in' => (int) config('communication.notices.link_minutes', 5) * 60,
        ]]);
    }

    /** Served only through a signed link (middleware "signed"); the link was issued after a permission check. */
    public function download(string $tenant, string $notice, string $attachment, TenantContext $context): Response
    {
        return $context->run($tenant, function () use ($notice, $attachment): Response {
            $record = Notice::query()->find($notice) ?? throw new ApiException('not_found', 'The record was not found.', 404);
            $file = collect($record->attachments)->firstWhere('id', $attachment) ?? throw new ApiException('not_found', 'The record was not found.', 404);
            $disk = Storage::disk('local');

            if (! $disk->exists($file['path'])) {
                throw new ApiException('not_found', 'The record was not found.', 404);
            }

            return response()->download($disk->path($file['path']), $file['name'], [
                'Content-Type' => $file['mime'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
