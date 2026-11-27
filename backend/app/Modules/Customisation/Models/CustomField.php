<?php

namespace App\Modules\Customisation\Models;

use App\Support\Models\SchoolModel;

/**
 * A custom field (a column on a list, a field on a form). Every field has an
 * owner level and a scope; people change what sits at or below their level.
 *
 *   entity  student (values in students.custom) | enrolment (values in
 *           enrolments.custom, so they reset each year) | staff
 *   scope   {level: school|wing|section|subject_section, ref: null|wing|section id|"<section>:<subject>"}
 *
 * Removing a field archives it: values stay, hidden. Full Aadhaar or bank
 * numbers can never be a field.
 *
 * @property string $entity
 * @property string $key
 * @property array<string, string> $label e.g. {en: "Seat row", hi: "…"}
 * @property string $type
 * @property list<string> $options
 * @property bool $required
 * @property bool $sensitive
 * @property array{level: string, ref: string|null} $scope
 * @property int $owner_level
 * @property list<string> $visible_to extra roles that may see it (e.g. subject_teacher)
 * @property string $status active | archived
 * @property int $order
 * @property array{id: string, name: string}|null $owner
 */
class CustomField extends SchoolModel
{
    public const ENTITIES = ['student', 'enrolment', 'staff'];

    public const TYPES = ['text', 'long_text', 'number', 'date', 'dropdown', 'multi_select', 'yes_no', 'file', 'grade', 'phone', 'email'];

    public const SCOPE_LEVELS = ['school', 'wing', 'section', 'subject_section'];

    protected $table = 'custom_fields';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'entity', 'key', 'label', 'type', 'options', 'required', 'sensitive', 'scope',
        'owner_level', 'visible_to', 'status', 'order', 'owner',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'options' => [], 'required' => false, 'sensitive' => false, 'visible_to' => [], 'status' => 'active', 'order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'sensitive' => 'boolean', 'owner_level' => 'integer', 'order' => 'integer'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
