import { type ReactNode, useCallback, useEffect, useId, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface TriggerProps {
  id: string;
  'aria-expanded': boolean;
  'aria-controls': string;
  onClick: () => void;
}

interface PopoverProps {
  /** Renders the button that opens the panel; spread the props onto it. */
  trigger: (props: TriggerProps) => ReactNode;
  /** Accessible name of the panel. */
  label: string;
  children: (close: () => void) => ReactNode;
  align?: 'start' | 'end';
  panelClassName?: string;
  className?: string;
}

/**
 * A button with a panel under it (the bell, quick create, the profile
 * menu). Escape or a click outside closes it and focus goes back to the
 * button. The panel is a non-modal dialog, so it may hold form controls.
 */
export function Popover({ trigger, label, children, align = 'end', panelClassName, className }: PopoverProps) {
  const [open, setOpen] = useState(false);
  const id = useId();
  const root = useRef<HTMLDivElement>(null);

  const close = useCallback((returnFocus = true) => {
    setOpen(false);
    if (returnFocus) document.getElementById(`${id}-button`)?.focus();
  }, [id]);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        close();
      }
    };
    const onPointer = (event: MouseEvent) => {
      if (root.current && !root.current.contains(event.target as Node)) close(false);
    };
    document.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onPointer);

    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('mousedown', onPointer);
    };
  }, [open, close]);

  return (
    <div ref={root} className={cn('relative', className)}>
      {trigger({ id: `${id}-button`, 'aria-expanded': open, 'aria-controls': id, onClick: () => setOpen((v) => !v) })}
      {open ? (
        <div
          id={id}
          role="dialog"
          aria-label={label}
          className={cn(
            'absolute top-full z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-card border border-border bg-surface p-3 text-fg shadow-xl',
            align === 'end' ? 'end-0' : 'start-0',
            panelClassName,
          )}
        >
          {children(() => close())}
        </div>
      ) : null}
    </div>
  );
}
