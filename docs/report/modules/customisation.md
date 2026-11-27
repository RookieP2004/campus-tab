# Customisation

> Status: **custom fields built in Phase 2.** Option lists and the settings screens for roles and branding come later.

## 1. What it does (plain words, no code)

Schools add their own fields and list columns, each with an **owner level** and a **scope**. People change what sits at or below their level, never above it.

| Who | Can add | Scope |
| --- | --- | --- |
| Chief, Principal | School-wide fields on student and staff records | school (or any narrower one) |
| Vice Principal | Fields for their wing | wing, or a section in it |
| Clerk | Clerical school-wide fields (owned at level 60) | school |
| Class Teacher | Columns only for their own section, this year | section |
| Subject Teacher | Columns for their own subject in a section | subject-section |

- **Seeing a field:**
  - a school field: everyone who can see the record
  - any other field: whoever may change it (its owner level and those above, within the scope)
  - a subject teacher sees a section column only when the class teacher shares it with subject teachers
  - so a class-only column is seen by its class teacher, never by a subject teacher
- **Archive, not delete:** removing a field archives it. Values stay, hidden, and can be restored.
- **Promote:** the Principal or Chief can make a useful class column school-wide.
- **Limits:**
  - at most 50 fields per entity and scope
  - duplicate labels in one scope are blocked
  - Aadhaar and bank account numbers can never be a field (refused by type, name or label, in English or Hindi)

## 2. Who uses it and from which screens

The custom-field manager (`/settings/custom-fields`, `frontend/src/features/custom-fields/`) lists only the fields the person may see, with actions where they may change them. Values are filled on the student profile and appear as columns in the student list.

## 3. Requests it handles

| Method and path | Input | Output | Permission |
| --- | --- | --- | --- |
| `GET /custom-fields` | entity?, section_id?, include_archived? | fields the person may see, with `can_change` and `can_fill` | `custom_fields.manage`, `.clerical`, `students.view` or `staff.view` |
| `POST /custom-fields` | entity (student, enrolment, staff), label{en, hi?}, type, options?, required?, sensitive?, scope{level, ref}, visible_to? | field | `custom_fields.manage` or `.clerical`, then level and scope |
| `PATCH /custom-fields/{id}` | label, options, required, visible_to, order | field | owner level and scope |
| `POST /custom-fields/{id}/archive` · `/restore` | — | field | owner level and scope |
| `POST /custom-fields/{id}/promote` | — | field at school scope, owned by the promoter | `custom_fields.manage:school` (Principal, Chief) |

Values are written through `POST`/`PATCH /students…` with `custom: {key: value}`.

Errors:

- `custom_field_forbidden` (Aadhaar or bank field; or a value the person may not fill)
- `custom_field_scope_forbidden`, `custom_field_level`, `custom_field_scope_invalid`
- `custom_field_duplicate`, `custom_field_limit`, `custom_field_options_required`, `custom_field_type_invalid`
- `custom_value_invalid`

## 4. Code map

| File | What it does |
| --- | --- |
| [`Customisation/routes.php`](../../../backend/app/Modules/Customisation/routes.php) | Routes |
| [`Http/Controllers/CustomFieldController.php`](../../../backend/app/Modules/Customisation/Http/Controllers/CustomFieldController.php) | Validation and responses |
| [`Domain/FieldRules.php`](../../../backend/app/Modules/Customisation/Domain/FieldRules.php) | Define, change, see and fill rules; value types |
| [`Domain/CustomFields.php`](../../../backend/app/Modules/Customisation/Domain/CustomFields.php) | Define, change, archive, promote; the Aadhaar and bank refusal; keys |
| [`Models/CustomField.php`](../../../backend/app/Modules/Customisation/Models/CustomField.php) | The definition |
| [`Policies/CustomFieldPolicy.php`](../../../backend/app/Modules/Customisation/Policies/CustomFieldPolicy.php) | Route-level checks |

## 5. How the code works, step by step

- **Scope a person may define at:**
  - school: `custom_fields.manage:school`, or `custom_fields.clerical:school` (owned at 60)
  - wing W: `manage:wing` with W one of their wings
  - section S: `manage:class` with S one of their class sections, or `manage:wing` covering S's wing
  - subject-section: `manage:subject` for that subject-section, or class or wing cover of its section
- **Owner level.** The field gets the definer's level, and a change needs a level at or above it.
- **Where values live.** Section and subject-section fields have entity `enrolment`, so their values are on the year's enrolment and reset with the new year. School and wing fields live on `students.custom` (or `staff.custom`).

## 6. Data it reads and writes

| Collection | Fields | Index |
| --- | --- | --- |
| `custom_fields` | entity, key, label{}, type, options, required, sensitive, scope{level, ref}, owner_level, visible_to, status (active, archived), order, owner | tenant_id + entity + scope.level + scope.ref; tenant_id + entity + key |

It writes values into `students.custom`, `enrolments.custom` and `staff.custom`.

## 7. Links to other modules

- **Reads:** the scope facts (People), sections and assignments (Academic Structure).
- **Used by:** People (lists, profiles, imports).

## 8. Settings that change its behaviour

- `FieldRules::MAX_PER_SCOPE` (50).
- Field types: text, long text, number, date, dropdown, multi-select, yes/no, file, grade, phone, email.

## 9. Permissions and the rules behind them

These are code rules, not settings:

- the level and scope checks
- the Aadhaar and bank refusal
- archive instead of delete

## 10. How to change it

- **Add a field type:** add it to `CustomField::TYPES`, `FieldRules::normalise()` and the front-end value input.

## 11. Tests that cover it

- **[`tests/Feature/Customisation/CustomFieldsTest.php`](../../../backend/tests/Feature/Customisation/CustomFieldsTest.php):**
  - the class-only column seen by the class teacher and the Principal, never by the subject teacher (list, columns and writes)
  - sharing a column with subject teachers
  - each level kept to its scope
  - a higher level changes a lower field, not the other way round
  - archive keeps values; promote by the Principal only
  - duplicate labels and value types
- **[`StudentsTest.php`](../../../backend/tests/Feature/People/StudentsTest.php):** Aadhaar and bank fields refused.

## 12. Decisions and limits

[decisions/0011-people-privacy-and-imports.md](../decisions/0011-people-privacy-and-imports.md). Purging an archived field (Chief only) and option lists come in a later phase. "Sensitive" fields are masked in lists now; they get app-level encryption in the Phase 9 security pass.

## 13. Change history

| Date | Phase | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-27 | 0 | Page created from the template | Phase 0 starts the Application Report |
| 2026-11-27 | 2 | Custom fields with owner level and scope, archive, promote, the Aadhaar and bank refusal | Phase 2 item 4 and rule 5 |
