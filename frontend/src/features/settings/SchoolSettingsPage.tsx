import { TypedConfirmDialog } from '@campus-tab/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { Switch } from '@/components/ui/switch';
import { errorText, fieldError } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { ApiError, apiGet } from '@/lib/api';
import { send } from '@/lib/requests';

interface SchoolConfig {
  version: number;
  body: {
    school: { name: string; short_name: string; logo_url?: string | null; board: string; affiliation_no?: string | null; grievance?: { name: string; role?: string | null; email: string; phone?: string | null; address?: string | null } | null };
    branding: { accent: string };
    modules: Record<string, boolean>;
    sign_in: { methods: string[] };
  } & Record<string, unknown>;
}

const BOARDS = ['CBSE', 'ICSE', 'STATE', 'IB', 'CAMBRIDGE', 'OTHER'];
const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
const LOGO_MAX_BYTES = 512 * 1024;
const STUDENT_LOGIN = 'student_login';
const configKey = ['config'] as const;

/**
 * School settings: identity and branding, modules and student sign-in.
 * The Chief (config.edit) changes them; a Principal sees them read-only.
 * Saves send If-Match with the version loaded, so two people editing at
 * once get a conflict instead of overwriting each other. The server
 * decides what the licence allows (module_not_licensed). The dashboard's
 * "New module available" card links here.
 */
export function SchoolSettingsPage() {
  const { t } = useTranslation();
  const config = useQuery({ queryKey: configKey, queryFn: apiGet<SchoolConfig>('/config'), retry: false });

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t('settings.school.title')}</h1>
        <p className="mt-1 text-sm text-muted">{t('settings.school.intro')}</p>
      </div>
      {config.isPending ? <Spinner label={t('common.loading')} /> : null}
      {config.isError ? <Alert tone="danger">{errorText(t, config.error)}</Alert> : null}
      {config.data ? <SettingsForm key={config.data.version} config={config.data} /> : null}
    </div>
  );
}

