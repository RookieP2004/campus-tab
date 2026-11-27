<?php

namespace App\Modules\Communication\Domain;

use App\Modules\Communication\Jobs\PublishNotice;
use App\Modules\Communication\Models\Notice;
use App\Modules\Communication\Models\NoticeRead;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Sending, listing and reading notices. Attachments are kept privately
 * under tenants/<tenant_id>/notices/<notice_id>/ and handed out only as
 * short-lived signed links, after checking the person may read the notice.
 */
class Notices
{
    public function __construct(
        private readonly NoticeAudience $audience,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{type: string, refs: list<string>}  $audience
     * @param  list<UploadedFile>  $files
     */
    public function send(User $sender, string $title, string $body, array $audience, array $files): Notice
    {
        $scope = $this->audience->assertMaySend($sender, $audience);

        $notice = Notice::create([
            'title' => $title,
            'body' => $body,
            'audience' => ['type' => $audience['type'], 'refs' => array_values(array_unique($audience['refs']))],
            'sent_by' => ['id' => (string) $sender->getKey(), 'name' => $sender->name],
            'sent_scope' => $scope,
            'published_at' => now(),
        ]);

        $attachments = [];
        foreach ($files as $file) {
            $id = (string) Str::ulid();
            $extension = mb_strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
            $path = $file->storeAs('tenants/'.$this->context->require().'/notices/'.$notice->getKey(), $id.'.'.$extension, 'local');
            $attachments[] = [
                'id' => $id,
                'name' => self::safeName($file->getClientOriginalName()),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'path' => (string) $path,
            ];
        }

        $notice->forceFill([
            'attachments' => $attachments,
            'recipient_count' => $this->audience->recipients($notice)->count(),
        ])->save();

        PublishNotice::dispatch($this->context->require(), (string) $notice->getKey());

        return $notice;
    }

    /**
     * Recent notices this person may read, newest first, with a read flag.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user, int $limit = 100): array
    {
        $notices = Notice::query()
            ->where('published_at', '>=', now()->subDays((int) config('communication.notices.list_days', 120)))
            ->orderBy('published_at', 'desc')
            ->limit(500)
            ->get()
            ->filter(fn (Notice $n) => $this->audience->reaches($user, $n))
            ->take($limit)
            ->values();

        $read = NoticeRead::query()->where('user_id', (string) $user->getKey())
            ->whereIn('notice_id', $notices->map(fn (Notice $n) => (string) $n->getKey())->all())
            ->pluck('notice_id')->all();

        return $notices->map(fn (Notice $n) => array_merge(self::present($n), ['read' => in_array((string) $n->getKey(), $read, true)]))->all();
    }

    public function open(User $user, Notice $notice): void
    {
        if (! $this->audience->reaches($user, $notice)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        $exists = NoticeRead::query()->where('notice_id', (string) $notice->getKey())->where('user_id', (string) $user->getKey())->exists();

        if (! $exists) {
            NoticeRead::create(['notice_id' => (string) $notice->getKey(), 'user_id' => (string) $user->getKey(), 'read_at' => now()]);
        }
    }

    /** A download link for one attachment, valid a few minutes. */
    public function attachmentLink(User $user, Notice $notice, string $attachmentId): string
    {
        if (! $this->audience->reaches($user, $notice)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        $attachment = collect($notice->attachments)->firstWhere('id', $attachmentId)
            ?? throw new ApiException('not_found', 'The record was not found.', 404);

        return URL::temporarySignedRoute('files.notice-attachment', now()->addMinutes((int) config('communication.notices.link_minutes', 5)), [
            'tenant' => $this->context->require(),
            'notice' => (string) $notice->getKey(),
            'attachment' => $attachment['id'],
        ], absolute: false);
    }

    /**
     * @return array{recipient_count: int, read_count: int, readers: list<array{name: string, read_at: string}>}
     */
    public function receipts(Notice $notice): array
    {
        $reads = NoticeRead::query()->where('notice_id', (string) $notice->getKey())->orderBy('read_at')->get();
        $names = User::query()->whereIn('_id', $reads->pluck('user_id')->all())->get()->mapWithKeys(fn (User $u) => [(string) $u->getKey() => $u->name]);

        return [
            'recipient_count' => $notice->recipient_count,
            'read_count' => $reads->count(),
            'readers' => $reads->take(500)->map(fn (NoticeRead $r) => ['name' => (string) ($names[$r->user_id] ?? '—'), 'read_at' => $r->read_at->toIso8601String()])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Notice $n): array
    {
        return [
            'id' => (string) $n->getKey(),
            'title' => $n->title,
            'body' => $n->body,
            'audience' => $n->audience,
            'sent_by' => $n->sent_by['name'] ?? null,
            'sent_by_id' => $n->sent_by['id'] ?? null,
            'published_at' => $n->published_at->toIso8601String(),
            'attachments' => array_map(fn (array $a) => ['id' => $a['id'], 'name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size']], $n->attachments),
            'recipient_count' => $n->recipient_count,
        ];
    }

    private static function safeName(string $name): string
    {
        $clean = preg_replace('/[^\pL\pN ._()-]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($clean) ?: 'file', 0, 120);
    }
}
