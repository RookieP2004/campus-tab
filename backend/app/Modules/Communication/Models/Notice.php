<?php

namespace App\Modules\Communication\Models;

use App\Support\Models\SchoolModel;
use Illuminate\Support\Carbon;

/**
 * A notice to an audience in the school.
 *
 *   audience  {type: school | class | section | role, refs: [...ids or role names]}
 *   attachments  [{id, name, mime, size, path}] stored privately under
 *             tenants/<tenant_id>/notices/, served only by short-lived
 *             signed links after a permission check.
 *
 * Who sees it is worked out on the server (NoticeAudience); read receipts
 * are separate records (NoticeRead).
 *
 * @property string $title
 * @property string $body
 * @property array{type: string, refs: list<string>} $audience
 * @property list<array{id: string, name: string, mime: string, size: int, path: string}> $attachments
 * @property array{id: string, name: string} $sent_by
 * @property string $sent_scope the scope the sender used (school, wing, class, subject)
 * @property int $recipient_count
 * @property Carbon $published_at
 */
class Notice extends SchoolModel
{
    public const AUDIENCE_TYPES = ['school', 'class', 'section', 'role'];

    protected $table = 'notices';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'title', 'body', 'audience', 'attachments', 'sent_by', 'sent_scope', 'recipient_count', 'published_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['attachments' => [], 'recipient_count' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'recipient_count' => 'integer'];
    }

    public function auditCategory(): string
    {
        return 'admin';
    }
}
