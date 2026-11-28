<?php

namespace App\Modules\Privacy\Models;

use App\Support\Models\SchoolModel;

/**
 * A version of the school's privacy notice (DPDP). Drafts can be edited;
 * publishing gives it the next version number and makes it the one shown
 * to everyone. A published version is never changed again: a change is a
 * new version. Consents record the version the parent saw.
 *
 * @property int|null $version null while a draft
 * @property string $title
 * @property string $body plain text, paragraphs separated by blank lines
 * @property string $status draft | published | replaced
 * @property string|null $summary_of_changes
 * @property string|null $published_at
 * @property array{id: string, name: string}|null $published_by
 */
class PrivacyNotice extends SchoolModel
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const REPLACED = 'replaced';

    protected $table = 'privacy_notices';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'version', 'title', 'body', 'status', 'published_at', 'published_by', 'summary_of_changes'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT, 'version' => null, 'published_at' => null, 'published_by' => null, 'summary_of_changes' => null];

    public function auditCategory(): string
    {
        return 'security';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public static function current(): ?self
    {
        return self::query()->where('status', self::PUBLISHED)->orderBy('version', 'desc')->first();
    }
}
