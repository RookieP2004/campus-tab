<?php

namespace App\Modules\Customisation\Policies;

use App\Modules\Customisation\Domain\FieldRules;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Custom fields. Seeing the list needs a custom-field or a people
 * permission; defining one needs custom_fields.manage or .clerical (the
 * scope and level are checked in FieldRules); changing one needs the
 * owner level and scope.
 */
class CustomFieldPolicy
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly FieldRules $rules,
    ) {}

    public function viewAny(User $actor): Response
    {
        foreach (['custom_fields.manage', 'custom_fields.clerical', 'students.view', 'staff.view'] as $ability) {
            if ($this->access->holds($actor, $ability)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to do this.', 'permission_denied');
    }

    public function create(User $actor): Response
    {
        return $this->access->holds($actor, 'custom_fields.manage') || $this->access->holds($actor, 'custom_fields.clerical')
            ? Response::allow()
            : Response::deny('You do not have permission to do this.', 'permission_denied');
    }

    public function update(User $actor, CustomField $field): Response
    {
        return $this->rules->canChange($actor, $field)
            ? Response::allow()
            : Response::deny('This field belongs to a higher level or another scope.', 'custom_field_level');
    }
}
