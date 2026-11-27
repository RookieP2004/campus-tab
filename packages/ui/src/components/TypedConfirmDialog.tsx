import { type ReactNode, useEffect, useId, useRef, useState } from 'react';

export interface TypedConfirmDialogProps {
  open: boolean;
  title: string;
  /** What will happen, in plain words. */
  description?: ReactNode;
  /** The word the person must type, like the record's name. */
  word: string;
  /** Label of the text box, like "Type Class 6-A to confirm". */
  inputLabel: string;
  confirmLabel: string;
  cancelLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
  /** While the action runs: the button stays disabled. */
  busy?: boolean;
}

/**
 * A destructive action that makes the person type a given word first. The
 * confirm button stays disabled until the typed text matches exactly
 * (ignoring spaces at either end). Built on the native <dialog>, so focus
 * moves in and Escape cancels.
 */
export function TypedConfirmDialog({
  open,
  title,
  description,
  word,
  inputLabel,
  confirmLabel,
  cancelLabel,
  onConfirm,
  onCancel,
  busy = false,
}: TypedConfirmDialogProps) {
  const ref = useRef<HTMLDialogElement>(null);
  const id = useId();
  const [typed, setTyped] = useState('');
  const matches = typed.trim() === word.trim() && word.trim() !== '';

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) {
      setTyped('');
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
      aria-labelledby={`${id}-title`}
      aria-describedby={description ? `${id}-desc` : undefined}
      onCancel={(event) => {
        event.preventDefault();
        onCancel();
      }}
      className="m-auto w-[calc(100%-2rem)] max-w-md rounded-card border border-border bg-surface p-0 text-fg shadow-xl backdrop:bg-black/50"
    >
      {open ? (
        <form
          className="flex flex-col gap-4 p-5 sm:p-6"
          onSubmit={(event) => {
            event.preventDefault();
            if (matches && !busy) onConfirm();
          }}
        >
          <h2 id={`${id}-title`} className="text-lg font-semibold">
            {title}
          </h2>
          {description ? (
            <div id={`${id}-desc`} className="text-sm text-muted">
              {description}
            </div>
          ) : null}
          <div className="flex flex-col gap-1.5">
            <label htmlFor={`${id}-input`} className="text-sm font-medium">
              {inputLabel}
            </label>
            <input
              id={`${id}-input`}
              value={typed}
              onChange={(event) => setTyped(event.target.value)}
              autoComplete="off"
              spellCheck={false}
              className="h-11 w-full rounded-input border border-border bg-surface px-3 text-base text-fg focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            />
          </div>
          <div className="flex flex-wrap justify-end gap-2">
            <button
              type="button"
              onClick={onCancel}
              className="inline-flex h-11 items-center justify-center rounded-input border border-border bg-surface px-4 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {cancelLabel}
            </button>
            <button
              type="submit"
              disabled={!matches || busy}
              className="inline-flex h-11 items-center justify-center rounded-input bg-danger-solid px-4 text-sm font-medium text-on-solid hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50"
            >
              {confirmLabel}
            </button>
          </div>
        </form>
      ) : null}
    </dialog>
  );
}
