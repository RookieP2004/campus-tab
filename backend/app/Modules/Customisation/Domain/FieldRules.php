<?php

namespace App\Modules\Customisation\Domain;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Support\Collection;

/**
 * Who may define, change, see and fill each custom field
 * ("Customisation engine"). Everything runs on the server.
 *
 *  Define:  a field gets the definer's level as owner_level, at a scope
 *           their custom_fields permission covers:
 *             school           custom_fields.manage:school (Chief, Principal), or
 *                              custom_fields.clerical:school (Clerk, clerical fields)
 *             wing W           manage:school, or manage:wing with W one of their wings
 *             section S        manage:school, manage:wing (S in their wing), or
 *                              manage:class with S one of their sections
 *             subject_section  as section, or manage:subject for that subject-section
 *  Change:  the same scope rule, and their level is at least the field's
 *           owner_level (a higher level may change any lower field).
 *  See:     school fields: everyone who can see the record. Others: whoever
 *           may change the field, plus subject teachers of that section when
 *           visible_to lists subject_teacher. So a class-only column is seen
 *           by its class teacher (and those above), never by a subject teacher.
 *  Fill:    whoever may change it, and for school and wing fields anyone who
 *           may edit the student at that scope.
 */
class FieldRules
{
    public const MAX_PER_SCOPE = 50;

    /** @var array<string, string|null> */
    private array $sectionWings = [];

    public function __construct(private readonly AccessControl $access) {}

    /**
     * The owner level a new field at this scope gets, or a 403.
     *
     * @param  array{level: string, ref: string|null}  $scope
     */
    public function ownerLevelFor(User $user, array $scope): int
    {
        if ($this->scopeAllowed($user, $scope)) {
            $schoolWide = in_array('school', $this->access->scopesFor($user, 'custom_fields.manage'), true);

            // A Clerk's school-wide field is a clerical field, owned at level 60.
            return $scope['level'] === 'school' && ! $schoolWide ? min($user->level(), 60) : $user->level();
        }

        throw new ApiException('custom_field_scope_forbidden', 'You can add fields only for your own level and scope.', 403);
    }

    public function canChange(User $user, CustomField $field): bool
    {
        return $user->level() >= $field->owner_level && $this->scopeAllowed($user, $field->scope, $field->owner_level);
    }

