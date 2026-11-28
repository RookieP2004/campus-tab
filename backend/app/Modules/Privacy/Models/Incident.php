<?php

namespace App\Modules\Privacy\Models;

use App\Support\Models\SchoolModel;

/**
 * The incident log (breach runbook: docs/report/how-to/breach-runbook.md).
 * Every suspected personal data breach is written down here: what
 * happened, what data and how many people, and each step taken, including
 * when the Data Protection Board and the people affected were told.
 * Timeline entries are added, never rewritten.
 *
 * @property string $title
 * @property string $detected_at ISO time
 * @property string $severity low | medium | high
 * @property string $status open | contained | closed
 * @property string $description
 * @property list<string> $data_affected
 * @property int|null $people_affected
 * @property string|null $board_notified_at
 * @property string|null $people_notified_at
 * @property array{id: string, name: string} $reported_by
 * @property list<array{at: string, by: string, note: string}> $timeline
 */
class Incident extends SchoolModel
{
    public const SEVERITIES = ['low', 'medium', 'high'];

    public const STATUSES = ['open', 'contained', 'closed'];

    protected $table = 'privacy_incidents';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'title', 'detected_at', 'severity', 'status', 'description', 'data_affected', 'people_affected', 'board_notified_at', 'people_notified_at', 'reported_by', 'timeline'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'board_notified_at' => null, 'people_notified_at' => null, 'timeline' => []];

    public function auditCategory(): string
    {
        return 'security';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['people_affected' => 'integer'];
    }
}
