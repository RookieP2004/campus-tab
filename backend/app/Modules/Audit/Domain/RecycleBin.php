<?php

namespace App\Modules\Audit\Domain;

use App\Modules\Identity\Domain\Authority;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PasswordToken;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

/**
 * Soft-deleted records: list, restore, and the Chief's permanent purge.
 *
 * Purge is the only real delete in the app. It needs records.purge (Chief
 * only), step-up MFA, the record to have been in the bin for 30 days, and
 * the record's exact name typed as confirmation. The audit log keeps only
 * that a purge happened (type, id, who, when), not the record's data.
 */
class RecycleBin
{
    public function __construct(
        private readonly Authority $authority,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $type = null): array
    {
        $items = [];

        foreach ($this->types() as $name => $class) {
            if ($type !== null && $type !== $name) {
                continue;
            }

            /** @var iterable<Model&Recyclable> $trashed */
            $trashed = $class::query()->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('deleted_at')->orderBy('deleted_at', 'desc')->limit(500)->get();

            foreach ($trashed as $model) {
                $items[] = $this->present($model);
            }
        }

        return $items;
    }

    public function restore(User $actor, string $type, string $id): Model
    {
        $model = $this->find($type, $id);
        $this->mustManage($actor, $model);

        if ($model instanceof User) {
            $model->forceFill(['status' => User::STATUS_ACTIVE]);
        }

        $model->restore();

        if ($model instanceof User && $model->hasRole(Role::Chief)) {
            $this->audit->record('chief.added', 'security', 'users', (string) $model->getKey(), meta: ['how' => 'restored']);
        }

        return $model;
    }

    public function purge(User $actor, string $type, string $id, string $confirmation): void
    {
        $model = $this->find($type, $id);
        $this->mustManage($actor, $model);

        $purgeAfter = $this->purgeAfter($model);

        if ($purgeAfter->isFuture()) {
            throw new ApiException('purge_too_early', 'A record can be purged only after 30 days in the recycle bin.', 409, [
                'purge_after' => $purgeAfter->toIso8601String(),
            ]);
        }

        if ($confirmation !== $model->recycleLabel()) {
            throw new ApiException('confirmation_mismatch', 'Type the exact name of the record to confirm it will be deleted for ever.', 422);
        }

        if ($model instanceof User) {
            $this->purgeUserLeftovers($model);
        }

        $model->forceDelete();
    }

    /**
     * @return Model&Recyclable
     */
    private function find(string $type, string $id): Model
    {
        $class = $this->types()[$type] ?? throw new ApiException('not_found', 'The record was not found.', 404);
        $model = $class::query()->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('deleted_at')->find($id);

        return $model instanceof Model && $model instanceof Recyclable
            ? $model
            : throw new ApiException('not_found', 'The record was not found.', 404);
    }

    private function mustManage(User $actor, Model $model): void
    {
        if ($model instanceof User && ! $this->authority->outranks($actor, $model)) {
            throw new ApiException('level_too_low', 'You can only manage people below your own level.', 403);
        }
    }

    /** A purged user's devices, tokens and password links go with them. */
    private function purgeUserLeftovers(User $user): void
    {
        $id = (string) $user->getKey();

        DeviceSession::query()->where('user_id', $id)->delete();
        PasswordToken::query()->where('user_id', $id)->delete();
        PersonalAccessToken::query()->where('tokenable_type', 'user')->where('tokenable_id', $id)->delete();
    }

    private function purgeAfter(Model $model): Carbon
    {
        /** @var Carbon $deletedAt */
        $deletedAt = $model->getAttribute('deleted_at');

        return $deletedAt->copy()->addDays((int) config('audit.purge_after_days', 30));
    }

    /**
     * @param  Model&Recyclable  $model
     * @return array<string, mixed>
     */
    private function present(Model $model): array
    {
        return [
            'type' => $model->recycleType(),
            'id' => (string) $model->getKey(),
            'label' => $model->recycleLabel(),
            'deleted_at' => $model->getAttribute('deleted_at')?->toIso8601String(),
            'purge_after' => $this->purgeAfter($model)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function types(): array
    {
        /** @var array<string, class-string<Model>> $types */
        $types = config('audit.recyclable', []);

        return $types;
    }
}
