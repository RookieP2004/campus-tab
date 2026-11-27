<?php

namespace App\Modules\Leave\Models;

use App\Support\Models\SchoolModel;

/**
 * The Principal hands the final leave decision to a Vice Principal for set
 * dates. It ends on its own after `to`, or when the Principal ends it. A
 * delegate never decides leave for anyone at or above their own level, nor
 * their own (LeaveRouting).
 *
 * @property array{id: string, name: string} $delegate user
 * @property array{id: string, name: string} $by
 * @property string $from Y-m-d
 * @property string $to Y-m-d
 * @property string|null $ended_at
 * @property array{id: string, name: string}|null $ended_by
 */
class LeaveDelegation extends SchoolModel
{
    protected $table = 'leave_delegations';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'delegate', 'delegate_id', 'by', 'from', 'to', 'ended_at', 'ended_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['ended_at' => null, 'ended_by' => null];

    public function auditCategory(): string
    {
        return 'admin';
    }

    public function activeOn(string $date): bool
    {
        return $this->ended_at === null && $this->from <= $date && $this->to >= $date;
    }
}
