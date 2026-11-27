import { LogOut } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/form';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { LanguagePicker } from './LanguagePicker';
import { accountItems, type NavItemDefinition, visibleNavItems } from './navItems';
import { useSignOut } from './shellHooks';
import { ServerStatus } from './ServerStatus';
import { ThemePicker } from './ThemePicker';

function LinkList({ items }: { items: NavItemDefinition[] }) {
  const { t } = useTranslation();

  return (
    <ul className="flex flex-col divide-y divide-border">
      {items.map((item) => (
        <li key={item.to}>
          <Link to={item.to} className="flex min-h-12 items-center gap-3 px-1 text-base hover:bg-surface-muted">
            <item.icon aria-hidden="true" className="size-5 text-muted" />
            {t(item.label)}
          </Link>
        </li>
      ))}
    </ul>
  );
}

/** Phones, the "More" tab: every other screen, the account and display settings. */
export function MorePage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const signOut = useSignOut();

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('nav.more')}</h1>
      <Card className="p-3 sm:p-4">
        <h2 className="sr-only">{t('more.screens')}</h2>
        <LinkList items={visibleNavItems(me)} />
      </Card>
      <Card className="flex flex-col gap-3 p-3 sm:p-4">
        <h2 className="text-sm font-semibold">{t('more.account')}</h2>
        <LinkList items={accountItems} />
        <div className="flex flex-wrap items-center gap-2">
          <LanguagePicker />
          <ThemePicker />
        </div>
        <ServerStatus />
        <Button variant="outline" onClick={() => void signOut()}>
          <LogOut aria-hidden="true" />
          {t('session.signOut')}
        </Button>
      </Card>
    </div>
  );
}
