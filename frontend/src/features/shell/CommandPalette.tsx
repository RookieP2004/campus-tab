import { Search, UserRound, Users } from 'lucide-react';
import { type KeyboardEvent, useDeferredValue, useId, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Dialog, Spinner } from '@/components/ui/form';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { useSearch } from '@/features/dashboard/api';
import { cn } from '@/lib/utils';
import { accountItems, homeItem, visibleNavItems } from './navItems';

interface Option {
  id: string;
  group: 'screens' | 'people';
  label: string;
  detail?: string | null;
  to: string;
  icon: typeof Search;
}

/**
 * Ctrl/Cmd K: jump to a screen, or find a student or staff member
 * (GET /me/search, only people the person may see). Arrow keys move,
 * Enter opens, Escape closes. Loaded only when first opened.
 */
export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();

  return (
    <Dialog open={open} onClose={onClose} title={t('search.title')} className="max-w-xl self-start sm:mt-[12vh]">
      {open ? <PaletteBody onClose={onClose} /> : null}
    </Dialog>
  );
}

function PaletteBody({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const id = useId();
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const deferred = useDeferredValue(query);
  const search = useSearch(deferred);

  const options = useMemo<Option[]>(() => {
    const q = query.trim().toLocaleLowerCase();
    const screens = [homeItem, ...visibleNavItems(me), ...accountItems]
      .map((item) => ({ id: `screen:${item.to}`, group: 'screens' as const, label: t(item.label), to: item.to, icon: item.icon }))
      .filter((o) => !q || o.label.toLocaleLowerCase().includes(q));
    const people = (q.length >= 2 && Array.isArray(search.data) ? search.data : []).map((r, i) => ({
      id: `person:${i}:${r.link}`,
      group: 'people' as const,
      label: r.label,
      detail: [t(`search.types.${r.type}`), r.detail].filter(Boolean).join(' · '),
      to: r.link,
      icon: r.type === 'student' ? Users : UserRound,
    }));
    return [...screens, ...people];
  }, [query, me, t, search.data]);

  const current = Math.min(active, Math.max(options.length - 1, 0));

  const go = (option: Option | undefined) => {
    if (!option) return;
    onClose();
    navigate(option.to);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((current + 1) % Math.max(options.length, 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((current - 1 + options.length) % Math.max(options.length, 1));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      go(options[current]);
    }
  };

  const groups = (['screens', 'people'] as const).map((group) => ({ group, items: options.filter((o) => o.group === group) }));

  return (
    <div className="flex flex-col gap-3">
      <div className="relative">
        <Search aria-hidden="true" className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <input
          autoFocus
          role="combobox"
          aria-expanded="true"
          aria-controls={`${id}-list`}
          aria-activedescendant={options[current] ? `${id}-${current}` : undefined}
          aria-autocomplete="list"
          aria-label={t('search.label')}
          placeholder={t('search.placeholder')}
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setActive(0);
          }}
          onKeyDown={onKeyDown}
          className="h-11 w-full rounded-input border border-border bg-surface ps-9 pe-3 text-base text-fg placeholder:text-muted focus-visible:outline-2 focus-visible:outline-ring"
        />
      </div>
      <div id={`${id}-list`} role="listbox" aria-label={t('search.results')} className="max-h-[50vh] overflow-y-auto">
        {groups.map(({ group, items }) =>
          items.length > 0 ? (
            <div key={group} role="group" aria-label={t(`search.groups.${group}`)} className="mb-2">
              <p aria-hidden="true" className="px-2 pb-1 text-xs font-semibold text-muted uppercase">
                {t(`search.groups.${group}`)}
              </p>
              {items.map((option) => {
                const index = options.indexOf(option);
                return (
                  <div
                    key={option.id}
                    id={`${id}-${index}`}
                    role="option"
                    aria-selected={index === current}
                    onMouseEnter={() => setActive(index)}
                    onClick={() => go(option)}
                    className={cn('flex min-h-11 cursor-pointer items-center gap-2.5 rounded-input px-2 text-sm', index === current && 'bg-primary-soft text-fg')}
                  >
                    <option.icon aria-hidden="true" className="size-4 shrink-0 text-muted" />
                    <span className="flex min-w-0 flex-col">
                      <span className="truncate">{option.label}</span>
                      {option.detail ? <span className="truncate text-xs text-muted">{option.detail}</span> : null}
                    </span>
                  </div>
                );
              })}
            </div>
          ) : null,
        )}
        {options.length === 0 && !search.isFetching ? <p className="px-2 py-3 text-sm text-muted">{t('search.none')}</p> : null}
      </div>
      <div className="flex items-center justify-between gap-2 text-xs text-muted">
        <span>{t('search.hint')}</span>
        {search.isFetching ? <Spinner label={t('search.searching')} /> : null}
      </div>
    </div>
  );
}
