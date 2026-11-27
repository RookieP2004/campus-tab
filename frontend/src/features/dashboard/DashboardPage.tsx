import { EmptyState } from '@campus-tab/ui';
import { LayoutDashboard } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Alert, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { formatLongDate, hourInIndia } from '@/lib/format';
import { useDashboard } from './api';
import { CardView } from './cards';

/**
 * My dashboard (Main.dc.html for the Principal, ParentHome.dc.html for a
 * parent). The cards come from GET /me/dashboard, already built from what
 * this person may see; they are shown in the server's order, the first
 * (the role's top card) large across the top, the rest in a grid. Nothing
 * is filtered here.
 */
export function DashboardPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const { data, isPending, error } = useDashboard(me);
  const hour = hourInIndia();
  const greeting = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';
  const cards = data?.cards ?? [];
  const [top, ...rest] = cards;

  return (
    <div className="mx-auto flex max-w-7xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-sm text-muted">{formatLongDate(new Date(), i18n.language)}</p>
          <h1 className="text-2xl font-semibold tracking-tight">{t(`dashboard.greeting.${greeting}`, { name: me.name })}</h1>
        </div>
        {data?.role ? <p className="text-sm text-muted">{t('dashboard.viewingAs', { role: t(`roles.${data.role}`, { defaultValue: data.role }) })}</p> : null}
      </div>

      {isPending ? <Spinner label={t('common.loading')} /> : null}
      {error && !data ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}

      {data && cards.length === 0 ? <EmptyState icon={<LayoutDashboard className="size-5" />} title={t('dashboard.empty.title')} body={t('dashboard.empty.body')} /> : null}

      {top ? (
        <div className="grid gap-4" data-testid="dashboard-cards">
          <CardView card={top} featured />
          {rest.length > 0 ? (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              {rest.map((card) => (
                <CardView key={card.id} card={card} />
              ))}
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
