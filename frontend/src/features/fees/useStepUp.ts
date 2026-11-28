import { useState } from 'react';
import { signIn } from '@/features/auth/api';
import { ApiError } from '@/lib/api';

/**
 * Runs a sensitive action; when the server answers step_up_required, asks
 * for a fresh authenticator code, confirms it, and runs the action again.
 * The server decides which actions need it (approving a fee adjustment or a
 * receipt cancellation, exporting a report).
 */
/** True for the answer that only asks for a fresh code (the step-up field already says so). */
export function isStepUpRequest(e: unknown): boolean {
  return e instanceof ApiError && e.code === 'step_up_required';
}

export function useStepUp() {
  const [needed, setNeeded] = useState(false);
  const [code, setCode] = useState('');

  const run = async <T,>(action: () => Promise<T>): Promise<T> => {
    if (needed) {
      await signIn.stepUp(code.trim());
      setNeeded(false);
      setCode('');
    }
    try {
      return await action();
    } catch (e) {
      if (isStepUpRequest(e)) {
        setNeeded(true);
        setCode('');
      }
      throw e;
    }
  };

  return { needed, code, setCode, run, ready: !needed || /^\d{6}$/.test(code.trim()) };
}
