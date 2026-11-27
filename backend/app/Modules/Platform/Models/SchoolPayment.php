<?php

namespace App\Modules\Platform\Models;

use App\Modules\Audit\Models\AuditLogBuilder;
use Illuminate\Support\Carbon;
use LogicException;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Query\Builder as QueryBuilder;

/**
 * One payment from a school, entered by hand in the console (platform
 * level). Append-only, like a ledger: a mistake is put right with a
 * correction entry (a negative amount and a note), never by editing.
 *
 * @property string $id
 * @property string $school_id
 * @property float|int $amount rupees; negative for a correction
 * @property string $paid_on Y-m-d
 * @property string $method bank_transfer | upi | cheque | cash | other
 * @property string|null $reference
 * @property string|null $period_from
 * @property string|null $period_to
 * @property string|null $note
 * @property array{id: string, name: string} $recorded_by
 * @property Carbon $at
 */
class SchoolPayment extends Model
{
    public const METHODS = ['bank_transfer', 'upi', 'cheque', 'cash', 'other'];

    public const UPDATED_AT = null;

    public const CREATED_AT = 'at';

    protected $connection = 'mongodb';

    protected $table = 'school_payments';

    /** @var list<string> */
    protected $fillable = ['school_id', 'amount', 'paid_on', 'method', 'reference', 'period_from', 'period_to', 'note', 'recorded_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments are append-only: record a correction instead.'));
        static::deleting(fn () => throw new LogicException('Payments are append-only: record a correction instead.'));
    }

    /**
     * @param  QueryBuilder  $query
     * @return AuditLogBuilder<static>
     */
    public function newEloquentBuilder($query): AuditLogBuilder
    {
        /** @var AuditLogBuilder<static> $builder */
        $builder = new AuditLogBuilder($query);

        return $builder;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'amount' => $this->amount,
            'paid_on' => $this->paid_on,
            'method' => $this->method,
            'reference' => $this->reference,
            'period_from' => $this->period_from,
            'period_to' => $this->period_to,
            'note' => $this->note,
            'recorded_by' => $this->recorded_by['name'] ?? null,
            'at' => $this->at->toIso8601String(),
        ];
    }
}
