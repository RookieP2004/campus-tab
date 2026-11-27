import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input } from '@/components/ui/form';
import { ApiError, api, unwrap } from '@/lib/api';
import { useSchoolCode } from '@/stores/school';
import { type PublicConfig } from './api';
import { AuthLayout } from './AuthLayout';

/**
 * First screen: the school code (for example SUNRISE-BPL). It loads only
 * the school's public branding; nothing private loads before sign-in.
 */
export function SchoolCodePage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const remembered = useSchoolCode((s) => s.code);
  const setCode = useSchoolCode((s) => s.setCode);
  const [code, setValue] = useState(remembered ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const normalised = code.trim().toUpperCase();
    if (!/^[A-Z0-9]+(-[A-Z0-9]+)*$/.test(normalised) || normalised.length < 3) {
      setError(t('auth.school.invalid'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const config = await unwrap<PublicConfig>(api.GET('/public/tenants/{code}/config', { params: { path: { code: normalised } } }));
      queryClient.setQueryData(['public-config', normalised], config);
      setCode(normalised);
      navigate('/sign-in');
    } catch (e) {
      setError(e instanceof ApiError && e.status === 404 ? t('auth.school.notFound') : t('auth.apiErrors.network'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout title={t('auth.school.title')} subtitle={t('auth.school.subtitle')}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label={t('auth.school.code')} hint={t('auth.school.hint')}>
          {(props) => (
            <Input
              {...props}
              value={code}
              onChange={(e) => setValue(e.target.value.toUpperCase())}
              autoCapitalize="characters"
              autoComplete="organization"
              placeholder="SUNRISE-BPL"
              className="font-mono uppercase"
            />
          )}
        </Field>
        <Button type="submit" disabled={busy}>
          {busy ? t('common.working') : t('auth.school.continue')}
        </Button>
      </form>
      <div className="mt-6 flex flex-col gap-2 border-t border-border pt-4 text-sm">
        <Link to="/activate" className="text-primary underline-offset-2 hover:underline">
          {t('auth.school.activate')}
        </Link>
      </div>
    </AuthLayout>
  );
}
