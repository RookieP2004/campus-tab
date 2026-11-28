<?php

namespace App\Modules\Privacy\Domain;

use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Models\User;
use App\Modules\Privacy\Models\PrivacyNotice;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Versioned privacy notices. One draft at a time; publishing numbers it
 * (1, 2, 3 …), marks the previous version replaced, and tells every user
 * in the app that the notice changed. A published version never changes.
 */
class Notices
{
    public function __construct(private readonly TenantContext $tenant, private readonly Notifier $notifier) {}

    /** @param array{title: string, body: string, summary_of_changes?: string|null} $input */
    public function saveDraft(array $input): PrivacyNotice
    {
        $draft = PrivacyNotice::query()->where('status', PrivacyNotice::DRAFT)->first() ?? new PrivacyNotice;
        $draft->fill(['title' => trim($input['title']), 'body' => trim($input['body']), 'summary_of_changes' => $input['summary_of_changes'] ?? null, 'status' => PrivacyNotice::DRAFT])->save();

        return $draft;
    }

    public function publish(User $user): PrivacyNotice
    {
        $draft = PrivacyNotice::query()->where('status', PrivacyNotice::DRAFT)->first()
            ?? throw new ApiException('no_draft', 'Write the new version first, then publish it.', 422);

        $published = DB::connection('mongodb')->transaction(function () use ($draft, $user): PrivacyNotice {
            $previous = PrivacyNotice::current();
            $next = ($previous instanceof PrivacyNotice ? (int) $previous->version : 0) + 1;
            $previous?->fill(['status' => PrivacyNotice::REPLACED])->save();
            $draft->fill([
                'status' => PrivacyNotice::PUBLISHED, 'version' => $next,
                'published_at' => now()->toIso8601String(), 'published_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            ])->save();

            return $draft;
        }, 5);

        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant) {
            $everyone = User::query()->where('status', User::STATUS_ACTIVE)->get();
            $this->notifier->notify($tenant, $everyone, 'privacy.notice', 'The privacy notice was updated (version '.$published->version.')', null, '/privacy', []);
        }

        return $published;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return PrivacyNotice::query()->orderBy('created_at', 'desc')->get()
            ->map(fn (PrivacyNotice $n) => self::present($n))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function present(PrivacyNotice $n): array
    {
        return [
            'id' => (string) $n->getKey(), 'version' => $n->version, 'status' => $n->status, 'title' => $n->title, 'body' => $n->body,
            'summary_of_changes' => $n->summary_of_changes, 'published_at' => $n->published_at, 'published_by' => $n->published_by['name'] ?? null,
        ];
    }
}
