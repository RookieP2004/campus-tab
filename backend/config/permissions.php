<?php

/*
| The permission catalogue and the default matrix ("Permission matrix" in
| docs/BLUEPRINT.md).
|
| A permission is "module.action:scope", for example marks.enter:subject.
| 'abilities' lists every module.action the code knows and the scopes it may
| take, widest first. A role can hold only strings built from this list, so no
| setting can create a permission the code does not define (for example,
| there is no "edit locked marks" or "unlock marks" ability: a locked mark
| changes only through a correction the Principal approves, owner's decision
| in Phase 6).
|
| 'roles' is what each school starts with. The Chief can edit it, within the
| guard rules (no escalation, the Chief role is fixed). Abilities for modules
| built in later phases are listed now so the default roles are complete.
*/

return [

    // Widest first. "child" is a parent's children and stands on its own.
    'scopes' => ['school', 'wing', 'class', 'subject', 'own', 'child'],

    'abilities' => [
        // Settings, roles, users (Phase 1)
        'config.view' => ['school'],
        'config.edit' => ['school'],
        'config.edit_policies' => ['school'],
        'roles.view' => ['school'],
        'roles.edit' => ['school'],
        'users.view' => ['school'],
        'users.create' => ['school'],
        'users.draft' => ['school'],
        'users.edit_roles' => ['school'],
        'users.deactivate' => ['school'],
        'users.unlock' => ['school'],
        'users.send_password_link' => ['school'],
        'users.reset_mfa' => ['school'],
        'records.restore' => ['school'],
        'records.purge' => ['school'],
        'audit.view' => ['school', 'own'],
        'audit.view_academic' => ['school'],
        'support.view' => ['school'],
        'support.grant' => ['school'],
        'export.bulk' => ['school'],
        'export.academic' => ['school'],
        'export.lists' => ['school'],
        'export.class_list' => ['class'],
        'export.own_data' => ['own', 'child'],

        // Academic structure (Phase 2): years, classes, sections, subjects, teaching assignments
        'academic.view' => ['school'],
        'academic.manage' => ['school'],

        // People (Phase 2)
        'students.view' => ['school', 'wing', 'class', 'subject', 'own', 'child'],
        // edit at class scope is limited: roll number and the section's own columns
        'students.edit' => ['school', 'wing', 'class'],
        'students.admit' => ['school'],
        'students.request_edit' => ['child'],
        // one-time setup codes for student sign-in (a school setting, off by default)
        'students.login_codes' => ['school', 'wing', 'class'],
        'guardians.manage' => ['school'],
        'staff.view' => ['school'],
        'staff.create' => ['school'],
        // the Clerk's "draft for principal": the account is made only on approval
        'staff.draft' => ['school'],
        'staff.approve' => ['school'],
        'staff.edit' => ['school'],
        'staff.deactivate' => ['school'],
        'imports.students' => ['school'],
        'imports.staff' => ['school'],

        // Attendance (Phase 4)
        'attendance.view' => ['school', 'wing', 'class', 'subject', 'own', 'child'],
        'attendance.mark' => ['school', 'wing', 'class', 'subject'],
        'attendance.correct' => ['school'],
        'attendance.edit_after_window' => ['school', 'wing'],
        'attendance.request_edit' => ['class', 'subject'],
        'staff_attendance.manage' => ['school'],
        'staff_attendance.mark' => ['own'],
        // Entering other staff's attendance (the Attendance Officer duty).
        'staff_attendance.enter' => ['school'],
        'staff_attendance.view' => ['school', 'own'],
        // Holiday calendar (Phase 4): the Clerk keeps it, the Principal approves.
        'calendar.view' => ['school'],
        'calendar.manage' => ['school'],
        'calendar.approve' => ['school'],

        // Leave (Phase 5)
        'leave.apply' => ['own'],
        // The leave calendar: who is away (never the type or the reason).
        'leave.view' => ['school'],
        // Leave types, quotas, the sandwich rule and documents (the Principal).
        'leave.manage_types' => ['school'],
        // Hand the final leave decision to a Vice Principal for set dates.
        'leave.delegate' => ['school'],
        'leave.verify' => ['school'],
        'leave.fast_track' => ['school', 'wing'],
        'leave.decide' => ['school', 'wing'],
        'leave.decide_principal' => ['school'],
        'student_leave.apply' => ['child'],
        'student_leave.approve' => ['school', 'wing', 'class'],

        // Tests and marks (Phase 6), report cards (Phase 7)
        'assessment.scheme' => ['school'],
        'assessment.setup' => ['school'],
        'exams.schedule' => ['school'],
        'tests.create' => ['school', 'wing', 'class', 'subject'],
        'marks.enter' => ['school', 'wing', 'class', 'subject'],
        'marks.verify' => ['school', 'wing', 'class'],
        'marks.lock' => ['school', 'wing'],
        'marks.correction_request' => ['school', 'class', 'subject'],
        'marks.correction_approve' => ['school'],
        'results.publish' => ['school', 'wing'],
        'results.view' => ['school', 'own', 'child'],
        'remarks.edit' => ['school', 'wing', 'class', 'subject'],
        // Report cards (Phase 7): the template designer, re-issue (the Principal
        // only, with a reason) and the year-end promotion wizard.
        'report_templates.manage' => ['school'],
        'report_cards.reissue' => ['school'],
        'year.promote' => ['school'],

        // Customisation and notices (Phases 2 and 3)
        'custom_fields.manage' => ['school', 'wing', 'class', 'subject'],
        'custom_fields.clerical' => ['school'],
        'notices.send' => ['school', 'wing', 'class', 'subject'],
        'notices.view' => ['school', 'own'],

        // Fees (Phase 8)
        'fees.gateway_view' => ['school'],
        'fees.gateway_edit' => ['school'],
        'fees.setup' => ['school'],
        'fees.collect' => ['school'],
        'fees.adjust' => ['school'],
        'fees.adjust_approve' => ['school'],
        'fees.adjust_propose' => ['school'],
        'fees.receipt_cancel' => ['school'],
        'fees.receipt_cancel_approve' => ['school'],
        'fees.receipt_cancel_propose' => ['school'],
        'fees.receipt_cancel_request' => ['school'],
        'fees.dues_view' => ['school', 'class', 'own', 'child'],
        'fees.pay_online' => ['child'],
        'fees.reports' => ['school', 'own'],
        // Confirming someone else's day-end cash closing (never your own; a Clerk's only by the Principal or Chief).
        'fees.closing_confirm' => ['school'],

        // Privacy, DPDP (Phase 9): everyone's own page (own; a parent also for
        // their children), the notice editor, the requests inbox, erasing a
        // child's details, retention runs and the incident log.
        'privacy.self' => ['own', 'child'],
        'privacy.notices' => ['school'],
        'privacy.requests' => ['school'],
        'privacy.erase' => ['school'],
        'privacy.retention' => ['school'],
        'privacy.incidents' => ['school'],
    ],

    /*
    | The system roles each school starts with: nine levels plus two duties,
    | Fee Collector and Attendance Officer. A duty has no level, the Principal
    | gives it, and it may hold only the abilities listed under 'duties'.
    | Platform Admin is not a school role and has no column.
    */
    'roles' => [
        'chief' => [
            'label' => 'Chief Admin',
            'level' => 90,
            'permissions' => ['privacy.self:own', 'privacy.notices:school', 'privacy.requests:school', 'privacy.erase:school', 'privacy.retention:school', 'privacy.incidents:school',
                'config.view:school', 'config.edit:school', 'config.edit_policies:school',
                'roles.view:school', 'roles.edit:school',
                'users.view:school', 'users.create:school', 'users.edit_roles:school', 'users.deactivate:school',
                'users.unlock:school', 'users.send_password_link:school', 'users.reset_mfa:school',
                'records.restore:school', 'records.purge:school',
                'audit.view:school', 'support.view:school', 'support.grant:school', 'export.bulk:school',
                'students.view:school', 'students.edit:school', 'students.admit:school', 'students.login_codes:school', 'guardians.manage:school',
                'academic.view:school', 'academic.manage:school',
                'staff.view:school', 'staff.create:school', 'staff.approve:school', 'staff.edit:school', 'staff.deactivate:school',
                'imports.students:school', 'imports.staff:school',
                'attendance.view:school', 'attendance.mark:school', 'attendance.edit_after_window:school', 'staff_attendance.manage:school',
                'staff_attendance.enter:school', 'staff_attendance.view:school', 'staff_attendance.mark:own', 'calendar.view:school', 'calendar.manage:school', 'calendar.approve:school',
                'leave.apply:own', 'leave.view:school', 'leave.decide_principal:school', 'student_leave.approve:school',
                'assessment.scheme:school', 'exams.schedule:school', 'tests.create:school',
                'marks.enter:school', 'marks.verify:school', 'marks.lock:school', 'marks.correction_request:school',
                'results.publish:school', 'results.view:school', 'remarks.edit:school',
                'report_templates.manage:school', 'year.promote:school',
                'custom_fields.manage:school', 'notices.send:school', 'notices.view:school',
                'fees.gateway_view:school', 'fees.gateway_edit:school', 'fees.setup:school', 'fees.collect:school',
                'fees.adjust:school', 'fees.receipt_cancel:school', 'fees.dues_view:school', 'fees.reports:school', 'fees.closing_confirm:school',
            ],
        ],
        'principal' => [
            'label' => 'Principal',
            'level' => 80,
            'permissions' => ['privacy.self:own', 'privacy.requests:school', 'privacy.incidents:school',
                'config.view:school', 'config.edit_policies:school', 'roles.view:school',
                'users.view:school', 'users.create:school', 'users.edit_roles:school', 'users.deactivate:school',
                'users.unlock:school', 'users.send_password_link:school', 'users.reset_mfa:school', 'records.restore:school',
                'audit.view_academic:school', 'audit.view:own', 'export.academic:school',
                'students.view:school', 'students.edit:school', 'students.admit:school', 'students.login_codes:school', 'guardians.manage:school',
                'academic.view:school', 'academic.manage:school',
                'staff.view:school', 'staff.create:school', 'staff.approve:school', 'staff.edit:school', 'staff.deactivate:school',
                'imports.students:school', 'imports.staff:school',
                'attendance.view:school', 'attendance.mark:school', 'attendance.edit_after_window:school', 'staff_attendance.manage:school',
                'staff_attendance.enter:school', 'staff_attendance.view:school', 'staff_attendance.mark:own', 'calendar.view:school', 'calendar.approve:school',
                'leave.apply:own', 'leave.view:school', 'leave.manage_types:school', 'leave.delegate:school', 'leave.fast_track:school', 'leave.decide:school', 'student_leave.approve:school',
                'assessment.scheme:school', 'exams.schedule:school', 'tests.create:school',
                'marks.enter:school', 'marks.verify:school', 'marks.lock:school', 'marks.correction_request:school', 'marks.correction_approve:school',
                'results.publish:school', 'results.view:school', 'remarks.edit:school',
                'report_templates.manage:school', 'report_cards.reissue:school', 'year.promote:school',
                'custom_fields.manage:school', 'notices.send:school', 'notices.view:school',
                'fees.gateway_view:school', 'fees.setup:school', 'fees.collect:school', 'fees.adjust_approve:school',
                'fees.receipt_cancel_approve:school', 'fees.dues_view:school', 'fees.reports:school', 'fees.closing_confirm:school',
            ],
        ],
        'vice_principal' => [
            'label' => 'Vice Principal',
            'level' => 70,
            'permissions' => ['privacy.self:own',
                'users.view:school', 'audit.view:own',
                'students.view:wing', 'students.edit:wing', 'students.login_codes:wing', 'academic.view:school', 'staff.view:school',
                'attendance.view:wing', 'attendance.mark:wing', 'attendance.edit_after_window:wing',
                'staff_attendance.enter:school', 'staff_attendance.mark:own', 'calendar.view:school',
                'leave.apply:own', 'leave.view:school', 'leave.fast_track:wing', 'leave.decide:wing', 'student_leave.approve:wing',
                'tests.create:wing', 'marks.enter:wing', 'marks.verify:wing', 'marks.lock:wing',
                'results.publish:wing', 'remarks.edit:wing', 'custom_fields.manage:wing',
                'notices.send:wing', 'notices.view:school',
            ],
        ],
        'clerk' => [
            'label' => 'Clerk',
            'level' => 60,
            'permissions' => ['privacy.self:own',
                'users.view:school', 'users.draft:school', 'audit.view:own', 'export.lists:school',
                'students.view:school', 'students.edit:school', 'students.admit:school', 'guardians.manage:school',
                'academic.view:school', 'staff.view:school', 'staff.draft:school', 'imports.students:school',
                'attendance.view:school', 'attendance.correct:school', 'attendance.edit_after_window:school', 'staff_attendance.manage:school',
                'staff_attendance.view:school', 'staff_attendance.mark:own', 'calendar.view:school', 'calendar.manage:school',
                'leave.apply:own', 'leave.view:school', 'leave.verify:school',
                'assessment.setup:school', 'exams.schedule:school', 'tests.create:school',
                'marks.enter:school', 'marks.lock:school', 'marks.correction_request:school',
                'custom_fields.clerical:school', 'notices.send:school', 'notices.view:school',
                'fees.setup:school', 'fees.collect:school', 'fees.adjust_propose:school', 'fees.receipt_cancel_propose:school',
                'fees.dues_view:school', 'fees.reports:school', 'fees.closing_confirm:school',
            ],
        ],
        'class_teacher' => [
            'label' => 'Class Teacher',
            'level' => 50,
            'permissions' => ['privacy.self:own',
                'students.view:class', 'students.edit:class', 'students.login_codes:class', 'academic.view:school',
                'attendance.view:class', 'attendance.mark:class', 'attendance.request_edit:class', 'staff_attendance.mark:own', 'calendar.view:school',
                'leave.apply:own', 'leave.view:school', 'student_leave.approve:class',
                'tests.create:class', 'marks.enter:class', 'marks.verify:class', 'marks.correction_request:class',
                'remarks.edit:class', 'custom_fields.manage:class', 'notices.send:class', 'notices.view:own',
                'export.class_list:class', 'fees.dues_view:class',
            ],
        ],
        'subject_teacher' => [
            'label' => 'Subject Teacher',
            'level' => 40,
            'permissions' => ['privacy.self:own',
                'students.view:subject', 'academic.view:school',
                'attendance.view:subject', 'attendance.mark:subject', 'attendance.request_edit:subject', 'staff_attendance.mark:own', 'calendar.view:school',
                'leave.apply:own', 'leave.view:school', 'tests.create:subject', 'marks.enter:subject', 'marks.correction_request:subject',
                'remarks.edit:subject', 'custom_fields.manage:subject', 'notices.send:subject', 'notices.view:own',
            ],
        ],
        'staff' => [
            'label' => 'Staff',
            'level' => 30,
            'permissions' => ['privacy.self:own', 'staff_attendance.mark:own', 'leave.apply:own', 'leave.view:school', 'notices.view:own', 'calendar.view:school'],
        ],
        'parent' => [
            'label' => 'Parent / Guardian',
            'level' => 20,
            'permissions' => ['privacy.self:child',
                'students.view:child', 'students.request_edit:child', 'attendance.view:child', 'student_leave.apply:child',
                'results.view:child', 'notices.view:own', 'export.own_data:child', 'calendar.view:school', 'fees.dues_view:child', 'fees.pay_online:child',
            ],
        ],
        'student' => [
            'label' => 'Student',
            'level' => 10,
            'permissions' => ['privacy.self:own', 'students.view:own', 'attendance.view:own', 'results.view:own', 'notices.view:own', 'export.own_data:own', 'calendar.view:school'],
        ],
        'fee_collector' => [
            'label' => 'Fee Collector',
            'level' => null,
            'duty' => 'fee_collector',
            'permissions' => ['fees.collect:school', 'fees.receipt_cancel_request:school', 'fees.dues_view:school', 'fees.reports:own'],
        ],
        // Phase 4 rule: nobody can enter or change their own attendance through this duty.
        'attendance_officer' => [
            'label' => 'Attendance Officer',
            'level' => null,
            'duty' => 'attendance_officer',
            'permissions' => ['staff_attendance.enter:school', 'staff_attendance.view:school'],
        ],
    ],

    /*
    | What each duty may ever hold. A duty has no level and adds only these
    | rights; the role editor refuses anything else, whoever asks.
    */
    'duties' => [
        'fee_collector' => ['fees.*'],
        'attendance_officer' => ['staff_attendance.enter', 'staff_attendance.view'],
    ],

];
