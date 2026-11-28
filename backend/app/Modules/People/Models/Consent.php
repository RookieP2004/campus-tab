<?php

namespace App\Modules\People\Models;

use App\Support\Models\SchoolModel;

/**
 * A parent's consent for one purpose, for one child (DPDP). A change is a
 * new record; the newest per purpose counts.
 *
 * Since Phase 9 only a consent given by the parent from their own signed-in
 * account counts (method parent_account): that is the verified parental
 * consent for a child's data (owner's rule). Older records ticked by the
 * office (no method) are kept as history and shown as "needs the parent's
 * confirmation"; they no longer count.
 *
 * @property string $student_id
 * @property string|null $guardian_id
 * @property string|null $user_id the parent account that gave it
 * @property string $purpose pen | apaar | photo_public | third_party_sharing
 * @property string $notice_version the privacy notice version the parent saw
 * @property bool $given
 * @property string $at ISO time
 * @property string|null $method parent_account (counts) | null (office, before Phase 9)
 * @property array{id: string, name: string}|null $recorded_by
 */
class Consent extends SchoolModel
{
    public const PURPOSES = ['pen', 'apaar', 'photo_public', 'third_party_sharing'];

    public const PARENT_ACCOUNT = 'parent_account';

    protected $table = 'consents';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'student_id', 'guardian_id', 'user_id', 'purpose', 'notice_version', 'given', 'at', 'method', 'recorded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['given' => 'boolean'];
    }

    /** Whether the parent's own, newest answer for this purpose is yes. */
    public static function latestGiven(string $studentId, string $purpose): bool
    {
        $latest = self::query()->where('student_id', $studentId)->where('purpose', $purpose)->where('method', self::PARENT_ACCOUNT)->orderBy('created_at', 'desc')->first();

        return $latest instanceof self && $latest->given;
    }

    /** An office-ticked consent with no answer from the parent yet. */
    public static function needsParent(string $studentId, string $purpose): bool
    {
        $any = self::query()->where('student_id', $studentId)->where('purpose', $purpose)->orderBy('created_at', 'desc')->first();

        return $any instanceof self && $any->method !== self::PARENT_ACCOUNT
            && ! self::query()->where('student_id', $studentId)->where('purpose', $purpose)->where('method', self::PARENT_ACCOUNT)->exists();
    }
}
