import { Bell, Megaphone, Menu, Search } from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { NavLink, Outlet } from 'react-router';
import { usePublicConfig } from '@/features/auth/api';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { holds } from '@/features/auth/permissions';
import { useDashboard } from '@/features/dashboard/api';
import { useNotificationSocket } from '@/features/notifications/socket';
import { cn } from '@/lib/utils';
import { useViewport } from '@/lib/useViewport';
import { BrandStack } from './BrandStack';
import { homeItem, mainJob, type NavItemDefinition, visibleNavItems } from './navItems';
import { NotificationBell } from './NotificationBell';
import { ProfileMenu } from './ProfileMenu';
import { QuickCreate } from './QuickCreate';
import { ServerStatus } from './ServerStatus';
import { useUnreadCount } from './shellHooks';

// The palette (and its search) loads on the first Ctrl/Cmd K.
const CommandPalette = lazy(() => import('./CommandPalette').then((m) => ({ default: m.CommandPalette })));

/**
 * The signed-in shell, one layout per width (only one navigation is ever
 * rendered):
 *  - 1024 px and up: sidebar with the brand stack, navigation and server status
 *  - 768-1023 px: icon rail; the brand stack moves to the top bar
 *  - under 768 px: compact header and bottom tabs (Home, the role's main
 *    job, Inbox, Notices, More)
 * The top bar holds search (Ctrl/Cmd K), quick create, the bell and the
 * profile menu. Layout follows docs/design/screens/{light,dark}/Sidebar.dc.html
 * and Main.dc.html.
 */
export function AppShell() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const viewport = useViewport();
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchLoaded, setSearchLoaded] = useState(false);
  const items = [homeItem, ...visibleNavItems(me)];

  useNotificationSocket(me);
  useSchoolAccent(me.school.code);
  const header = useHeaderHeight();

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setSearchLoaded(true);
        setSearchOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const openSearch = () => {
    setSearchLoaded(true);
    setSearchOpen(true);
  };

  return (
    <div className="min-h-dvh bg-bg text-fg">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-input focus:bg-surface focus:px-4 focus:py-2 focus:shadow"
      >
        {t('a11y.skipToContent')}
      </a>

      <div className="flex min-h-dvh">
        {viewport === 'desktop' ? (
          <aside className="sticky top-0 flex h-dvh w-62 print:hidden shrink-0 flex-col gap-5 overflow-y-auto border-e border-border bg-surface px-3.5 py-5" data-layout="sidebar">
            <BrandStack />
            <nav aria-label={t('a11y.mainNavigation')}>
              <ul className="flex flex-col gap-0.5">
                {items.map((item) => (
                  <SideItem key={item.to} item={item} />
                ))}
              </ul>
            </nav>
            <div className="mt-auto border-t border-border pt-3.5">
              <ServerStatus />
            </div>
          </aside>
        ) : null}

        {viewport === 'tablet' ? (
          <aside className="sticky top-0 flex h-dvh w-18 print:hidden shrink-0 flex-col items-center gap-4 border-e border-border bg-surface py-4" data-layout="rail">
            <img src="/logo-mark.svg" width={36} height={36} alt="" />
            <nav aria-label={t('a11y.mainNavigation')}>
              <ul className="flex flex-col gap-1">
                {items.map((item) => (
                  <RailItem key={item.to} item={item} />
                ))}
              </ul>
            </nav>
          </aside>
        ) : null}

        <div className="flex min-w-0 flex-1 flex-col">
          <header ref={header} className="sticky top-0 z-30 border-b border-border bg-surface print:hidden">
            <div aria-hidden="true" className="h-[3px] bg-school-accent" />
            <div className="flex items-center gap-2 px-3 py-2 md:px-4 lg:px-6">
              {viewport !== 'desktop' ? (
                <div className="min-w-0 flex-1">
                  <BrandStack variant="compact" />
                </div>
              ) : (
                <SearchField onOpen={openSearch} />
              )}
              <div className="ms-auto flex shrink-0 items-center gap-1 md:gap-2">
                {viewport !== 'desktop' ? (
                  <button
                    type="button"
                    onClick={openSearch}
                    aria-label={t('search.open')}
                    title={t('search.open')}
                    className="inline-flex size-11 items-center justify-center rounded-input text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    <Search aria-hidden="true" className="size-5" />
                  </button>
                ) : null}
                <QuickCreate />
                <NotificationBell />
                <ProfileMenu />
              </div>
            </div>
          </header>

          <main id="main" className={cn('flex-1 px-4 py-6 lg:px-8', viewport === 'phone' && 'pb-24')}>
            <Outlet />
          </main>
        </div>
      </div>

      {viewport === 'phone' ? <BottomTabs /> : null}

      {searchLoaded ? (
        <Suspense fallback={null}>
          <CommandPalette open={searchOpen} onClose={() => setSearchOpen(false)} />
        </Suspense>
      ) : null}
    </div>
  );
}

