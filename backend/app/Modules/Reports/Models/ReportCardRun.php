<?php

namespace App\Modules\Reports\Models;

use App\Support\Models\SchoolModel;

/**
 * A background job over one section: "generate" (compute draft cards from
 * locked papers) or "publish" (freeze, render the PDFs with their QR codes,
 * notify families). The screen polls it for progress.
 *
 * @property string $kind generate | publish | reissue
 * @property string $section_id
 * @property string $year_id
 * @property string $status queued | running | done | failed
 * @property int $total
 * @property int $done
 * @property string|null $error
 * @property array{id: string, name: string} $requested_by
 * @property list<string> $card_ids
 * @property string|null $note the re-issue reason
 */
class ReportCardRun extends SchoolModel
{
    protected $table = 'report_card_runs';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'kind', 'section_id', 'year_id', 'status', 'total', 'done', 'error', 'requested_by', 'card_ids', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'queued', 'total' => 0, 'done' => 0, 'error' => null, 'card_ids' => [], 'note' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total' => 'integer', 'done' => 'integer'];
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['updated_at', 'done', 'card_ids'];
    }
}
