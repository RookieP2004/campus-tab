<?php

namespace App\Modules\Customisation\Domain;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Support\Str;

/**
 * Defining custom fields. Removing a field archives it (values stay,
 * hidden); a higher level can promote a useful class column to school-wide.
 *
 * Refused outright: fields meant for Aadhaar or bank account numbers (by
 * name or label, in English or Hindi). Only the last four Aadhaar digits may
 * be kept, in the student's own Aadhaar (last 4) field.
 */
class CustomFields
{
    /** Words that mark a field as an Aadhaar or bank number field. */
    private const FORBIDDEN = [
        'aadhaar', 'aadhar', 'adhaar', 'adhar', 'aadhaar no', 'uidai', 'uid', 'आधार',
        'bank account', 'account number', 'account no', 'a/c', 'acct', 'ifsc', 'bank number', 'बैंक खाता', 'खाता संख्या',
    ];

    public function __construct(private readonly FieldRules $rules) {}

    /**
     * @param  array{entity: string, label: array<string, string>, type: string, options?: list<string>, required?: bool, sensitive?: bool, scope: array{level: string, ref?: string|null}, visible_to?: list<string>}  $input
     */
    public function define(User $actor, array $input): CustomField
    {
        $scope = ['level' => $input['scope']['level'], 'ref' => $input['scope']['level'] === 'school' ? null : ($input['scope']['ref'] ?? null)];
        $this->assertScopeRef($input['entity'], $scope);
        self::refuseAadhaarOrBank($input['label'], $input['type']);

        $ownerLevel = $this->rules->ownerLevelFor($actor, $scope);
        $sameScope = CustomField::query()->where('entity', $input['entity'])->where('scope.level', $scope['level'])->where('scope.ref', $scope['ref']);

        if ((clone $sameScope)->where('status', 'active')->count() >= FieldRules::MAX_PER_SCOPE) {
            throw new ApiException('custom_field_limit', 'This list already has '.FieldRules::MAX_PER_SCOPE.' fields. Archive one first.', 422);
        }

        $labelEn = trim($input['label']['en'] ?? '');

        if ((clone $sameScope)->where('status', 'active')->get()->contains(fn (CustomField $f) => mb_strtolower($f->label['en'] ?? '') === mb_strtolower($labelEn))) {
            throw new ApiException('custom_field_duplicate', 'A field with this name already exists here.', 422);
        }

        if (in_array($input['type'], ['dropdown', 'multi_select'], true) && count($input['options'] ?? []) === 0) {
            throw new ApiException('custom_field_options_required', 'Add the choices for this list.', 422);
        }

        return CustomField::create([
            'entity' => $input['entity'],
            'key' => $this->uniqueKey($input['entity'], $labelEn),
            'label' => array_filter($input['label'], fn ($v) => is_string($v) && trim($v) !== ''),
            'type' => $input['type'],
            'options' => array_values(array_unique($input['options'] ?? [])),
            'required' => (bool) ($input['required'] ?? false),
            'sensitive' => (bool) ($input['sensitive'] ?? false),
            'scope' => $scope,
            'owner_level' => $ownerLevel,
            'visible_to' => array_values(array_intersect($input['visible_to'] ?? [], ['subject_teacher', 'class_teacher'])),
            'status' => 'active',
            'order' => (int) CustomField::query()->where('entity', $input['entity'])->count(),
            'owner' => ['id' => (string) $actor->getKey(), 'name' => $actor->name],
        ]);
    }

    /**
     * @param  array{label?: array<string, string>, options?: list<string>, required?: bool, visible_to?: list<string>, order?: int}  $changes
     */
    public function change(User $actor, CustomField $field, array $changes): CustomField
    {
        $this->mustChange($actor, $field);

        if (isset($changes['label'])) {
            self::refuseAadhaarOrBank($changes['label'], $field->type);
        }

        if (isset($changes['visible_to'])) {
            $changes['visible_to'] = array_values(array_intersect($changes['visible_to'], ['subject_teacher', 'class_teacher']));
        }

        $field->fill($changes)->save();

        return $field;
    }

    public function archive(User $actor, CustomField $field, bool $archived = true): CustomField
    {
        $this->mustChange($actor, $field);
        $field->fill(['status' => $archived ? 'archived' : 'active'])->save();

        return $field;
    }

    /** A class (or wing, or subject) column becomes school-wide; owned by the promoter. */
    public function promote(User $actor, CustomField $field): CustomField
    {
        $this->mustChange($actor, $field);
        $scope = ['level' => 'school', 'ref' => null];

        if ($field->scope['level'] === 'school') {
            throw new ApiException('custom_field_already_school', 'This field is already school-wide.', 422);
        }

        // Only a school-wide field manager (Principal, Chief) may promote.
        $level = $this->rules->ownerLevelFor($actor, $scope);

        if ($level < 80) {
            throw new ApiException('custom_field_scope_forbidden', 'Only the Principal or Chief Admin can make a field school-wide.', 403);
        }

        $field->fill(['scope' => $scope, 'owner_level' => $level, 'visible_to' => []])->save();

        return $field;
    }

    public function mustChange(User $actor, CustomField $field): void
    {
        if (! $this->rules->canChange($actor, $field)) {
            throw new ApiException('custom_field_level', 'This field belongs to a higher level or another scope.', 403);
        }
    }

    /**
     * @param  array<string, string>  $label
     */
    public static function refuseAadhaarOrBank(array $label, string $type): void
    {
        if (in_array(mb_strtolower($type), ['aadhaar', 'aadhar', 'bank_account', 'bank'], true)) {
            throw self::forbidden();
        }

        foreach ($label as $text) {
            $plain = ' '.mb_strtolower(preg_replace('/[_\-.]+/', ' ', (string) $text) ?? '').' ';

            foreach (self::FORBIDDEN as $word) {
                if (str_contains($plain, ' '.$word.' ') || (mb_strlen($word) > 4 && str_contains($plain, $word))) {
                    throw self::forbidden();
                }
            }
        }
    }

    private static function forbidden(): ApiException
    {
        return new ApiException(
            'custom_field_forbidden',
            'Aadhaar and bank account numbers cannot be custom fields. Keep only the last four Aadhaar digits, in the student\'s Aadhaar (last 4) field.',
            422,
        );
    }

    /**
     * @param  array{level: string, ref: string|null}  $scope
     */
    private function assertScopeRef(string $entity, array $scope): void
    {
        $ok = match ($scope['level']) {
            'school' => true,
            'wing' => is_string($scope['ref']) && $scope['ref'] !== '' && $entity !== 'staff',
            'section' => is_string($scope['ref']) && $entity === 'enrolment' && Section::query()->whereKey($scope['ref'])->exists(),
            'subject_section' => is_string($scope['ref']) && $entity === 'enrolment' && str_contains($scope['ref'], ':')
                && TeachingAssignment::query()->where('section_id', strtok($scope['ref'], ':'))->where('subject_id', substr($scope['ref'], strpos($scope['ref'], ':') + 1))->exists(),
            default => false,
        };

        if (! $ok) {
            throw new ApiException('custom_field_scope_invalid', 'Section and subject columns are on the year\'s enrolment and need an existing section or subject-section.', 422);
        }
    }

    private function uniqueKey(string $entity, string $label): string
    {
        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(30, '')->value() ?: 'field';
        $key = $base;
        $n = 2;

        while (CustomField::query()->withTrashed()->where('entity', $entity)->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }
}
