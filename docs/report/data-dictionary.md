# Data dictionary

Every collection in MongoDB, what each field means, which module owns it and who writes it. The target model (about 45 collections) is in "Data model" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md); this page lists what exists in code.

## Rules for every school collection (from Phase 1)

- Every document carries `tenant_id` (set by the `BelongsToTenant` trait from the signed-in user), `created_by`, `updated_by`, `created_at`, `updated_at` and `deleted_at` (soft delete).
- Every index starts with `tenant_id`.
- Small bounded lists are embedded (marks inside a paper, attendance entries inside a day); anything that grows gets its own collection.
- Full Aadhaar numbers are never stored.

## Databases

| Database | Used by |
| --- | --- |
| `campus_tab` | Local development (`MONGODB_DATABASE`) |
| `campus_tab_test` | Tests; dropped and recreated before every database test |

## Collections in code (generated)

`users` is a placeholder so Laravel's sign-in config has a MongoDB model; Phase 1 replaces it with the Identity module's tenant-scoped users.

<!-- generated:collections:start -->
<!-- Written by `php artisan report:generate`. Do not edit by hand. -->

| Collection | Model | Fillable fields | Flags |
| --- | --- | --- | --- |
| `academic_years` | [`AcademicYear`](../../backend/app/Modules/AcademicStructure/Models/AcademicYear.php) | `tenant_id`, `name`, `starts_on`, `ends_on`, `status` | tenant-scoped, soft deletes |
| `assessment_schemes` | [`AssessmentScheme`](../../backend/app/Modules/Assessment/Models/AssessmentScheme.php) | `tenant_id`, `year_id`, `name`, `grades`, `terms`, `components`, `grade_scale`, `rules`, `preset`, `status` | tenant-scoped, soft deletes |
| `assessments` | [`Assessment`](../../backend/app/Modules/Assessment/Models/Assessment.php) | `tenant_id`, `year_id`, `kind`, `type`, `term`, `name`, `component`, `from`, `to`, `entry_closes_at`, `created_by` | tenant-scoped, soft deletes |
| `attendance_days` | [`AttendanceDay`](../../backend/app/Modules/Attendance/Models/AttendanceDay.php) | `tenant_id`, `section_id`, `year_id`, `date`, `period`, `entries`, `marked_by`, `marked_at`, `source`, `late_entry`, `notified_absent`, `wing` | tenant-scoped, soft deletes |
| `audit_logs` | [`AuditLog`](../../backend/app/Modules/Audit/Models/AuditLog.php) | `tenant_id`, `actor`, `actor_id`, `support_grant_id`, `action`, `category`, `entity`, `entity_id`, `before`, `after`, `redacted`, `meta`, `ip`, `device` | tenant-scoped |
| `calendar_events` | [`CalendarEvent`](../../backend/app/Modules/Attendance/Models/CalendarEvent.php) | `tenant_id`, `date`, `end_date`, `type`, `title`, `status`, `created_by`, `decided_by`, `decision_note` | tenant-scoped, soft deletes |
| `classes` | [`SchoolClass`](../../backend/app/Modules/AcademicStructure/Models/SchoolClass.php) | `tenant_id`, `year_id`, `grade`, `name`, `order`, `wing` | tenant-scoped, soft deletes |
| `consents` | [`Consent`](../../backend/app/Modules/People/Models/Consent.php) | `tenant_id`, `student_id`, `guardian_id`, `purpose`, `notice_version`, `given`, `at`, `recorded_by` | tenant-scoped, soft deletes |
| `correction_requests` | [`CorrectionRequest`](../../backend/app/Modules/Assessment/Models/CorrectionRequest.php) | `tenant_id`, `paper_id`, `assessment_id`, `student_id`, `student_name`, `section_id`, `label`, `old`, `new`, `reason`, `requested_by`, `requested_by_id`, `status`, `decided_by`, `decision_reason`, `decided_at`, `self_approved` | tenant-scoped, soft deletes |
| `custom_fields` | [`CustomField`](../../backend/app/Modules/Customisation/Models/CustomField.php) | `tenant_id`, `entity`, `key`, `label`, `type`, `options`, `required`, `sensitive`, `scope`, `owner_level`, `visible_to`, `status`, `order`, `owner` | tenant-scoped, soft deletes |
| `device_sessions` | [`DeviceSession`](../../backend/app/Modules/Identity/Models/DeviceSession.php) | `tenant_id`, `user_id`, `kind`, `token_id`, `session_hash`, `name`, `user_agent`, `ip`, `method`, `limited`, `last_seen_at`, `step_up_at`, `revoked_at`, `revoked_reason` | tenant-scoped |
| `enrolments` | [`Enrolment`](../../backend/app/Modules/People/Models/Enrolment.php) | `tenant_id`, `student_id`, `year_id`, `section_id`, `roll_no`, `result`, `custom` | tenant-scoped, soft deletes |
| `guardians` | [`Guardian`](../../backend/app/Modules/People/Models/Guardian.php) | `tenant_id`, `name`, `relation`, `phone`, `email`, `student_ids`, `user_id` | tenant-scoped, soft deletes |
| `import_batches` | [`ImportBatch`](../../backend/app/Modules/People/Models/ImportBatch.php) | `tenant_id`, `entity`, `status`, `file_path`, `file_name`, `progress`, `summary`, `rows`, `created_by`, `result`, `error` | tenant-scoped, soft deletes |
| `leave_balance_locks` | [`LeaveBalanceLock`](../../backend/app/Modules/Leave/Models/LeaveBalanceLock.php) | `tenant_id`, `staff_id`, `year_id`, `type_code`, `version` | tenant-scoped, soft deletes |
| `leave_delegations` | [`LeaveDelegation`](../../backend/app/Modules/Leave/Models/LeaveDelegation.php) | `tenant_id`, `delegate`, `delegate_id`, `by`, `from`, `to`, `ended_at`, `ended_by` | tenant-scoped, soft deletes |
| `leave_ledger` | [`LeaveLedgerEntry`](../../backend/app/Modules/Leave/Models/LeaveLedgerEntry.php) | `tenant_id`, `staff_id`, `year_id`, `type_code`, `kind`, `days`, `request_id`, `by` | tenant-scoped, soft deletes |
| `leave_requests` | [`LeaveRequest`](../../backend/app/Modules/Leave/Models/LeaveRequest.php) | `tenant_id`, `kind`, `applicant`, `applicant_user_id`, `applicant_staff_id`, `applicant_student_id`, `applied_by`, `applied_by_id`, `section_id`, `wing`, `type_code`, `type_name`, `year_id`, `from`, `to`, `half_day`, `days`, `reason`, `documents`, `status`, `stage`, `chain`, `steps`, `reserved_days`, `approved`, `cancel_request`, `stage_since`, `reminded_for`, `escalated_for`, `cover_needed`, `principal_sees`, `fast_tracked` | tenant-scoped, soft deletes |
| `leave_types` | [`LeaveType`](../../backend/app/Modules/Leave/Models/LeaveType.php) | `tenant_id`, `code`, `name`, `quota`, `sandwich`, `half_day`, `document_after_days`, `status` | tenant-scoped, soft deletes |
| `licences` | [`Licence`](../../backend/app/Modules/Licensing/Models/Licence.php) | `tenant_id`, `key_hash`, `key_last4`, `plan`, `modules`, `seats`, `valid_from`, `valid_to`, `status`, `payload`, `signature`, `activated_at`, `revoked_at` | — |
| `notice_reads` | [`NoticeRead`](../../backend/app/Modules/Communication/Models/NoticeRead.php) | `tenant_id`, `notice_id`, `user_id`, `read_at` | tenant-scoped |
| `notices` | [`Notice`](../../backend/app/Modules/Communication/Models/Notice.php) | `tenant_id`, `title`, `body`, `audience`, `attachments`, `sent_by`, `sent_scope`, `recipient_count`, `published_at` | tenant-scoped, soft deletes |
| `notifications` | [`Notification`](../../backend/app/Modules/Communication/Models/Notification.php) | `tenant_id`, `user_id`, `type`, `title`, `body`, `link`, `data`, `read_at` | tenant-scoped |
| `papers` | [`Paper`](../../backend/app/Modules/Assessment/Models/Paper.php) | `tenant_id`, `assessment_id`, `year_id`, `section_id`, `section_label`, `subject_id`, `subject_name`, `wing`, `class_test`, `max_marks`, `date`, `status`, `version`, `marks`, `published_at`, `locked`, `history`, `returned_note` | tenant-scoped, soft deletes |
| `password_tokens` | [`PasswordToken`](../../backend/app/Modules/Identity/Models/PasswordToken.php) | `tenant_id`, `user_id`, `purpose`, `token_hash`, `expires_at`, `used_at`, `created_by` | tenant-scoped |
| `personal_access_tokens` | [`PersonalAccessToken`](../../backend/app/Modules/Identity/Models/PersonalAccessToken.php) | `name`, `token`, `abilities`, `expires_at` | — |
| `platform_admins` | [`PlatformAdmin`](../../backend/app/Modules/Identity/Models/PlatformAdmin.php) | `name`, `email`, `password`, `mfa`, `status` | — |
| `platform_alerts` | [`PlatformAlert`](../../backend/app/Modules/Platform/Models/PlatformAlert.php) | `school_id`, `school_code`, `metric`, `count`, `baseline`, `message`, `raised_at`, `acknowledged_at`, `acknowledged_by` | — |
| `platform_audit_logs` | [`PlatformAuditLog`](../../backend/app/Modules/Audit/Models/PlatformAuditLog.php) | `actor`, `actor_id`, `action`, `school_id`, `school_code`, `entity`, `entity_id`, `before`, `after`, `redacted`, `meta`, `ip`, `device` | — |
| `platform_sessions` | [`PlatformSession`](../../backend/app/Modules/Platform/Models/PlatformSession.php) | `admin_id`, `session_hash`, `user_agent`, `ip`, `method`, `limited`, `last_seen_at`, `step_up_at`, `revoked_at`, `revoked_reason` | — |
| `push_devices` | [`PushDevice`](../../backend/app/Modules/Communication/Models/PushDevice.php) | `tenant_id`, `user_id`, `token`, `token_hash`, `platform` | tenant-scoped |
| `roles` | [`SchoolRole`](../../backend/app/Modules/Identity/Models/SchoolRole.php) | `tenant_id`, `name`, `label`, `level`, `duty`, `permissions`, `seeded_permissions`, `is_system` | tenant-scoped, soft deletes |
| `school_clients` | [`SchoolClient`](../../backend/app/Modules/Platform/Models/SchoolClient.php) | `school_id`, `contact_person`, `phone`, `email`, `address`, `package_name`, `price`, `billing_period`, `paid_until`, `notes` | — |
| `school_configs` | [`SchoolConfig`](../../backend/app/Modules/Tenancy/Models/SchoolConfig.php) | `tenant_id`, `version`, `body`, `diff`, `changed_by`, `reason`, `rolled_back_from` | tenant-scoped |
| `school_payments` | [`SchoolPayment`](../../backend/app/Modules/Platform/Models/SchoolPayment.php) | `school_id`, `amount`, `paid_on`, `method`, `reference`, `period_from`, `period_to`, `note`, `recorded_by` | — |
| `school_stats` | [`SchoolStats`](../../backend/app/Modules/Platform/Models/SchoolStats.php) | `school_id`, `computed_at`, `usage`, `security`, `grants` | — |
| `sections` | [`Section`](../../backend/app/Modules/AcademicStructure/Models/Section.php) | `tenant_id`, `year_id`, `class_id`, `name`, `label`, `wing`, `class_teacher_id`, `room` | tenant-scoped, soft deletes |
| `staff_attendance` | [`StaffAttendance`](../../backend/app/Modules/Attendance/Models/StaffAttendance.php) | `tenant_id`, `staff_id`, `date`, `status`, `in`, `out`, `source`, `inside_area`, `late`, `entered_by`, `reason`, `leave_request_id`, `replaced` | tenant-scoped, soft deletes |
| `staff` | [`Staff`](../../backend/app/Modules/People/Models/Staff.php) | `tenant_id`, `employee_code`, `name`, `designation`, `type`, `email`, `phone`, `joined_on`, `user_id`, `wings`, `status`, `requested_roles`, `drafted_by`, `custom`, `import_batch_id` | tenant-scoped, soft deletes |
| `students` | [`Student`](../../backend/app/Modules/People/Models/Student.php) | `tenant_id`, `admission_no`, `name`, `dob`, `gender`, `guardian_ids`, `ids`, `custom`, `user_id`, `login_name`, `status`, `import_batch_id` | tenant-scoped, soft deletes |
| `subjects` | [`Subject`](../../backend/app/Modules/AcademicStructure/Models/Subject.php) | `tenant_id`, `code`, `name`, `kind`, `elective` | tenant-scoped, soft deletes |
| `support_grants` | [`SupportGrant`](../../backend/app/Modules/Identity/Models/SupportGrant.php) | `tenant_id`, `granted_by`, `agent_id`, `agent_email`, `agent_name`, `scope`, `read_only`, `reason`, `starts_at`, `expires_at`, `revoked_at`, `revoked_by` | tenant-scoped, soft deletes |
| `teaching_assignments` | [`TeachingAssignment`](../../backend/app/Modules/AcademicStructure/Models/TeachingAssignment.php) | `tenant_id`, `year_id`, `section_id`, `subject_id`, `teacher_id` | tenant-scoped, soft deletes |
| `tenants` | [`Tenant`](../../backend/app/Modules/Tenancy/Models/Tenant.php) | `code`, `name`, `group_id`, `status`, `config_version`, `contact` | — |
| `users` | [`User`](../../backend/app/Modules/Identity/Models/User.php) | `tenant_id`, `name`, `email`, `phone`, `username`, `password`, `roles`, `mfa`, `status`, `language`, `theme`, `notification_prefs` | tenant-scoped, soft deletes |

<!-- generated:collections:end -->

## Collections written by packages

| Collection | Written by | What it holds |
| --- | --- | --- |
| `migrations` | Laravel | Which migrations (collection and index definitions) have run |
| `failed_jobs` | Laravel queue | Jobs that failed after all retries |
| `job_batches` | Laravel queue | Batched job progress (from Phase 2 imports) |
