<?php

namespace App\Modules\Platform\Models;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * An alert for our team: a security count at one school jumped (for example
 * a burst of failed sign-ins). Counts only; the details stay in the
 * school's own audit log.
 *
 * @property string $id
 * @property string $school_id
 * @property string $school_code
 * @property string $metric failed_sign_ins | lockouts | password_resets | mfa_resets | chief_changes
 * @property int $count in the last hour
 * @property float $baseline average per hour over the 7 days before
 * @property string $message
 * @property Carbon $raised_at
 * @property Carbon|null $acknowledged_at
 * @property array{id: string, name: string}|null $acknowledged_by
 */
class PlatformAlert extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'platform_alerts';

    /** @var list<string> */
    protected $fillable = ['school_id', 'school_code', 'metric', 'count', 'baseline', 'message', 'raised_at', 'acknowledged_at', 'acknowledged_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'school_code' => $this->school_code,
            'metric' => $this->metric,
            'count' => $this->count,
            'baseline' => round($this->baseline, 2),
            'message' => $this->message,
            'raised_at' => $this->raised_at->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $this->acknowledged_by['name'] ?? null,
        ];
    }
}
