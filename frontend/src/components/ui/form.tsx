import { AlertCircle, CheckCircle2, Info, Loader2 } from 'lucide-react';
import { type ComponentProps, type ReactNode, useEffect, useId, useRef } from 'react';
import { cn } from '@/lib/utils';

/** Text input with Campus TAB tokens; 44 px tall for touch. */
export function Input({ className, ...props }: ComponentProps<'input'>) {
  return (
    <input
      className={cn(
        'h-11 w-full rounded-input border border-border bg-surface px-3 text-base text-fg placeholder:text-muted',
        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:opacity-60',
        'aria-[invalid=true]:border-danger',
        className,
      )}
      {...props}
    />
  );
}

export function Select({ className, ...props }: ComponentProps<'select'>) {
  return (
    <select
      className={cn(
        'h-11 w-full rounded-input border border-border bg-surface px-3 text-base text-fg',
        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
        className,
      )}
      {...props}
    />
  );
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
  return (
    <textarea
      className={cn(
        'min-h-24 w-full rounded-input border border-border bg-surface px-3 py-2 text-base text-fg placeholder:text-muted',
        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
        className,
      )}
      {...props}
    />
  );
}

interface FieldProps {
  label: ReactNode;
  error?: string | undefined;
  hint?: ReactNode;
  children: (props: { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }) => ReactNode;
  className?: string;
}

/** Label, control, hint and error, wired for screen readers. */
export function Field({ label, error, hint, children, className }: FieldProps) {
  const id = useId();
  const describedBy = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-sm font-medium text-fg">
        {label}
      </label>
      {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': describedBy })}
      {hint ? (
        <p id={`${id}-hint`} className="text-xs text-muted">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}

const alertTones = {
  danger: { icon: AlertCircle, className: 'border-danger/40 text-danger' },
  success: { icon: CheckCircle2, className: 'border-success/40 text-success' },
  info: { icon: Info, className: 'border-info/40 text-info' },
  warning: { icon: AlertCircle, className: 'border-warning/40 text-warning' },
} as const;

export function Alert({ tone = 'info', children, className }: { tone?: keyof typeof alertTones; children: ReactNode; className?: string }) {
  const { icon: Icon, className: toneClass } = alertTones[tone];

  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('flex gap-2.5 rounded-input border bg-surface p-3 text-sm', toneClass, className)}>
      <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 text-fg">{children}</div>
    </div>
  );
}

export function Card({ className, ...props }: ComponentProps<'section'>) {
  return <section className={cn('rounded-card border border-border bg-surface p-5 sm:p-6', className)} {...props} />;
}

export function Spinner({ label, className }: { label: string; className?: string }) {
  return (
    <span role="status" className={cn('inline-flex items-center gap-2 text-sm text-muted', className)}>
      <Loader2 aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" />
      {label}
    </span>
  );
}

/**
 * A modal dialog on the native <dialog> element: focus moves in, Escape
 * closes it unless `dismissible` is false, and the page behind stays
 * mounted (so a form under it keeps its input).
 */
export function Dialog({
  open,
  title,
  children,
  onClose,
  dismissible = true,
  className,
}: {
  open: boolean;
  title: ReactNode;
  children: ReactNode;
  onClose?: () => void;
  dismissible?: boolean;
  className?: string;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) {
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    }
    if (!open && dialog.open) {
      if (typeof dialog.close === 'function') dialog.close();
      else dialog.removeAttribute('open');
    }
  }, [open]);

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault();
        if (dismissible) onClose?.();
      }}
      className={cn(
        'm-auto w-[calc(100%-2rem)] max-w-md rounded-card border border-border bg-surface p-0 text-fg shadow-xl backdrop:bg-black/50',
        className,
      )}
    >
      {open ? (
        <div className="flex flex-col gap-4 p-5 sm:p-6">
          <h2 id={titleId} className="text-lg font-semibold">
            {title}
          </h2>
          {children}
        </div>
      ) : null}
    </dialog>
  );
}
