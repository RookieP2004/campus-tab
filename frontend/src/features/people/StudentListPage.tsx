import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { type ColumnDef, flexRender, getCoreRowModel, useReactTable, type VisibilityState } from '@tanstack/react-table';
import { Columns3, KeyRound, Search, Upload, UserPlus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { allSections, useClasses } from '@/features/academic/api';
import { errorText } from '@/features/auth/errors';
import { holds, scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGetBody } from '@/lib/requests';
import { formatCustomValue, localLabel, peopleKeys, type StudentList, type StudentRow } from './api';
import { Checkbox, PageHeader, td, th } from './ui';

const PAGE_SIZES = [25, 50, 100];

/** Which columns a person hid, kept in this browser per user (a UI preference, never data). */
function columnStorageKey(userId: string) {
  return `campus-tab:student-columns:${userId}`;
}

function loadHidden(userId: string): VisibilityState {
  try {
    const raw = localStorage.getItem(columnStorageKey(userId));
    const hidden = raw ? (JSON.parse(raw) as unknown) : [];
    return Array.isArray(hidden) ? Object.fromEntries(hidden.filter((h) => typeof h === 'string').map((h: string) => [h, false])) : {};
  } catch {
    return {};
  }
}

function saveHidden(userId: string, state: VisibilityState) {
  try {
    const hidden = Object.entries(state)
      .filter(([, visible]) => !visible)
      .map(([id]) => id);
    localStorage.setItem(columnStorageKey(userId), JSON.stringify(hidden));
  } catch {
    // Storage blocked: the choice lasts for this visit only.
  }
}

export function StudentListPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const sectionId = params.get('section') ?? '';
  const q = params.get('q') ?? '';
  const page = Math.max(Number(params.get('page') ?? 1) || 1, 1);
  const perPage = PAGE_SIZES.includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : 50;
  const [search, setSearch] = useState(q);

  const query = { section_id: sectionId || undefined, q: q || undefined, page, per_page: perPage };
  const list = useQuery({
    queryKey: peopleKeys.studentList(query),
    queryFn: apiGetBody<StudentList>('/students', query),
    placeholderData: keepPreviousData,
  });
  const classes = useClasses();
  const sections = allSections(classes.data);

  const [visibility, setVisibility] = useState<VisibilityState>(() => loadHidden(me.id));

  const update = (next: Record<string, string | undefined>) => {
    const merged = new URLSearchParams(params);
    for (const [key, value] of Object.entries(next)) {
      if (value) merged.set(key, value);
      else merged.delete(key);
    }
    setParams(merged, { replace: true });
  };

  const yes = t('common.yes');
  const no = t('common.no');
  const customColumns = list.data?.columns;

  const columns = useMemo<ColumnDef<StudentRow>[]>(() => {
    const base: ColumnDef<StudentRow>[] = [
      { id: 'roll_no', header: t('students.columns.roll'), cell: ({ row }) => row.original.roll_no ?? '—' },
      {
        id: 'name',
        header: t('students.columns.name'),
        cell: ({ row }) => (
          <Link to={`/students/${row.original.id}`} className="font-medium text-primary underline-offset-2 hover:underline" onClick={(e) => e.stopPropagation()}>
            {row.original.name}
          </Link>
        ),
      },
      { id: 'admission_no', header: t('students.columns.admissionNo'), cell: ({ row }) => <span className="font-mono">{row.original.admission_no}</span> },
      { id: 'section', header: t('students.columns.section'), cell: ({ row }) => row.original.section_label ?? '—' },
      { id: 'gender', header: t('students.columns.gender'), cell: ({ row }) => (row.original.gender ? t(`students.gender.${row.original.gender}`) : '—') },
      { id: 'dob', header: t('students.columns.dob'), cell: ({ row }) => row.original.dob ?? '—' },
    ];
    const custom: ColumnDef<StudentRow>[] = (customColumns ?? []).map((c) => ({
      id: `custom:${c.key}`,
      header: localLabel(c.label, i18n.language),
      cell: ({ row }) => formatCustomValue(row.original.custom[c.key], yes, no),
    }));
    return [...base, ...custom];
  }, [customColumns, i18n.language, t, yes, no]);

  const table = useReactTable({
    data: list.data?.data ?? [],
    columns,
    getCoreRowModel: getCoreRowModel(),
    state: { columnVisibility: visibility },
    onColumnVisibilityChange: (updater) => {
      setVisibility((old) => {
        const next = typeof updater === 'function' ? updater(old) : updater;
        saveHidden(me.id, next);
        return next;
      });
    },
  });

  const meta = list.data?.meta;
  const lastPage = meta ? Math.max(Math.ceil(meta.total / meta.per_page), 1) : 1;
  const myClass = sectionId !== '' && scopeOf(me).class_sections.includes(sectionId) && holds(me, 'students.login_codes');

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={t('students.title')}
        actions={
          <>
            {holds(me, 'imports.students') ? (
              <Button variant="outline" asChild>
                <Link to="/imports/students">
                  <Upload aria-hidden="true" />
                  {t('students.import')}
                </Link>
              </Button>
            ) : null}
            {holds(me, 'students.admit') ? (
              <Button asChild>
                <Link to="/students/new">
                  <UserPlus aria-hidden="true" />
                  {t('students.admit')}
                </Link>
              </Button>
            ) : null}
          </>
        }
      />

      <Card className="flex flex-col gap-3 p-4 sm:p-4">
        <div className="grid gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] sm:items-end">
          <Field label={t('students.filter.section')}>
            {(p) => (
              <Select {...p} value={sectionId} onChange={(e) => update({ section: e.target.value || undefined, page: undefined })}>
                <option value="">{t('students.filter.allSections')}</option>
                {sections.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <form
            role="search"
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              update({ q: search.trim() || undefined, page: undefined });
            }}
          >
            <Field label={t('students.filter.search')} className="flex-1">
              {(p) => <Input {...p} type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('students.filter.searchHint')} />}
            </Field>
            <Button type="submit" variant="outline" size="icon" aria-label={t('common.search')}>
              <Search aria-hidden="true" />
            </Button>
          </form>
          <ColumnChooser
            items={table.getAllLeafColumns().map((c) => ({
              id: c.id,
              label: String(c.columnDef.header ?? c.id),
              visible: c.getIsVisible(),
              toggle: (v: boolean) => c.toggleVisibility(v),
              locked: c.id === 'name',
            }))}
          />
        </div>
        {myClass ? (
          <Link to={`/sections/${sectionId}/setup-codes`} className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
            <KeyRound aria-hidden="true" className="size-4" />
            {t('students.setupCodesLink')}
          </Link>
        ) : null}
      </Card>

      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.isError ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}

      {list.data ? (
        list.data.data.length === 0 ? (
          <Card>{t('students.empty')}</Card>
        ) : (
          <div className="relative overflow-x-auto rounded-card border border-border bg-surface">
            <table className="w-full min-w-max border-collapse text-sm" data-testid="student-table">
              <thead>
                {table.getHeaderGroups().map((group) => (
                  <tr key={group.id}>
                    {group.headers.map((header) => (
                      <th key={header.id} scope="col" className={th}>
                        {flexRender(header.column.columnDef.header, header.getContext())}
                      </th>
                    ))}
                  </tr>
                ))}
              </thead>
              <tbody>
                {table.getRowModel().rows.map((row) => (
                  <tr key={row.id} className="cursor-pointer hover:bg-surface-muted" onClick={() => navigate(`/students/${row.original.id}`)}>
                    {row.getVisibleCells().map((cell) => (
                      <td key={cell.id} className={td}>
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      ) : null}

      {meta && meta.total > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
          <p className="text-muted">{t('students.pageInfo', { page: meta.page, pages: lastPage, total: meta.total })}</p>
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-2 text-muted">
              {t('students.perPage')}
              <Select className="h-9 w-20" value={perPage} onChange={(e) => update({ per_page: e.target.value, page: undefined })}>
                {PAGE_SIZES.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </Select>
            </label>
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>
              {t('common.previous')}
            </Button>
            <Button size="sm" variant="outline" disabled={page >= lastPage} onClick={() => update({ page: String(page + 1) })}>
              {t('common.next')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

interface ChooserItem {
  id: string;
  label: string;
  visible: boolean;
  toggle: (visible: boolean) => void;
  locked: boolean;
}

/** A checkbox menu to show or hide columns. */
function ColumnChooser({ items }: { items: ChooserItem[] }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const escape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <Button type="button" variant="outline" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen(!open)}>
        <Columns3 aria-hidden="true" />
        {t('students.columnsButton')}
      </Button>
      {open ? (
        <div
          role="group"
          aria-label={t('students.columnsButton')}
          className="absolute end-0 z-20 mt-2 flex max-h-80 w-64 flex-col gap-2 overflow-y-auto rounded-card border border-border bg-surface p-3 shadow-lg"
        >
          {items.map((item) => (
            <Checkbox key={item.id} label={item.label} checked={item.visible} disabled={item.locked} onChange={(e) => item.toggle(e.target.checked)} />
          ))}
        </div>
      ) : null}
    </div>
  );
}
