<?php

namespace App\Modules\Identity\Domain;

/**
 * The ten system roles and their levels ("Role hierarchy and authority").
 * A user can manage only people below their own level. Phase 1 part B adds
 * the roles collection and the permission matrix on top of these levels.
 */
enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case Chief = 'chief';
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case Clerk = 'clerk';
    case ClassTeacher = 'class_teacher';
    case SubjectTeacher = 'subject_teacher';
    case Staff = 'staff';
    case Parent = 'parent';
    case Student = 'student';

    /** MFA is mandatory from this level up (Clerk and above). */
    public const MFA_REQUIRED_FROM = 60;

    public function level(): int
    {
        return match ($this) {
            self::PlatformAdmin => 100,
            self::Chief => 90,
            self::Principal => 80,
            self::VicePrincipal => 70,
            self::Clerk => 60,
            self::ClassTeacher => 50,
            self::SubjectTeacher => 40,
            self::Staff => 30,
            self::Parent => 20,
            self::Student => 10,
        };
    }
}
