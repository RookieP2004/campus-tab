import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useLocation, useNavigate } from 'react-router';
import { Spinner } from '@/components/ui/form';
import { useSchoolCode } from '@/stores/school';
import { useSessionState } from '@/stores/session';
import { meKey, usePublicConfig } from './api';
import { AuthLayout } from './AuthLayout';
import { SignInForm } from './SignInForm';

/** Sign-in for the chosen school: password, then the MFA code. */
export function SignInPage() {
  const { t } = useTranslation();
  const code = useSchoolCode((s) => s.code);
  const { data: school, isPending, isError } = usePublicConfig(code);
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const resume = useSessionState((s) => s.resume);

  if (!code || isError) return <Navigate to="/school" replace />;

  const from = (location.state as { from?: string } | null)?.from ?? '/';

  return (
    <AuthLayout school={school ?? null} title={t('auth.signIn.title')} subtitle={school ? t('auth.signIn.subtitle', { school: school.name }) : undefined}>
      {isPending ? (
        <Spinner label={t('common.loading')} />
      ) : (
        <>
          <SignInForm
            schoolCode={code}
            onSignedIn={async (result) => {
              resume();
              if (result.mfa_enrolment_required) {
                navigate('/mfa/enrol', { replace: true });
                return;
              }
              await queryClient.invalidateQueries({ queryKey: meKey });
              navigate(from, { replace: true });
            }}
          />
          <div className="mt-6 flex flex-col gap-2 border-t border-border pt-4 text-sm">
            {school?.login_methods.includes('parent_otp') ? (
              <Link to="/sign-in/parent" className="text-primary underline-offset-2 hover:underline">
                {t('auth.signIn.parentLink')}
              </Link>
            ) : null}
            <Link to="/password/forgot" className="text-primary underline-offset-2 hover:underline">
              {t('auth.signIn.forgot')}
            </Link>
            <Link to="/school" className="text-muted underline-offset-2 hover:underline">
              {t('auth.signIn.otherSchool')}
            </Link>
          </div>
        </>
      )}
    </AuthLayout>
  );
}
