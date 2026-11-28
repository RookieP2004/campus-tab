# Academic Structure

> Status: **built in Phase 2.**

## 1. What it does (plain words, no code)

It holds the shape of the school year:

- **The years:** one year (for example 2026-27) is "current".
- **Classes** (Class 7) and their **sections** (7-A, 7-B). Each section has a **wing** (primary, middle…) and a **class teacher**.
- **Subjects**, and **teaching assignments**: who teaches which subject in which section.

These records decide what each teacher may see. A class teacher covers their section, a subject teacher the sections they teach, and a Vice Principal the sections of their wings.

## 2. Who uses it and from which screens

- **The Chief and the Principal** set it up in the setup wizard (`/setup`, `frontend/src/features/setup/`).
- **Every staff member** reads it (section pickers, staff profiles).

## 3. Requests it handles

| Method and path | Input | Output | Permission |
| --- | --- | --- | --- |
| `GET /academic-years` | — | years, newest first | `academic.view` |
| `POST /academic-years` | name (`2026-27`), starts_on, ends_on, make_current? | year | `academic.manage` |
| `POST /academic-years/{id}/make-current` | — | year (the old current one is closed) | `academic.manage` |
| `GET /classes` | year_id? | classes, each with its sections and class teacher name | `academic.view` |
| `POST /classes` | grade, name, order?, wing?, sections? (`["A","B"]`) | class | `academic.manage` |
| `PATCH /classes/{id}` · `DELETE /classes/{id}` | grade, name, order, wing | class | `academic.manage` |
| `POST /sections` · `PATCH /sections/{id}` · `DELETE /sections/{id}` | class_id, name, class_teacher_id, wing, room | section | `academic.manage` |
| `GET /subjects` · `POST /subjects` · `PATCH /subjects/{id}` · `DELETE /subjects/{id}` | code, name, kind, elective | subject | view / manage |
| `GET /teaching-assignments` | year_id?, section_id?, teacher_id?, subject_id? | assignments with names | `academic.view` |
| `POST /teaching-assignments` · `DELETE /teaching-assignments/{id}` | section_id, subject_id, teacher_id | assignment (one teacher per subject-section) | `academic.manage` |

Errors:

- `no_current_year`, `year_exists`, `class_exists`, `section_exists`
- `section_has_students` (a section with enrolments cannot be removed)
- `teacher_not_found`, `subject_exists`

## 4. Code map

| File | What it does |
| --- | --- |
| [`AcademicStructure/routes.php`](../../../backend/app/Modules/AcademicStructure/routes.php) | Routes |
| [`Http/Controllers/StructureController.php`](../../../backend/app/Modules/AcademicStructure/Http/Controllers/StructureController.php) | Validation and responses |
| [`Domain/Structure.php`](../../../backend/app/Modules/AcademicStructure/Domain/Structure.php) | Current year, create class or section, assign teachers, the safe-delete rules, presenters |
| [`Models/*`](../../../backend/app/Modules/AcademicStructure/Models/) | `AcademicYear`, `SchoolClass` (collection `classes`), `Section`, `Subject`, `TeachingAssignment`, all on [`Support/Models/SchoolModel.php`](../../../backend/app/Support/Models/SchoolModel.php) (tenant scope, audit, soft delete) |
| [`Policies/AcademicStructurePolicy.php`](../../../backend/app/Modules/AcademicStructure/Policies/AcademicStructurePolicy.php) | view or manage |

## 5. How the code works, step by step

- **One current year.** `makeCurrent()` closes the previous current year in the same transaction.
- **Sections take their class's wing** unless one is given. Their label is `<grade>-<section>`.
- **Assigning** a subject-section that already has a teacher replaces the teacher, so there is one teacher per subject-section.
- **Every change** is audited (category `academic`) and soft-deleted to the recycle bin.

## 6. Data it reads and writes

| Collection | Fields | Index |
| --- | --- | --- |
| `academic_years` | name, starts_on, ends_on, status (planned, current, closed) | tenant_id + status; tenant_id + name |
| `classes` | year_id, grade, name, order, wing | tenant_id + year_id + order |
| `sections` | year_id, class_id, name, label, wing, class_teacher_id (staff id), room | tenant_id + year_id + class_id; tenant_id + class_teacher_id |
| `subjects` | code (unique), name, kind, elective | tenant_id + code (unique) |
| `teaching_assignments` | year_id, section_id, subject_id, teacher_id (staff id) | tenant_id + teacher_id + year_id; tenant_id + section_id + subject_id |

## 7. Links to other modules

- **People:**
  - reads the structure for enrolments and imports
  - `People\Domain\RecordAccessScopes` turns sections and assignments into the class and subject scopes
  - the scope cache is flushed whenever a section or an assignment changes
- **Customisation:** section and subject-section columns refer to sections and assignments.

## 8. Settings that change its behaviour

None yet.

## 9. Permissions and the rules behind them

- `academic.view:school` goes to every staff role from Subject Teacher up; `academic.manage:school` to the Chief and the Principal.
- Scopes come only from these records for the **current** year. Nothing a user sends can widen them.

## 10. How to change it

- **Add a field:** add it to the model's `$fillable`, the controller validation and `Structure::section()`/`schoolClass()`, then regenerate the API client (see [operations.md](../operations.md)).

## 11. Tests that cover it

- [`tests/Feature/People/AccountsTest.php`](../../../backend/tests/Feature/People/AccountsTest.php) ("academic structure"): the Principal sets it up, a teacher only reads it, and a section that still has students cannot be removed.
- [`ScopeResolverTest.php`](../../../backend/tests/Feature/People/ScopeResolverTest.php): class, subject and wing scopes come from these records, and a non-current year gives no scope.

## 12. Decisions and limits

- A section's students move to a new year with promotion in Phase 7. Until then a year change starts with empty sections.

## 13. Change history

| Date | Phase | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-27 | 0 | Page created from the template | Phase 0 starts the Application Report |
| 2026-11-27 | 2 | Module built | Phase 2 item 1 |
| 2026-11-28 | 7 | Sections and teaching assignments carry `draft` (rolled forward by the year-end wizard); a closed year's records are read-only (`ClosedYears`) | Year-end promotion |
