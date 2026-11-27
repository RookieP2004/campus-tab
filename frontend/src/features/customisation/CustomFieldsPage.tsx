import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { Archive, ArrowUpCircle, Pencil, Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { allSections, type SchoolClass, type Subject, useClasses, useSubjects } from '@/features/academic/api';
import { errorText } from '@/features/auth/errors';
import { holds, scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { type FieldScope, type FieldType, localLabel } from '@/features/people/api';
import { Checkbox, PageHeader, StatusChip, TableFrame, td, th } from '@/features/people/ui';
import { CREATABLE_TYPES, type CustomFieldDef, type CustomFieldInput, customFields, customFieldsKey, useCustomFields } from './api';

const ENTITIES = ['student', 'enrolment', 'staff'] as const;

interface ScopeOption {
  value: string;
  label: string;
  scope: FieldScope;
}

const encode = (scope: FieldScope) => `${scope.level}|${scope.ref ?? ''}`;

/** Names for scopes: section labels from the classes, subject names from the subjects. */
function scopeLabeller(classes: SchoolClass[] | undefined, subjects: Subject[] | undefined, t: TFunction) {
  const sections = new Map(allSections(classes).map((s) => [s.id, s.label]));
  const subjectNames = new Map((subjects ?? []).map((s) => [s.id, s.name]));

  return (scope: FieldScope): string => {
    switch (scope.level) {
      case 'school':
        return t('customFields.scope.school');
      case 'wing':
        return t('customFields.scope.wing', { wing: scope.ref ?? '' });
      case 'section':
        return t('customFields.scope.section', { section: sections.get(scope.ref ?? '') ?? scope.ref ?? '' });
      default: {
        const [sectionId = '', subjectId = ''] = (scope.ref ?? '').split(':');
        return t('customFields.scope.subjectSection', { section: sections.get(sectionId) ?? sectionId, subject: subjectNames.get(subjectId) ?? subjectId });
      }
    }
  };
}

/** Only the scopes this person can use to create a field. */
function useScopeOptions(): ScopeOption[] {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const scope = scopeOf(me);
  const classes = useClasses();
  const subjects = useSubjects(scope.subject_sections.length > 0);
  const label = scopeLabeller(classes.data, subjects.data, t);
  const options: FieldScope[] = [];

  if (holds(me, 'custom_fields.manage', 'school') || holds(me, 'custom_fields.clerical', 'school')) options.push({ level: 'school', ref: null });
  if (holds(me, 'custom_fields.manage', 'wing')) for (const wing of scope.wings) options.push({ level: 'wing', ref: wing });
  for (const section of scope.class_sections) options.push({ level: 'section', ref: section });
  for (const taught of scope.subject_sections) options.push({ level: 'subject_section', ref: taught });

  return options.map((s) => ({ value: encode(s), label: label(s), scope: s }));
}

export function CustomFieldsPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const list = useCustomFields();
  const classes = useClasses();
  const subjects = useSubjects();
  const scopeOptions = useScopeOptions();
  const label = scopeLabeller(classes.data, subjects.data, t);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<CustomFieldDef | null>(null);

  const groups = ENTITIES.map((entity) => {
    const fields = (list.data ?? []).filter((f) => f.entity === entity);
    const byScope = new Map<string, CustomFieldDef[]>();
    for (const f of fields) byScope.set(encode(f.scope), [...(byScope.get(encode(f.scope)) ?? []), f]);
    return { entity, scopes: [...byScope.entries()] };
  }).filter((g) => g.scopes.length > 0);

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={t('customFields.title')}
        intro={t('customFields.intro')}
        actions={
          scopeOptions.length > 0 ? (
            <Button onClick={() => setCreating(true)}>
              <Plus aria-hidden="true" />
              {t('customFields.create')}
            </Button>
          ) : null
        }
      />
      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.isError ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}
      {list.data?.length === 0 ? <Card>{t('customFields.empty')}</Card> : null}

      {groups.map((group) => (
        <section key={group.entity} className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t(`customFields.entity.${group.entity}`)}</h2>
          {group.scopes.map(([key, fields]) => (
            <div key={key} className="flex flex-col gap-2">
              <h3 className="text-sm font-medium text-muted">{fields[0] ? label(fields[0].scope) : ''}</h3>
              <TableFrame>
                <thead>
                  <tr>
                    <th scope="col" className={th}>{t('customFields.table.label')}</th>
                    <th scope="col" className={th}>{t('customFields.table.type')}</th>
                    <th scope="col" className={th}>{t('customFields.table.scope')}</th>
                    <th scope="col" className={th}>{t('customFields.table.owner')}</th>
                    <th scope="col" className={th}>{t('customFields.table.status')}</th>
                    <th scope="col" className={th}>
                      <span className="sr-only">{t('customFields.table.actions')}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {fields.map((f) => (
                    <FieldRow key={f.id} field={f} label={localLabel(f.label, i18n.language)} scopeLabel={label(f.scope)} canPromote={me.level >= 80} onEdit={() => setEditing(f)} />
                  ))}
                </tbody>
              </TableFrame>
            </div>
          ))}
        </section>
      ))}

      {creating ? <FieldDialog options={scopeOptions} onClose={() => setCreating(false)} /> : null}
      {editing ? <FieldDialog key={editing.id} field={editing} options={scopeOptions} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function FieldRow({ field, label, scopeLabel, canPromote, onEdit }: { field: CustomFieldDef; label: string; scopeLabel: string; canPromote: boolean; onEdit: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const action = useMutation({
    mutationFn: (kind: 'archive' | 'restore' | 'promote') => customFields[kind](field.id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: customFieldsKey }),
  });

  return (
    <tr>
      <td className={td}>
        <p className="font-medium">{label}</p>
        {field.required ? <p className="text-xs text-muted">{t('customFields.requiredTag')}</p> : null}
        {action.isError ? <p className="text-xs text-danger" role="alert">{errorText(t, action.error)}</p> : null}
      </td>
      <td className={td}>{t(`customFields.type.${field.type}`, { defaultValue: field.type })}</td>
      <td className={td}>{scopeLabel}</td>
      <td className={td}>{t('customFields.ownerLevel', { level: field.owner_level })}</td>
      <td className={td}>
        <StatusChip status={field.status} label={t(`customFields.status.${field.status}`)} />
      </td>
      <td className={td}>
        {field.can_change ? (
          <div className="flex flex-wrap gap-1.5">
            {field.status === 'active' ? (
              <>
                <Button size="sm" variant="outline" onClick={onEdit}>
                  <Pencil aria-hidden="true" />
                  {t('customFields.edit')}
                </Button>
                <Button size="sm" variant="outline" disabled={action.isPending} onClick={() => action.mutate('archive')}>
                  <Archive aria-hidden="true" />
                  {t('customFields.archive')}
                </Button>
                {canPromote && field.scope.level !== 'school' ? (
                  <Button size="sm" variant="outline" disabled={action.isPending} onClick={() => action.mutate('promote')}>
                    <ArrowUpCircle aria-hidden="true" />
                    {t('customFields.promote')}
                  </Button>
                ) : null}
              </>
            ) : (
              <Button size="sm" variant="outline" disabled={action.isPending} onClick={() => action.mutate('restore')}>
                <RotateCcw aria-hidden="true" />
                {t('customFields.restore')}
              </Button>
            )}
          </div>
        ) : null}
      </td>
    </tr>
  );
}

/** Create a field (any scope the person can use), or edit one's label, options and flags. */
function FieldDialog({ field, options, onClose }: { field?: CustomFieldDef; options: ScopeOption[]; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [scopeValue, setScopeValue] = useState(field ? encode(field.scope) : (options[0]?.value ?? ''));
  const scope = field?.scope ?? options.find((o) => o.value === scopeValue)?.scope ?? null;
  const sectionScoped = scope?.level === 'section' || scope?.level === 'subject_section';
  const [entity, setEntity] = useState<CustomFieldInput['entity']>(field?.entity ?? (sectionScoped ? 'enrolment' : 'student'));
  const [form, setForm] = useState({
    en: field?.label.en ?? '',
    hi: field?.label.hi ?? '',
    type: (field?.type ?? 'text') as FieldType,
    options: (field?.options ?? []).join('\n'),
    required: field?.required ?? false,
    subjectTeachers: field?.visible_to.includes('subject_teacher') ?? false,
  });
  const hasOptions = form.type === 'dropdown' || form.type === 'multi_select';

  const save = useMutation({
    mutationFn: () => {
      const label = { en: form.en.trim(), ...(form.hi.trim() ? { hi: form.hi.trim() } : {}) };
      const choices = hasOptions
        ? form.options
            .split('\n')
            .map((o) => o.trim())
            .filter(Boolean)
        : undefined;
      const visibleTo = scope?.level === 'section' ? (form.subjectTeachers ? ['subject_teacher'] : []) : undefined;
      if (field) return customFields.update(field.id, { label, options: choices, required: form.required, visible_to: visibleTo });
      if (!scope) throw new Error('scope');
      return customFields.create({
        entity: sectionScoped ? 'enrolment' : entity,
        label,
        type: form.type,
        options: choices,
        required: form.required,
        scope,
        visible_to: visibleTo,
      });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: customFieldsKey });
      onClose();
    },
  });

  return (
    <Dialog open title={field ? t('customFields.editTitle') : t('customFields.create')} onClose={onClose} className="max-w-lg">
      <form
        className="flex flex-col gap-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {save.isError ? <Alert tone="danger">{errorText(t, save.error)}</Alert> : null}
        {!field ? (
          <Field label={t('customFields.form.scope')} hint={t('customFields.form.scopeHint')}>
            {(p) => (
              <Select
                {...p}
                value={scopeValue}
                onChange={(e) => {
                  setScopeValue(e.target.value);
                  const next = options.find((o) => o.value === e.target.value)?.scope;
                  if (next && (next.level === 'section' || next.level === 'subject_section')) setEntity('enrolment');
                }}
              >
                {options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}
        {!field ? (
          <Field label={t('customFields.form.entity')} hint={sectionScoped ? t('customFields.form.entitySectionHint') : undefined}>
            {(p) => (
              <Select {...p} value={sectionScoped ? 'enrolment' : entity} disabled={sectionScoped} onChange={(e) => setEntity(e.target.value as CustomFieldInput['entity'])}>
                {ENTITIES.map((en) => (
                  <option key={en} value={en}>
                    {t(`customFields.entity.${en}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}
        <Field label={t('customFields.form.labelEn')}>
          {(p) => <Input {...p} value={form.en} maxLength={60} onChange={(e) => setForm({ ...form, en: e.target.value })} />}
        </Field>
        <Field label={t('customFields.form.labelHi')} hint={t('customFields.form.optional')}>
          {(p) => <Input {...p} value={form.hi} maxLength={60} lang="hi" onChange={(e) => setForm({ ...form, hi: e.target.value })} />}
        </Field>
        {!field ? (
          <Field label={t('customFields.form.type')}>
            {(p) => (
              <Select {...p} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as FieldType })}>
                {CREATABLE_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {t(`customFields.type.${type}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}
        {hasOptions ? (
          <Field label={t('customFields.form.options')} hint={t('customFields.form.optionsHint')}>
            {(p) => <Textarea {...p} value={form.options} onChange={(e) => setForm({ ...form, options: e.target.value })} />}
          </Field>
        ) : null}
        <Checkbox label={t('customFields.form.required')} checked={form.required} onChange={(e) => setForm({ ...form, required: e.target.checked })} />
        {scope?.level === 'section' ? (
          <Checkbox
            label={t('customFields.form.subjectTeachers')}
            checked={form.subjectTeachers}
            onChange={(e) => setForm({ ...form, subjectTeachers: e.target.checked })}
          />
        ) : null}
        <p className="text-xs text-muted">{t('customFields.form.noAadhaar')}</p>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={save.isPending || form.en.trim() === '' || (!field && !scope)}>
            {field ? t('common.save') : t('customFields.form.submit')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
