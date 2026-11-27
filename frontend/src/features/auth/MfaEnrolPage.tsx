import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { useSchoolCode } from '@/stores/school';
import { meKey, mfaEnrolment, usePublicConfig } from './api';
import { AuthLayout } from './AuthLayout';
import { MfaEnrolPanel } from './MfaEnrolPanel';

/** First sign-in (or after activation, or an MFA reset): set up the authenticator. */
export function MfaEnrolPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const code = useSchoolCode((s) => s.code);
  const { data: school } = usePublicConfig(code);

  return (
    <AuthLayout school={school ?? null} title={t('auth.enrol.title')} subtitle={t('auth.enrol.subtitle')}>
      <MfaEnrolPanel
        start={mfaEnrolment.start}
        confirm={mfaEnrolment.confirm}
        accountLabel={school?.name ?? code ?? ''}
        onFinished={async () => {
          await queryClient.invalidateQueries({ queryKey: meKey });
          navigate('/', { replace: true });
        }}
      />
    </AuthLayout>
  );
}
