<?php

namespace App\Modules\People\Models;

use App\Support\Models\SchoolModel;

/**
 * A parent or guardian. Siblings share one guardian record (student_ids),
 * so turning on parent login gives one account for all of them.
 *
 * @property string $name
 * @property string|null $relation father | mother | guardian | other
 * @property string|null $phone E.164, e.g. +919800000000
 * @property string|null $email
 * @property list<string> $student_ids
 * @property string|null $user_id the Parent account, when parent login is on
 */
class Guardian extends SchoolModel
{
    protected $table = 'guardians';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'relation', 'phone', 'email', 'student_ids', 'user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['student_ids' => [], 'user_id' => null, 'relation' => null, 'email' => null];
}