    public function isVisible(User $user, CustomField $field): bool
    {
        if (! $field->isActive()) {
            return $this->canChange($user, $field);
        }

        if ($field->scope['level'] === 'school') {
            return true;
        }

        if ($this->canChange($user, $field)) {
            return true;
        }

        if ($field->scope['level'] === 'wing') {
            return in_array($field->scope['ref'], $user->accessScope()['wings'], true);
        }

        if ($field->scope['level'] === 'section' && in_array('subject_teacher', $field->visible_to, true)) {
            $section = (string) $field->scope['ref'];

            foreach ($user->accessScope()['subject_sections'] as $taught) {
                if (str_starts_with($taught, $section.':')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canFill(User $user, CustomField $field): bool
    {
        if (! $field->isActive()) {
            return false;
        }

        if ($this->canChange($user, $field)) {
            return true;
        }

        return match ($field->scope['level']) {
            'school' => $this->access->holdsAt($user, 'students.edit', 'school') || ($field->entity === 'staff' && $this->access->allows($user, 'staff.edit')),
            'wing' => $this->access->holdsAt($user, 'students.edit', 'school')
                || ($this->access->holdsAt($user, 'students.edit', 'wing') && in_array($field->scope['ref'], $user->accessScope()['wings'], true)),
            default => false,
        };
    }

    /**
     * Active fields of these entities the user may see. With a section, only
     * fields that apply there (school, its wing, the section, its subjects).
     *
     * @param  list<string>  $entities
     * @return Collection<int, CustomField>
     */
    public function visibleFields(User $user, array $entities, ?string $sectionId = null): Collection
    {
        $wing = $sectionId === null ? null : $this->wingOf($sectionId);

        return CustomField::query()
            ->whereIn('entity', $entities)
            ->where('status', 'active')
            ->orderBy('order')
            ->get()
            ->filter(function (CustomField $f) use ($user, $sectionId, $wing): bool {
                if ($sectionId !== null) {
                    $applies = match ($f->scope['level']) {
                        'school' => true,
                        'wing' => $f->scope['ref'] === $wing,
                        'section' => $f->scope['ref'] === $sectionId,
                        'subject_section' => str_starts_with((string) $f->scope['ref'], $sectionId.':'),
                        default => false,
                    };

                    if (! $applies) {
                        return false;
                    }
                }

                return $this->isVisible($user, $f);
            })
            ->values();
    }

    /**
     * Checks and normalises one value for its field type. Null clears it.
     */
    public function normalise(CustomField $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            if ($field->required) {
                throw $this->invalid($field, 'is required');
            }

            return null;
        }

        return match ($field->type) {
            'number' => is_numeric($value) ? $value + 0 : throw $this->invalid($field, 'must be a number'),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : throw $this->invalid($field, 'must be a date (YYYY-MM-DD)'),
            'yes_no' => is_bool($value) ? $value : (in_array($value, ['yes', 'no', '1', '0', 1, 0], true) ? in_array($value, ['yes', '1', 1], true) : throw $this->invalid($field, 'must be yes or no')),
            'dropdown' => is_string($value) && in_array($value, $field->options, true) ? $value : throw $this->invalid($field, 'must be one of the options'),
            'multi_select' => is_array($value) && array_diff($value, $field->options) === [] ? array_values($value) : throw $this->invalid($field, 'must use only the options'),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($value) : throw $this->invalid($field, 'must be an email'),
            'phone' => is_string($value) && preg_match('/^\+?\d{10,15}$/', $value) === 1 ? $value : throw $this->invalid($field, 'must be a phone number'),
            'long_text' => is_string($value) && mb_strlen($value) <= 2000 ? $value : throw $this->invalid($field, 'is too long'),
            default => is_scalar($value) && mb_strlen((string) $value) <= 200 ? (string) $value : throw $this->invalid($field, 'is too long'),
        };
    }

    /**
     * @param  array{level: string, ref: string|null}  $scope
     */
    private function scopeAllowed(User $user, array $scope, ?int $ownerLevel = null): bool
    {
        $mine = $user->accessScope();
        $held = $this->access->scopesFor($user, 'custom_fields.manage');
        $manage = fn (string $at): bool => in_array($at, $held, true);

        if ($manage('school')) {
            return true;
        }

        $ref = (string) $scope['ref'];

        return match ($scope['level']) {
            // Clerical fields: school-wide, owned at the Clerk's level.
            'school' => $this->access->holdsAt($user, 'custom_fields.clerical', 'school') && ($ownerLevel === null || $ownerLevel <= 60),
            'wing' => $manage('wing') && in_array($ref, $mine['wings'], true),
            'section' => ($manage('wing') && in_array($this->wingOf($ref), $mine['wings'], true))
                || ($manage('class') && in_array($ref, $mine['class_sections'], true)),
            'subject_section' => ($manage('wing') && in_array($this->wingOf(strtok($ref, ':') ?: ''), $mine['wings'], true))
                || ($manage('class') && in_array(strtok($ref, ':') ?: '', $mine['class_sections'], true))
                || ($manage('subject') && in_array($ref, $mine['subject_sections'], true)),
            default => false,
        };
    }

    private function wingOf(string $sectionId): ?string
    {
        if (! array_key_exists($sectionId, $this->sectionWings)) {
            $section = Section::query()->find($sectionId);
            $this->sectionWings[$sectionId] = $section instanceof Section ? $section->wing : null;
        }

        return $this->sectionWings[$sectionId];
    }

    private function invalid(CustomField $field, string $problem): ApiException
    {
        $label = $field->label['en'] ?? $field->key;

        return new ApiException('custom_value_invalid', "{$label} {$problem}.", 422, ['field' => $field->key]);
    }
}
