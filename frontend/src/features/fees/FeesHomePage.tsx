import { StatusChip } from '@campus-tab/ui';
import { useQuery } from '@tanstack/react-query';
import { BarChart3, ChevronRight, ClipboardCheck, CreditCard, HandCoins, Landmark, Settings2, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Alert, Card, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds, scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { MyFeesPage } from './MyFeesPage';

/**
 * Fees home: the office sees its tools (only those it holds), a class
 * teacher sees paid or pending for their own class (never an amount), and
 * a parent sees their children's dues.
 */
export function FeesHomePage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const office = holds(me, 'fees.dues_view', 'school') || holds(me, 'fees.collect') || holds(me, 'fees.setup');

  if (!office && holds(me, 'fees.dues_view', 'child')) return <MyFeesPage />;

  const tiles: { to: string; label: string; hint: string; icon: ReactNode; show: boolean }[] = [
    { to: '/fees/counter', label: t('fees.counter.title'), hint: t('fees.home.counterHint'), icon: <HandCoins className="size-5" />, show: holds(me, 'fees.collect') },
    { to: '/fees/closings', label: t('fees.closings.title'), hint: t('fees.home.closingsHint'), icon: <Wallet className="size-5" />, show: holds(me, 'fees.collect') || holds(me, 'fees.closing_confirm') },
    {
      to: '/fees/adjustments',
      label: t('fees.adjustments.title'),
      hint: t('fees.home.adjustmentsHint'),
      icon: <ClipboardCheck className="size-5" />,
      show: holds(me, 'fees.adjust') || holds(me, 'fees.adjust_propose') || holds(me, 'fees.adjust_approve'),
    },
    { to: '/fees/reports', label: t('fees.reports.title'), hint: t('fees.home.reportsHint'), icon: <BarChart3 className="size-5" />, show: holds(me, 'fees.reports') },
    { to: '/fees/setup', label: t('fees.setup.title'), hint: t('fees.home.setupHint'), icon: <Settings2 className="size-5" />, show: holds(me, 'fees.setup') },
    { to: '/fees/online', label: t('fees.online.title'), hint: t('fees.home.onlineHint'), icon: <CreditCard className="size-5" />, show: holds(me, 'fees.gateway_view') || holds(me, 'fees.gateway_edit') },
  ];

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('nav.fees')}</h1>
      {tiles.some((x) => x.show) ? (
        <ul className="grid gap-2 sm:grid-cols-2">
          {tiles
            .filter((x) => x.show)
            .map((x) => (
              <li key={x.to}>
                <Link to={x.to} className="flex min-h-16 items-center gap-3 rounded-card border border-border bg-surface p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-ring">
                  <span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-full bg-primary-soft text-primary">
                    {x.icon}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block font-semibold">{x.label}</span>
                    <span className="block text-xs text-muted">{x.hint}</span>
                  </span>
                  <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
                </Link>
              </li>
            ))}
        </ul>
      ) : null}
      {holds(me, 'fees.dues_view', 'class') ? scopeOf(me).class_sections.map((id) => <ClassStatus key={id} sectionId={id} />) : null}
    </div>
  );
}

/** Paid or pending for each student of the class teacher's own section. */
function ClassStatus({ sectionId }: { sectionId: string }) {
  const { t } = useTranslation();
  const data = useQuery({
    queryKey: ['fees', 'section-status', sectionId],
    queryFn: apiGet<{ section: { id: string; label: string }; students: { student_id: string; name: string; status: 'paid' | 'pending' }[] }>(`/fees/sections/${sectionId}/status`),
    retry: false,
  });
  if (data.isPending) return <Spinner label={t('common.loading')} />;
  if (data.error || !data.data) return <Alert tone="danger">{errorText(t, data.error)}</Alert>;
  const pending = data.data.students.filter((s) => s.status === 'pending').length;

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="flex items-center gap-2 text-lg font-semibold">
          <Landmark aria-hidden="true" className="size-5 text-muted" />
          {t('fees.classStatus.title', { label: data.data.section.label })}
        </h2>
        <p className="text-sm text-muted">{t('fees.classStatus.summary', { pending, total: data.data.students.length })}</p>
      </div>
      <p className="text-xs text-muted">{t('fees.classStatus.note')}</p>
      <ul className="flex flex-col divide-y divide-border">
        {data.data.students.map((s) => (
          <li key={s.student_id} className="flex min-h-11 items-center gap-2 py-2">
            <span className="min-w-0 flex-1 truncate text-sm">{s.name}</span>
            <StatusChip status={s.status === 'paid' ? 'approved' : 'pending'} label={t(`fees.classStatus.${s.status}`)} />
          </li>
        ))}
      </ul>
    </Card>
  );
}
