import { ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Field, Input } from '@/components/ui/form';
import type { useStepUp } from './useStepUp';

/** The authenticator-code box shown when the server asks for step-up. */
export function StepUpField({ state }: { state: ReturnType<typeof useStepUp> }) {
  const { t } = useTranslation();
  if (!state.needed) return null;

  return (
    <div className="flex flex-col gap-2 rounded-input border border-border p-3">
      <p className="flex items-start gap-2 text-sm">
        <ShieldCheck aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-primary" />
        {t('stepUp.body')}
      </p>
      <Field label={t('auth.mfa.code')}>
        {(p) => (
          <Input {...p} value={state.code} onChange={(e) => state.setCode(e.target.value)} inputMode="numeric" maxLength={6} autoComplete="one-time-code" className="w-40 font-mono tracking-widest" />
        )}
      </Field>
    </div>
  );
}