function SettingsForm({ config }: { config: SchoolConfig }) {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const canEdit = holds(me, 'config.edit');
  const school = config.body.school;
  const [form, setForm] = useState({
    name: school.name,
    short_name: school.short_name,
    board: school.board,
    affiliation_no: school.affiliation_no ?? '',
    accent: config.body.branding?.accent ?? '#0F766E',
    g_name: school.grievance?.name ?? '',
    g_role: school.grievance?.role ?? '',
    g_email: school.grievance?.email ?? '',
    g_phone: school.grievance?.phone ?? '',
  });
  const [modules, setModules] = useState<Record<string, boolean>>({ ...(config.body.modules ?? {}) });
  const [studentLogin, setStudentLogin] = useState((config.body.sign_in?.methods ?? []).includes(STUDENT_LOGIN));
  const [switchingOff, setSwitchingOff] = useState<string | null>(null);
  const accentValid = /^#[0-9a-f]{6}$/i.test(form.accent);

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: configKey });
    void queryClient.invalidateQueries({ queryKey: ['public-config'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  };

  const save = useMutation({
    mutationFn: () => {
      const methods = (config.body.sign_in?.methods ?? []).filter((m) => m !== STUDENT_LOGIN);
      return send<SchoolConfig>('PUT', '/config', {
        // The whole body goes back, with only these sections changed.
        body: {
          body: {
            ...config.body,
            school: { ...school, name: form.name.trim(), short_name: form.short_name.trim(), board: form.board, affiliation_no: form.affiliation_no.trim() || null,
              // DPDP grievance contact: shown on the school profile and everyone's Privacy page.
              grievance: form.g_name.trim() && form.g_email.trim() ? { name: form.g_name.trim(), role: form.g_role.trim() || null, email: form.g_email.trim(), phone: form.g_phone.trim() || null } : null,
            },
            branding: { ...config.body.branding, accent: form.accent.toUpperCase() },
            modules,
            sign_in: { ...config.body.sign_in, methods: studentLogin ? [...methods, STUDENT_LOGIN] : methods },
          },
          reason: 'School settings',
        },
        headers: { 'If-Match': `"v${config.version}"` },
      });
    },
    onSuccess: refresh,
  });

  const conflict = save.error instanceof ApiError && save.error.code === 'config_conflict';
  const moduleLabel = (m: string) => t(`modules.${m}`, { defaultValue: m });

  return (
    <>
    <form
      className="flex flex-col gap-4"
      noValidate
      onSubmit={(e) => {
        e.preventDefault();
        if (canEdit && accentValid) save.mutate();
      }}
    >
      {!canEdit ? <Alert tone="info">{t('settings.school.readOnly')}</Alert> : null}
      {save.isError ? (
        <Alert tone="danger">
          <div className="flex flex-wrap items-center gap-3">
            <span className="flex-1">{errorText(t, save.error)}</span>
            {conflict ? (
              <Button type="button" variant="outline" size="sm" className="h-11" onClick={() => void refresh()}>
                <RefreshCw aria-hidden="true" />
                {t('settings.school.reload')}
              </Button>
            ) : null}
          </div>
        </Alert>
      ) : null}
      {save.isSuccess ? <Alert tone="success">{t('settings.school.saved')}</Alert> : null}

      <Card className="flex flex-col gap-4">
        <h2 className="text-lg font-semibold">{t('settings.school.identity')}</h2>
        <fieldset disabled={!canEdit} className="grid gap-4 sm:grid-cols-2">
          <Field label={t('setup.profile.name')} error={fieldError(save.error, 'body.school.name')}>
            {(p) => <Input {...p} value={form.name} maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
          </Field>
          <Field label={t('setup.profile.shortName')}>
            {(p) => <Input {...p} value={form.short_name} maxLength={20} onChange={(e) => setForm({ ...form, short_name: e.target.value })} />}
          </Field>
          <Field label={t('setup.profile.board')}>
            {(p) => (
              <Select {...p} value={form.board} onChange={(e) => setForm({ ...form, board: e.target.value })}>
                {BOARDS.map((b) => (
                  <option key={b} value={b}>
                    {t(`setup.profile.boards.${b}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t('setup.profile.affiliation')}>
            {(p) => <Input {...p} value={form.affiliation_no} maxLength={40} onChange={(e) => setForm({ ...form, affiliation_no: e.target.value })} />}
          </Field>
          <Field label={t('setup.profile.accent')} hint={t('setup.profile.accentHint')} error={accentValid ? undefined : t('settings.school.accentInvalid')}>
            {(p) => (
              <div className="flex items-center gap-2">
                <input
                  type="color"
                  aria-label={t('settings.school.accentPicker')}
                  value={accentValid ? form.accent : '#000000'}
                  onChange={(e) => setForm({ ...form, accent: e.target.value.toUpperCase() })}
                  className="h-11 w-14 shrink-0 rounded-input border border-border bg-surface"
                />
                <Input {...p} value={form.accent} maxLength={7} className="font-mono" onChange={(e) => setForm({ ...form, accent: e.target.value })} />
              </div>
            )}
          </Field>
          <h3 className="font-semibold sm:col-span-2">{t('settings.school.grievance')}</h3>
          <p className="text-sm text-muted sm:col-span-2">{t('settings.school.grievanceHint')}</p>
          <Field label={t('privacy.grievance.name')} error={fieldError(save.error, 'body.school.grievance.name')}>
            {(p) => <Input {...p} value={form.g_name} maxLength={100} onChange={(e) => setForm({ ...form, g_name: e.target.value })} />}
          </Field>
          <Field label={t('settings.school.grievanceRole')}>{(p) => <Input {...p} value={form.g_role} maxLength={60} onChange={(e) => setForm({ ...form, g_role: e.target.value })} />}</Field>
          <Field label={t('privacy.grievance.email')} error={fieldError(save.error, 'body.school.grievance.email')}>
            {(p) => <Input {...p} type="email" value={form.g_email} maxLength={120} onChange={(e) => setForm({ ...form, g_email: e.target.value })} />}
          </Field>
          <Field label={t('privacy.grievance.phone')}>{(p) => <Input {...p} type="tel" value={form.g_phone} maxLength={20} onChange={(e) => setForm({ ...form, g_phone: e.target.value })} />}</Field>
        </fieldset>
        <LogoUpload logoUrl={school.logo_url ?? null} canEdit={canEdit} onUploaded={refresh} />
      </Card>

      <Card className="flex flex-col gap-2">
        <h2 className="text-lg font-semibold">{t('settings.school.modules')}</h2>
        <p className="text-sm text-muted">{t('settings.school.modulesIntro')}</p>
        <fieldset className="flex flex-col divide-y divide-border">
          <legend className="sr-only">{t('settings.school.modules')}</legend>
          {Object.keys(modules).map((m) => (
            <Switch
              key={m}
              label={moduleLabel(m)}
              disabled={!canEdit}
              checked={modules[m] === true}
              onChange={(on) => (on ? setModules({ ...modules, [m]: true }) : setSwitchingOff(m))}
            />
          ))}
        </fieldset>
      </Card>

      <Card className="flex flex-col gap-2">
        <h2 className="text-lg font-semibold">{t('settings.school.signIn')}</h2>
        <Switch label={t('settings.school.studentLogin')} hint={t('settings.school.studentLoginHint')} disabled={!canEdit} checked={studentLogin} onChange={setStudentLogin} />
      </Card>

      {canEdit ? (
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending || !accentValid}>
            {save.isPending ? t('common.working') : t('common.save')}
          </Button>
        </div>
      ) : null}

    </form>

      <TypedConfirmDialog
        open={switchingOff !== null}
        title={t('settings.school.moduleOff.title', { module: switchingOff ? moduleLabel(switchingOff) : '' })}
        description={t('settings.school.moduleOff.body')}
        word={switchingOff ? moduleLabel(switchingOff) : ''}
        inputLabel={t('settings.school.moduleOff.type', { word: switchingOff ? moduleLabel(switchingOff) : '' })}
        confirmLabel={t('settings.school.moduleOff.confirm')}
        cancelLabel={t('common.cancel')}
        onCancel={() => setSwitchingOff(null)}
        onConfirm={() => {
          if (switchingOff) setModules({ ...modules, [switchingOff]: false });
          setSwitchingOff(null);
        }}
      />
    </>
  );
}

function LogoUpload({ logoUrl, canEdit, onUploaded }: { logoUrl: string | null; canEdit: boolean; onUploaded: () => Promise<void> }) {
  const { t } = useTranslation();
  const id = useId();
  const [file, setFile] = useState<File | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const upload = useMutation({
    mutationFn: (logo: File) => {
      const data = new FormData();
      data.append('file', logo);
      return send<{ logo_url: string; version: number }>('POST', '/config/logo', { body: data });
    },
    onSuccess: async () => {
      setFile(null);
      await onUploaded();
    },
  });

  const pick = (chosen: File | null) => {
    setProblem(null);
    setFile(null);
    if (!chosen) return;
    if (!LOGO_TYPES.includes(chosen.type)) setProblem(t('setup.profile.logoType'));
    else if (chosen.size > LOGO_MAX_BYTES) setProblem(t('setup.profile.logoSize'));
    else setFile(chosen);
  };

  return (
    <div className="flex flex-wrap items-end gap-4 border-t border-border pt-4">
      {logoUrl ? <img src={logoUrl} alt={t('setup.profile.logoAlt')} className="size-16 rounded-full border border-border object-cover" /> : null}
      <div className="flex min-w-60 flex-1 flex-col gap-1.5">
        <label htmlFor={id} className="text-sm font-medium">
          {t('setup.profile.logoFile')}
        </label>
        <input
          id={id}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          disabled={!canEdit}
          aria-describedby={`${id}-hint`}
          onChange={(e) => pick(e.target.files?.[0] ?? null)}
          className="min-h-11 text-sm file:me-3 file:h-11 file:rounded-input file:border file:border-border file:bg-surface file:px-3 file:text-fg"
        />
        <p id={`${id}-hint`} className="text-xs text-muted">
          {t('setup.profile.logoHint')}
        </p>
        {problem ? (
          <p role="alert" className="text-sm text-danger">
            {problem}
          </p>
        ) : null}
        {upload.isError ? <Alert tone="danger">{errorText(t, upload.error)}</Alert> : null}
        {upload.isSuccess ? <Alert tone="success">{t('setup.profile.logoSaved')}</Alert> : null}
      </div>
      {canEdit ? (
        <Button type="button" variant="outline" disabled={!file || upload.isPending} onClick={() => file && upload.mutate(file)}>
          {t('setup.profile.upload')}
        </Button>
      ) : null}
    </div>
  );
}
