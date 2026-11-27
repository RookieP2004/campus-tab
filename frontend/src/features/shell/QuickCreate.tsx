import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { Popover } from './Popover';
import { createActions } from './shellHooks';

/** "New": admit a student, send a notice, add staff, import (only what the person may do). */
export function QuickCreate() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const actions = createActions(me);

  if (actions.length === 0) return null;

  return (
    <Popover
      label={t('quickCreate.title')}
      panelClassName="w-64"
      trigger={(props) => (
        <Button {...props} variant="primary" className="max-md:size-11 max-md:px-0" aria-label={t('quickCreate.title')}>
          <Plus aria-hidden="true" />
          <span className="max-md:sr-only" aria-hidden="true">
            {t('quickCreate.button')}
          </span>
        </Button>
      )}
    >
      {(close) => (
        <ul className="flex flex-col">
          {actions.map((action) => (
            <li key={action.to}>
              <Link to={action.to} onClick={close} className="flex min-h-11 items-center gap-2.5 rounded-input px-2 text-sm hover:bg-surface-muted">
                <action.icon aria-hidden="true" className="size-4 text-muted" />
                {t(action.label)}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Popover>
  );
}
