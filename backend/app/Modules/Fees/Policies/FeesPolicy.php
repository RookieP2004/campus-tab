<?php

namespace App\Modules\Fees\Policies;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Fees\Domain\FeeAccess;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use Illuminate\Auth\Access\Response;

/**
 * Fees abilities (the blueprint's "Who can do what" table). A record
 * someone may not see does not exist for them (404).
 */
class FeesPolicy
{
    /** ability => the permissions (any of) that grant it */
    private const ABILITIES = [
        'setup' => ['fees.setup'],
        'collect' => ['fees.collect'],
        'office' => ['fees.dues_view:school'],
        'reports' => ['fees.reports'],
        'gateway-view' => ['fees.gateway_view', 'fees.gateway_edit'],
        'gateway-edit' => ['fees.gateway_edit'],
        'adjust-propose' => ['fees.adjust_propose', 'fees.adjust'],
        'adjust-any' => ['fees.adjust_propose', 'fees.adjust_approve', 'fees.adjust'],
        'cancel-request' => ['fees.receipt_cancel_request', 'fees.receipt_cancel_propose', 'fees.receipt_cancel_approve', 'fees.receipt_cancel'],
        'closing-confirm' => ['fees.closing_confirm'],
    ];

    public function __construct(private readonly AccessControl $access, private readonly FeeAccess $fees) {}

    public function check(User $user, string $ability): Response
    {
        foreach (self::ABILITIES[$ability] ?? [] as $permission) {
            [$name, $scope] = array_pad(explode(':', $permission, 2), 2, null);
            if ($scope !== null ? $this->access->holdsAt($user, $name, $scope) : $this->access->holds($user, $name)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to do this.', 'permission_denied');
    }

    public function viewStudent(User $user, Student $student): Response
    {
        return $this->fees->mayViewStudent($user, $student) ? Response::allow() : Response::denyAsNotFound('The record was not found.');
    }

    public function sectionStatus(User $user, Section $section): Response
    {
        return $this->fees->mayViewSectionStatus($user, $section) ? Response::allow() : Response::denyAsNotFound('The record was not found.');
    }
}
