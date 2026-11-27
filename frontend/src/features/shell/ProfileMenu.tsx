import { ChevronDown, LogOut } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { useDashboard } from '@/features/dashboard/api';
import { useViewPrefs } from '@/stores/view';
import { LanguagePicker } from './LanguagePicker';
import { accountItems } from './navItems';
import { Popover } from './Popover';
import { useSignOut } from './shellHooks';
import { ThemePicker } from './ThemePicker';

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}

/**
 * The profile menu: who is signed in, the role switcher (from the roles
 * GET /me/dashboard lists), language, theme, devices, notification
 * settings and sign out.
 */
export function ProfileMenu() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const signOut = useSignOut();

  return (
    <Popover
      label={t('profile.menu')}
      trigger={(props) => (
        <button
          {...props}
          type="button"
          className="flex h-11 items-center gap-2 rounded-input px-1.5 text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
        >
          <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">
            {initials(me.name)}
          </span>
          <span className="max-w-40 truncate font-medium max-md:sr-only" data-testid="signed-in-name">
            {me.name}
          </span>
          <span className="sr-only">{t('profile.open')}</span>
          <ChevronDown aria-hidden="true" className="size-4 text-muted max-md:hidden" />
        </button>
      )}
    >
      {(close) => (
        <div className="flex flex-col gap-3">
          <div>
            <p className="text-sm font-semibold">{me.name}</p>
            <p className="text-xs text-muted">{me.roles.map((r) => t(`roles.${r}`, { defaultValue: r })).join(' · ')}</p>
          </div>
          <RoleSwitcher onChosen={close} />
          <div className="flex flex-col gap-2">
            <LanguagePicker className="w-full [&_select]:w-full" />
            <ThemePicker />
          </div>
          <ul className="flex flex-col border-t border-border pt-2">
            {accountItems.map((item) => (
              <li key={item.to}>
                <Link to={item.to} onClick={close} className="flex min-h-11 items-center gap-2.5 rounded-input px-2 text-sm hover:bg-surface-muted">
                  <item.icon aria-hidden="true" className="size-4 text-muted" />
                  {t(item.label)}
                </Link>
              </li>
            ))}
          </ul>
          <Button variant="outline" onClick={() => void signOut()}>
            <LogOut aria-hidden="true" />
            {t('session.signOut')}
          </Button>
        </div>
      )}
    </Popover>
  );
}

/**
 * For people with more than one role: whose dashboard to show. Choosing
 * one refetches GET /me/dashboard?as=<role>; the server builds the cards.
 */
function RoleSwitcher({ onChosen }: { onChosen: () => void }) {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const { data } = useDashboard(me);
  const setRole = useViewPrefs((s) => s.setRole);
  const roles = data?.roles ?? [];

  if (roles.length < 2) return null;

  return (
    <fieldset className="flex flex-col gap-1 border-t border-border pt-2">
      <legend className="pb-1 text-xs font-semibold text-muted">{t('profile.viewAs')}</legend>
      {roles.map((role) => (
        <label key={role.name} className="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-input px-2 text-sm hover:bg-surface-muted">
          <input
            type="radio"
            name="role-view"
            value={role.name}
            checked={data?.role === role.name}
            onChange={() => {
              setRole(me.id, role.name);
              onChosen();
              navigate('/');
            }}
            className="size-4 accent-[var(--ct-primary-solid)]"
          />
          {t(`roles.${role.name}`, { defaultValue: role.label })}
        </label>
      ))}
    </fieldset>
  );
}