/** Desktop search box: opens the command palette. */
function SearchField({ onOpen }: { onOpen: () => void }) {
  const { t } = useTranslation();
  const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-keyshortcuts={mac ? 'Meta+K' : 'Control+K'}
      className="flex h-11 w-full max-w-md items-center gap-2.5 rounded-input border border-border bg-bg px-3 text-sm text-muted hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
    >
      <Search aria-hidden="true" className="size-4" />
      <span className="flex-1 text-start">{t('search.placeholder')}</span>
      <kbd className="rounded border border-border bg-surface px-1.5 py-0.5 font-sans text-xs">{mac ? '⌘ K' : 'Ctrl K'}</kbd>
    </button>
  );
}

function SideItem({ item }: { item: NavItemDefinition }) {
  const { t } = useTranslation();

  return (
    <li>
      <NavLink
        to={item.to}
        end={item.end}
        className={({ isActive }) =>
          cn(
            'relative flex h-11 items-center gap-3 rounded-lg px-2.5 text-sm whitespace-nowrap focus-visible:outline-2 focus-visible:outline-ring',
            isActive
              ? 'bg-primary-soft font-semibold text-primary before:absolute before:inset-y-2 before:start-0 before:w-[3px] before:rounded-full before:bg-school-accent'
              : 'text-fg hover:bg-surface-muted',
          )
        }
      >
        <item.icon aria-hidden="true" className="size-[18px]" />
        {t(item.label)}
      </NavLink>
    </li>
  );
}

/** Tablet rail: icons with an accessible name and a tooltip on hover or focus. */
function RailItem({ item }: { item: NavItemDefinition }) {
  const { t } = useTranslation();
  const label = t(item.label);

  return (
    <li className="group relative">
      <NavLink
        to={item.to}
        end={item.end}
        aria-label={label}
        className={({ isActive }) =>
          cn(
            'flex size-11 items-center justify-center rounded-lg focus-visible:outline-2 focus-visible:outline-ring',
            isActive ? 'bg-primary-soft text-primary' : 'text-fg hover:bg-surface-muted',
          )
        }
      >
        <item.icon aria-hidden="true" className="size-5" />
      </NavLink>
      <span
        aria-hidden="true"
        className="pointer-events-none absolute start-full top-1/2 z-40 ms-2 -translate-y-1/2 rounded-input bg-fg px-2 py-1 text-xs whitespace-nowrap text-bg opacity-0 transition-opacity duration-(--ct-motion) group-focus-within:opacity-100 group-hover:opacity-100"
      >
        {label}
      </span>
    </li>
  );
}

/** Phones: Home, the role's main job, Inbox, Notices, More. */
function BottomTabs() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const { data } = useDashboard(me);
  const unread = useUnreadCount();
  const job = mainJob(data?.role ?? me.roles[0], me);

  const tabs: (NavItemDefinition & { badge?: number })[] = [
    homeItem,
    ...(job ? [job] : []),
    { to: '/notifications', label: 'nav.inbox', icon: Bell, badge: unread },
    ...(holds(me, 'notices.view') ? [{ to: '/notices', label: 'nav.notices', icon: Megaphone }] : []),
    { to: '/more', label: 'nav.more', icon: Menu },
  ];

  return (
    <nav aria-label={t('a11y.mainNavigation')} className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface pb-[env(safe-area-inset-bottom)] print:hidden" data-layout="tabs">
      <ul className="flex">
        {tabs.map((tab) => (
          <li key={tab.to} className="flex-1">
            <NavLink
              to={tab.to}
              end={tab.end}
              className={({ isActive }) =>
                cn(
                  'relative flex min-h-14 flex-col items-center justify-center gap-0.5 px-1 text-[11px] font-medium focus-visible:outline-2 focus-visible:outline-ring',
                  isActive ? 'text-primary' : 'text-muted',
                )
              }
            >
              <tab.icon aria-hidden="true" className="size-5" />
              <span className="max-w-full truncate">{t(tab.label)}</span>
              {tab.badge ? (
                <>
                  <span aria-hidden="true" className="absolute top-1.5 start-1/2 ms-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger-solid px-1 text-[10px] font-bold text-on-solid">
                    {tab.badge > 99 ? '99+' : tab.badge}
                  </span>
                  <span className="sr-only">{t('notifications.unreadCount', { count: tab.badge })}</span>
                </>
              ) : null}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** The school's accent colour (from its public config) for the header strip and nav marker. */
function useSchoolAccent(code: string) {
  const { data } = usePublicConfig(code);
  const accent = data?.accent;

  useEffect(() => {
    if (!accent || !/^#[0-9a-f]{6}$/i.test(accent)) return undefined;
    document.documentElement.style.setProperty('--ct-school-accent', accent);
    return () => {
      document.documentElement.style.removeProperty('--ct-school-accent');
    };
  }, [accent]);
}

/**
 * Publishes the top bar's height as --shell-header, so sticky parts of a
 * screen (the attendance counts) sit just under it on every layout.
 */
function useHeaderHeight() {
  const ref = useRef<HTMLElement>(null);

  useEffect(() => {
    const element = ref.current;
    if (!element || typeof ResizeObserver === 'undefined') return undefined;
    const observer = new ResizeObserver(() => document.documentElement.style.setProperty('--shell-header', `${element.offsetHeight}px`));
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  return ref;
}
