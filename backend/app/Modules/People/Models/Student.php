<?php

namespace App\Modules\People\Models;

use App\Modules\Audit\Domain\Recyclable;
use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Support\Models\SchoolModel;

/**
 * A student's master record. The year's class and section live on the
 * enrolment. PEN and APAAR are optional and kept only with a consent record;
 * of an Aadhaar number only the last four digits may be kept.
 *
 * @property string $admission_no
 * @property string $name
 * @property string $dob Y-m-d
 * @property string $gender female | male | other
 * @property list<string> $guardian_ids
 * @property array{pen?: string|null, apaar?: string|null, aadhaar_last4?: string|null} $ids
 * @property array<string, mixed> $custom school- and wing-wide custom values
 * @property string|null $user_id a student sign-in account, when the school allows it
 * @property string|null $login_name
 * @property string $status active | left
 * @property string|null $import_batch_id
 */
class Student extends SchoolModel implements AccessSubject, Recyclable
{
    protected $table = 'students';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'admission_no', 'name', 'dob', 'gender', 'guardian_ids', 'ids', 'custom',
        'user_id', 'login_name', 'status', 'import_batch_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['guardian_ids' => [], 'ids' => [], 'custom' => [], 'status' => 'active', 'user_id' => null, 'login_name' => null];

    /** Filled by the people service: this year's enrolment and its wing. */
    public ?Enrolment $currentEnrolment = null;

    public ?string $currentWing = null;

    public function accessFacts(): array
    {
        return [
            'owner_ids' => is_string($this->user_id) ? [$this->user_id] : [],
            'student_ids' => [(string) $this->getKey()],
            'section_id' => $this->currentEnrolment?->section_id,
            'wing' => $this->currentWing,
        ];
    }

    public function recycleType(): string
    {
        return 'student';
    }

    public function recycleLabel(): string
    {
        return $this->name.' ('.$this->admission_no.')';
    }
}
