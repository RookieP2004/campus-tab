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
| `cash_closings` | [`CashClosing`](../../backend/app/Modules/Fees/Models/CashClosing.php) | `tenant_id`, `collector`, `collector_id`, `date`, `payment_ids`, `totals`, `total_paise`, `cash_paise`, `counted_cash_paise`, `status`, `confirmed_by`, `confirmed_at`, `note` | tenant-scoped, soft deletes |
| `classes` | [`SchoolClass`](../../backend/app/Modules/AcademicStructure/Models/SchoolClass.php) | `tenant_id`, `year_id`, `grade`, `name`, `order`, `wing` | tenant-scoped, soft deletes |
| `consents` | [`Consent`](../../backend/app/Modules/People/Models/Consent.php) | `tenant_id`, `student_id`, `guardian_id`, `user_id`, `purpose`, `notice_version`, `given`, `at`, `method`, `recorded_by` | tenant-scoped, soft deletes |
| `correction_requests` | [`CorrectionRequest`](../../backend/app/Modules/Assessment/Models/CorrectionRequest.php) | `tenant_id`, `paper_id`, `assessment_id`, `student_id`, `student_name`, `section_id`, `label`, `old`, `new`, `reason`, `requested_by`, `requested_by_id`, `status`, `decided_by`, `decision_reason`, `decided_at`, `self_approved` | tenant-scoped, soft deletes |
| `custom_fields` | [`CustomField`](../../backend/app/Modules/Customisation/Models/CustomField.php) | `tenant_id`, `entity`, `key`, `label`, `type`, `options`, `required`, `sensitive`, `scope`, `owner_level`, `visible_to`, `status`, `order`, `owner` | tenant-scoped, soft deletes |
| `data_requests` | [`DataRequest`](../../backend/app/Modules/Privacy/Models/DataRequest.php) | `tenant_id`, `type`, `status`, `requested_by`, `requester_id`, `subject_type`, `student_id`, `subject_name`, `details`, `due_on`, `handled_by`, `outcome`, `completed_at`, `history` | tenant-scoped, soft deletes |
| `device_sessions` | [`DeviceSession`](../../backend/app/Modules/Identity/Models/DeviceSession.php) | `tenant_id`, `user_id`, `kind`, `token_id`, `session_hash`, `name`, `user_agent`, `ip`, `method`, `limited`, `last_seen_at`, `step_up_at`, `revoked_at`, `revoked_reason` | tenant-scoped |
| `enrolments` | [`Enrolment`](../../backend/app/Modules/People/Models/Enrolment.php) | `tenant_id`, `student_id`, `year_id`, `section_id`, `roll_no`, `result`, `custom` | tenant-scoped, soft deletes |
| `fee_adjustments` | [`FeeAdjustment`](../../backend/app/Modules/Fees/Models/FeeAdjustment.php) | `tenant_id`, `student_id`, `student_name`, `year_id`, `kind`, `demand_id`, `amount_paise`, `percent_bp`, `reason`, `status`, `proposed_by`, `decided_by`, `decision_reason`, `decided_at`, `source` | tenant-scoped, soft deletes |
| `fee_categories` | [`FeeCategory`](../../backend/app/Modules/Fees/Models/FeeCategory.php) | `tenant_id`, `name`, `code`, `status` | tenant-scoped, soft deletes |
| `fee_counters` | [`FeeCounter`](../../backend/app/Modules/Fees/Models/FeeCounter.php) | `tenant_id`, `key`, `value` | tenant-scoped, soft deletes |
| `fee_demands` | [`FeeDemand`](../../backend/app/Modules/Fees/Models/FeeDemand.php) | `tenant_id`, `student_id`, `student_name`, `year_id`, `section_id`, `section_label`, `plan_id`, `instalment_key`, `label`, `due_on`, `lines`, `discounts`, `total_paise`, `fine_rule`, `fine_posted_paise` | tenant-scoped, soft deletes |
| `fee_heads` | [`FeeHead`](../../backend/app/Modules/Fees/Models/FeeHead.php) | `tenant_id`, `name`, `code`, `status` | tenant-scoped, soft deletes |
| `fee_ledger` | [`FeeLedgerEntry`](../../backend/app/Modules/Fees/Models/FeeLedgerEntry.php) | `tenant_id`, `student_id`, `year_id`, `type`, `amount_paise`, `demand_id`, `head_id`, `payment_id`, `receipt_no`, `adjustment_id`, `adjustment_kind`, `reverses_id`, `reason`, `by` | tenant-scoped, soft deletes |
| `fee_plans` | [`FeePlan`](../../backend/app/Modules/Fees/Models/FeePlan.php) | `tenant_id`, `year_id`, `name`, `grades`, `category_ids`, `instalments`, `fine`, `discounts`, `status` | tenant-scoped, soft deletes |
| `fee_reconciliations` | [`FeeReconciliation`](../../backend/app/Modules/Fees/Models/FeeReconciliation.php) | `tenant_id`, `date`, `provider`, `matched`, `mismatches`, `status`, `error` | tenant-scoped, soft deletes |
| `fee_year_end_decisions` | [`FeeYearEndDecision`](../../backend/app/Modules/Fees/Models/FeeYearEndDecision.php) | `tenant_id`, `year_id`, `student_id`, `student_name`, `amount_paise`, `decided_by`, `decided_at`, `superseded_at` | tenant-scoped, soft deletes |
| `gateway_accounts` | [`GatewayAccount`](../../backend/app/Modules/Fees/Models/GatewayAccount.php) | `tenant_id`, `provider`, `key_id`, `key_secret`, `webhook_secret`, `status`, `updated_by` | tenant-scoped, soft deletes |
| `gateway_events` | [`GatewayEvent`](../../backend/app/Modules/Fees/Models/GatewayEvent.php) | `tenant_id`, `provider`, `gateway_payment_id`, `order_id`, `event`, `outcome`, `details` | tenant-scoped, soft deletes |
| `gateway_orders` | [`GatewayOrder`](../../backend/app/Modules/Fees/Models/GatewayOrder.php) | `tenant_id`, `provider`, `order_id`, `student_id`, `year_id`, `demand_ids`, `amount_paise`, `currency`, `status`, `gateway_payment_id`, `payment_id`, `receipt_no`, `created_by` | tenant-scoped, soft deletes |
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
| `payments` | [`Payment`](../../backend/app/Modules/Fees/Models/Payment.php) | `tenant_id`, `student_id`, `year_id`, `mode`, `amount_paise`, `reference`, `bank`, `status`, `collector`, `collector_id`, `online`, `allocations`, `receipt_id`, `receipt_no`, `received_on`, `closing_id`, `outcome` | tenant-scoped, soft deletes |
| `personal_access_tokens` | [`PersonalAccessToken`](../../backend/app/Modules/Identity/Models/PersonalAccessToken.php) | `name`, `token`, `abilities`, `expires_at` | — |
| `platform_admins` | [`PlatformAdmin`](../../backend/app/Modules/Identity/Models/PlatformAdmin.php) | `name`, `email`, `password`, `mfa`, `status` | — |
| `platform_alerts` | [`PlatformAlert`](../../backend/app/Modules/Platform/Models/PlatformAlert.php) | `school_id`, `school_code`, `metric`, `count`, `baseline`, `message`, `raised_at`, `acknowledged_at`, `acknowledged_by` | — |
| `platform_audit_logs` | [`PlatformAuditLog`](../../backend/app/Modules/Audit/Models/PlatformAuditLog.php) | `actor`, `actor_id`, `action`, `school_id`, `school_code`, `entity`, `entity_id`, `before`, `after`, `redacted`, `meta`, `ip`, `device` | — |
| `platform_sessions` | [`PlatformSession`](../../backend/app/Modules/Platform/Models/PlatformSession.php) | `admin_id`, `session_hash`, `user_agent`, `ip`, `method`, `limited`, `last_seen_at`, `step_up_at`, `revoked_at`, `revoked_reason` | — |
| `privacy_incidents` | [`Incident`](../../backend/app/Modules/Privacy/Models/Incident.php) | `tenant_id`, `title`, `detected_at`, `severity`, `status`, `description`, `data_affected`, `people_affected`, `board_notified_at`, `people_notified_at`, `reported_by`, `timeline` | tenant-scoped, soft deletes |
| `privacy_notices` | [`PrivacyNotice`](../../backend/app/Modules/Privacy/Models/PrivacyNotice.php) | `tenant_id`, `version`, `title`, `body`, `status`, `published_at`, `published_by`, `summary_of_changes` | tenant-scoped, soft deletes |
| `push_devices` | [`PushDevice`](../../backend/app/Modules/Communication/Models/PushDevice.php) | `tenant_id`, `user_id`, `token`, `token_hash`, `platform`, `device_session_id`, `last_seen_at` | tenant-scoped |
| `receipt_cancellations` | [`ReceiptCancellation`](../../backend/app/Modules/Fees/Models/ReceiptCancellation.php) | `tenant_id`, `receipt_id`, `receipt_no`, `payment_id`, `student_id`, `student_name`, `amount_paise`, `reason`, `status`, `requested_by`, `proposed_by`, `decided_by`, `decision_reason`, `decided_at` | tenant-scoped, soft deletes |
| `receipts` | [`Receipt`](../../backend/app/Modules/Fees/Models/Receipt.php) | `tenant_id`, `number`, `seq`, `year_id`, `payment_id`, `student_id`, `student_name`, `section_label`, `amount_paise`, `mode`, `lines`, `status`, `issued_by`, `issued_on` | tenant-scoped, soft deletes |
| `report_card_runs` | [`ReportCardRun`](../../backend/app/Modules/Reports/Models/ReportCardRun.php) | `tenant_id`, `kind`, `section_id`, `year_id`, `status`, `total`, `done`, `error`, `requested_by`, `card_ids`, `note` | tenant-scoped, soft deletes |
| `report_cards` | [`ReportCard`](../../backend/app/Modules/Reports/Models/ReportCard.php) | `tenant_id`, `year_id`, `year_name`, `student_id`, `student_name`, `student_user_id`, `section_id`, `section_label`, `wing`, `template_id`, `scheme_id`, `version`, `status`, `snapshot`, `remarks`, `co_scholastic`, `attendance`, `pdf`, `verify_hash`, `issued_at`, `published_by`, `reissue_reason`, `replaces_id`, `replaced_by_id`, `history` | tenant-scoped, soft deletes |
| `report_templates` | [`ReportTemplate`](../../backend/app/Modules/Reports/Models/ReportTemplate.php) | `tenant_id`, `name`, `grades`, `blocks`, `letterhead`, `preset` | tenant-scoped, soft deletes |
| `retention_runs` | [`RetentionRun`](../../backend/app/Modules/Privacy/Models/RetentionRun.php) | `tenant_id`, `kind`, `status`, `days`, `cutoff`, `count`, `ids`, `oldest`, `newest`, `made_by`, `confirmed_by`, `confirmed_at`, `deleted` | tenant-scoped, soft deletes |
| `roles` | [`SchoolRole`](../../backend/app/Modules/Identity/Models/SchoolRole.php) | `tenant_id`, `name`, `label`, `level`, `duty`, `permissions`, `seeded_permissions`, `is_system` | tenant-scoped, soft deletes |
| `school_clients` | [`SchoolClient`](../../backend/app/Modules/Platform/Models/SchoolClient.php) | `school_id`, `contact_person`, `phone`, `email`, `address`, `package_name`, `price`, `billing_period`, `paid_until`, `notes` | — |
| `school_configs` | [`SchoolConfig`](../../backend/app/Modules/Tenancy/Models/SchoolConfig.php) | `tenant_id`, `version`, `body`, `diff`, `changed_by`, `reason`, `rolled_back_from` | tenant-scoped |
| `school_payments` | [`SchoolPayment`](../../backend/app/Modules/Platform/Models/SchoolPayment.php) | `school_id`, `amount`, `paid_on`, `method`, `reference`, `period_from`, `period_to`, `note`, `recorded_by` | — |
| `school_stats` | [`SchoolStats`](../../backend/app/Modules/Platform/Models/SchoolStats.php) | `school_id`, `computed_at`, `usage`, `security`, `grants` | — |
| `sections` | [`Section`](../../backend/app/Modules/AcademicStructure/Models/Section.php) | `tenant_id`, `year_id`, `class_id`, `name`, `label`, `wing`, `class_teacher_id`, `room`, `draft` | tenant-scoped, soft deletes |
| `staff_attendance` | [`StaffAttendance`](../../backend/app/Modules/Attendance/Models/StaffAttendance.php) | `tenant_id`, `staff_id`, `date`, `status`, `in`, `out`, `source`, `inside_area`, `late`, `entered_by`, `reason`, `leave_request_id`, `replaced` | tenant-scoped, soft deletes |
| `staff` | [`Staff`](../../backend/app/Modules/People/Models/Staff.php) | `tenant_id`, `employee_code`, `name`, `designation`, `type`, `email`, `phone`, `joined_on`, `user_id`, `wings`, `status`, `requested_roles`, `drafted_by`, `custom`, `import_batch_id` | tenant-scoped, soft deletes |
| `student_fees` | [`StudentFee`](../../backend/app/Modules/Fees/Models/StudentFee.php) | `tenant_id`, `student_id`, `year_id`, `plan_id`, `category_id`, `joined_on`, `overrides` | tenant-scoped, soft deletes |
| `students` | [`Student`](../../backend/app/Modules/People/Models/Student.php) | `tenant_id`, `admission_no`, `name`, `dob`, `gender`, `guardian_ids`, `ids`, `custom`, `user_id`, `login_name`, `status`, `import_batch_id` | tenant-scoped, soft deletes |
| `subjects` | [`Subject`](../../backend/app/Modules/AcademicStructure/Models/Subject.php) | `tenant_id`, `code`, `name`, `kind`, `elective` | tenant-scoped, soft deletes |
| `support_grants` | [`SupportGrant`](../../backend/app/Modules/Identity/Models/SupportGrant.php) | `tenant_id`, `granted_by`, `agent_id`, `agent_email`, `agent_name`, `scope`, `read_only`, `reason`, `starts_at`, `expires_at`, `revoked_at`, `revoked_by` | tenant-scoped, soft deletes |
| `teaching_assignments` | [`TeachingAssignment`](../../backend/app/Modules/AcademicStructure/Models/TeachingAssignment.php) | `tenant_id`, `year_id`, `section_id`, `subject_id`, `teacher_id`, `draft` | tenant-scoped, soft deletes |
| `tenants` | [`Tenant`](../../backend/app/Modules/Tenancy/Models/Tenant.php) | `code`, `name`, `group_id`, `status`, `config_version`, `contact` | — |
| `users` | [`User`](../../backend/app/Modules/Identity/Models/User.php) | `tenant_id`, `name`, `email`, `phone`, `username`, `password`, `roles`, `mfa`, `status`, `language`, `theme`, `notification_prefs` | tenant-scoped, soft deletes |

<!-- generated:collections:end -->

## Collections written by packages

| Collection | Written by | What it holds |
| --- | --- | --- |
| `migrations` | Laravel | Which migrations (collection and index definitions) have run |
| `failed_jobs` | Laravel queue | Jobs that failed after all retries |
| `job_batches` | Laravel queue | Batched job progress (from Phase 2 imports) |
