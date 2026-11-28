<?php

namespace App\Modules\People\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use Illuminate\Database\Eloquent\Collection;

/**
 * The sign-in accounts of the given students and their guardians: who to
 * tell when something about a child changes (a report card, a receipt).
 */
class Families
{
    /**
     * @param  list<string>  $studentIds
     * @return Collection<int, User>
     */
    public function users(array $studentIds): Collection
    {
        $students = Student::query()->whereIn('_id', $studentIds)->get();
        $ids = $students->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all();
        $guardianIds = $students->flatMap(fn (Student $s) => $s->guardian_ids ?? [])->unique()->values()->all();
        $ids = [...$ids, ...Guardian::query()->whereIn('_id', $guardianIds)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (string) $id)->all()];

        return User::query()->whereIn('_id', array_values(array_unique($ids)))->get();
    }
}
