<?php

namespace App\Modules\Privacy\Domain;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Models\NoticeRead;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\People\Models\ImportBatch;
use App\Modules\Privacy\Models\RetentionRun;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Retention per kind of data (config privacy.retention). Two steps, never
 * one:
 *
 *  1. dryRun(): lists what is past its time (count, oldest, newest, the
 *     cut-off) and keeps the ids. Nothing changes. The daily job makes one
 *     for every kind that has something to remove and tells the Chief.
 *  2. confirm(): the Chief, with a fresh MFA code (step-up
 *     permanent_delete), removes exactly the records in that dry run that
 *     are still past the cut-off. A dry run older than 7 days must be made
 *     again.
 *
 * Files (register photos, leave documents, import files) are deleted from
 * storage; the record keeps a note that the file was removed under the
 * retention rules, so a closed year's records stay as they were. The audit
 * log and the fee ledger are never part of retention (decision 0017).
 */
class Retention
{
    public const VALID_DAYS = 7;

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys((array) config('privacy.retention'));
    }

    public function dryRun(string $kind, ?User $by): RetentionRun
    {
        $days = (int) (config("privacy.retention.{$kind}.days") ?? throw new ApiException('kind_unknown', 'That is not a kind of data with a retention rule.', 422));
        $cutoff = CarbonImmutable::now()->subDays($days);
        [$query, $dateField] = $this->candidates($kind, $cutoff);
        $rows = $query->get(['_id', $dateField]);
        $dates = $rows->map(fn (Model $m) => self::date($m->getAttribute($dateField)))->filter()->sort()->values();

        RetentionRun::query()->where('kind', $kind)->where('status', 'dry_run')->get()->each(fn (RetentionRun $r) => $r->fill(['status' => 'expired'])->save());

        return RetentionRun::create([
            'kind' => $kind, 'days' => $days, 'cutoff' => $cutoff->toIso8601String(), 'count' => $rows->count(),
            'ids' => $rows->map(fn (Model $m) => (string) $m->getKey())->values()->all(),
            'oldest' => $dates->first(), 'newest' => $dates->last(),
            'made_by' => $by instanceof User ? ['id' => (string) $by->getKey(), 'name' => $by->name] : null,
        ]);
    }

    public function confirm(User $user, RetentionRun $run): RetentionRun
    {
        if ($run->status !== 'dry_run') {
            throw new ApiException('run_not_open', 'This report was already used or replaced. Make a new dry run.', 409);
        }
        if ($run->created_at !== null && $run->created_at->lt(now()->subDays(self::VALID_DAYS))) {
            $run->fill(['status' => 'expired'])->save();
            throw new ApiException('run_expired', 'This report is more than '.self::VALID_DAYS.' days old. Make a new dry run and read it first.', 409);
        }

        [$query] = $this->candidates($run->kind, CarbonImmutable::parse($run->cutoff));
        $records = $query->whereIn('_id', $run->ids)->get();
        foreach ($records as $record) {
            $this->purge($run->kind, $record);
        }

        $run->fill(['status' => 'confirmed', 'deleted' => $records->count(), 'confirmed_by' => ['id' => (string) $user->getKey(), 'name' => $user->name], 'confirmed_at' => now()->toIso8601String()])->save();
        $this->audit->record('retention.purged', 'security', 'retention_run', (string) $run->getKey(), null, ['kind' => $run->kind, 'days' => $run->days, 'listed' => $run->count, 'deleted' => $records->count()]);

        return $run;
    }

    /** @return array{0: Builder<covariant Model>, 1: string} the records past the cut-off and the date field they are judged by */
    private function candidates(string $kind, CarbonImmutable $cutoff): array
    {
        return match ($kind) {
            'notifications' => [Notification::query()->whereNotNull('read_at')->where('read_at', '<', $cutoff), 'read_at'],
            'device_sessions' => [DeviceSession::query()->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff), 'revoked_at'],
            'notice_reads' => [NoticeRead::query()->where('read_at', '<', $cutoff), 'read_at'],
            'register_photos' => [AttendanceDay::query()->whereNotNull('late_entry.photo_path')->where('late_entry.photo_removed', '!=', true)->where('created_at', '<', $cutoff), 'created_at'],
            'leave_documents' => [LeaveRequest::query()->whereIn('status', ['approved', 'rejected', 'withdrawn', 'cancelled'])->where('documents.0', 'exists', true)->where('documents_removed', '!=', true)->where('updated_at', '<', $cutoff), 'updated_at'],
            'import_batches' => [ImportBatch::query()->where('created_at', '<', $cutoff), 'created_at'],
            default => throw new ApiException('kind_unknown', 'That is not a kind of data with a retention rule.', 422),
        };
    }

    private function purge(string $kind, Model $record): void
    {
        $disk = Storage::disk('local');
        switch ($kind) {
            case 'register_photos':
                /** @var AttendanceDay $record */
                $path = $record->late_entry['photo_path'] ?? null;
                if (is_string($path)) {
                    $disk->delete($path);
                }
                // Only the file goes; the record (and a closed year) stays as it was.
                AttendanceDay::query()->whereKey($record->getKey())->toBase()->update(['late_entry.photo_removed' => true]);
                break;
            case 'leave_documents':
                $documents = $record instanceof LeaveRequest ? $record->documents : [];
                foreach ($documents as $doc) {
                    $disk->delete($doc['path']);
                }
                LeaveRequest::query()->whereKey($record->getKey())->toBase()->update(['documents_removed' => true]);
                break;
            case 'import_batches':
                /** @var ImportBatch $record */
                if (is_string($record->file_path)) {
                    $disk->delete($record->file_path);
                }
                $record->forceDelete();
                break;
            default:
                // Past its time: gone for good (also models with a recycle bin).
                $record->forceDelete();
        }
    }

    private static function date(mixed $value): ?string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_string($value) => substr($value, 0, 10),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public static function present(RetentionRun $r): array
    {
        return [
            'id' => (string) $r->getKey(), 'kind' => $r->kind, 'label' => config("privacy.retention.{$r->kind}.label"), 'status' => $r->status,
            'days' => $r->days, 'cutoff' => $r->cutoff, 'count' => $r->count, 'oldest' => $r->oldest, 'newest' => $r->newest,
            'made_by' => $r->made_by['name'] ?? null, 'made_at' => $r->created_at?->toIso8601String(),
            'confirmed_by' => $r->confirmed_by['name'] ?? null, 'confirmed_at' => $r->confirmed_at, 'deleted' => $r->deleted,
        ];
    }
}
