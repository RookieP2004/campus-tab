<?php

namespace App\Modules\People\Models;

use App\Support\Models\SchoolModel;

/**
 * A guardian's consent for one purpose, for one student (DPDP; PEN and
 * APAAR are kept only with a given consent). A change is a new record; the
 * newest per purpose counts.
 *
 * @property string $student_id
 * @property string|null $guardian_id
 * @property string $purpose pen | apaar | photo_public | third_party_sharing
 * @property string $notice_version
 * @property bool $given
 * @property string $at ISO time
 * @property array{id: string, name: string}|null $recorded_by
 */
class Consent extends SchoolModel
{
    public const PURPOSES = ['pen', 'apaar', 'photo_public', 'third_party_sharing'];

    protected $table = 'consents';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'student_id', 'guardian_id', 'purpose', 'notice_version', 'given', 'at', 'recorded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['given' => 'boolean'];
    }

    public static function latestGiven(string $studentId, string $purpose): bool
    {
        $latest = self::query()->where('student_id', $studentId)->where('purpose', $purpose)->orderBy('created_at', 'desc')->first();

        return $latest instanceof self && $latest->given;
    }
}
