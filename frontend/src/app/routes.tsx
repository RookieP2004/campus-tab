import type { RouteObject } from 'react-router';
import { ActivatePage } from '@/features/auth/ActivatePage';
import { DeviceSessionsPage } from '@/features/auth/DeviceSessionsPage';
import { MfaEnrolPage } from '@/features/auth/MfaEnrolPage';
import { ParentOtpPage } from '@/features/auth/ParentOtpPage';
import { ForgotPasswordPage, SetPasswordPage } from '@/features/auth/PasswordPages';
import { RequireSession } from '@/features/auth/RequireSession';
import { SchoolCodePage } from '@/features/auth/SchoolCodePage';
import { SignInPage } from '@/features/auth/SignInPage';
import { ConsoleEnrolPage, ConsoleSetPasswordPage, ConsoleSignInPage } from '@/features/console/ConsoleAuthPages';
import { ConsoleLayout } from '@/features/console/ConsoleLayout';
import { ConsoleAlertsPage } from '@/features/console/ConsoleSchoolExtras';
import { ConsoleAuditPage, ConsoleSchoolPage, ConsoleSchoolsPage, ConsoleSessionsPage } from '@/features/console/ConsoleSchoolsPages';
import { CustomFieldsPage } from '@/features/customisation/CustomFieldsPage';
import { HomePage } from '@/features/home/HomePage';
import { NotFoundPage } from '@/features/home/NotFoundPage';
import { AdmitStudentPage } from '@/features/people/AdmitStudentPage';
import { ImportPage } from '@/features/people/ImportPage';
import { SetupCodesPage } from '@/features/people/SetupCodesPage';
import { StaffListPage, StaffProfilePage } from '@/features/people/StaffPages';
import { StudentListPage } from '@/features/people/StudentListPage';
import { StudentProfilePage } from '@/features/people/StudentProfilePage';
import { SetupWizardPage } from '@/features/setup/SetupWizardPage';
import { AppShell } from '@/features/shell/AppShell';

/**
 * Route table.
 *  - Before sign-in: school code, sign-in (password + MFA, or parent SMS
 *    code), password links, licence activation, MFA enrolment.
 *  - /console: the platform console (our team only), its own sign-in.
 *  - Everything else needs a signed-in school user (RequireSession), inside
 *    the shell. Each phase adds its screens as children of the shell.
 */
export const routes: RouteObject[] = [
  { path: '/school', element: <SchoolCodePage /> },
  { path: '/sign-in', element: <SignInPage /> },
  { path: '/sign-in/parent', element: <ParentOtpPage /> },
  { path: '/password/forgot', element: <ForgotPasswordPage /> },
  { path: '/password/:purpose', element: <SetPasswordPage /> },
  { path: '/activate', element: <ActivatePage /> },
  { path: '/mfa/enrol', element: <MfaEnrolPage /> },

  { path: '/console/sign-in', element: <ConsoleSignInPage /> },
  { path: '/console/enrol', element: <ConsoleEnrolPage /> },
  { path: '/console/set-password', element: <ConsoleSetPasswordPage /> },
  {
    path: '/console',
    element: <ConsoleLayout />,
    children: [
      { index: true, element: <ConsoleSchoolsPage /> },
      { path: 'schools/:code', element: <ConsoleSchoolPage /> },
      { path: 'alerts', element: <ConsoleAlertsPage /> },
      { path: 'audit', element: <ConsoleAuditPage /> },
      { path: 'sessions', element: <ConsoleSessionsPage /> },
    ],
  },

  {
    path: '/',
    element: (
      <RequireSession>
        <AppShell />
      </RequireSession>
    ),
    children: [
      { index: true, element: <HomePage /> },
      { path: 'account/sessions', element: <DeviceSessionsPage /> },
      { path: 'setup', element: <SetupWizardPage /> },
      { path: 'students', element: <StudentListPage /> },
      { path: 'students/new', element: <AdmitStudentPage /> },
      { path: 'students/:id', element: <StudentProfilePage /> },
      { path: 'staff', element: <StaffListPage /> },
      { path: 'staff/:id', element: <StaffProfilePage /> },
      { path: 'settings/custom-fields', element: <CustomFieldsPage /> },
      { path: 'imports/:entity', element: <ImportPage /> },
      { path: 'sections/:id/setup-codes', element: <SetupCodesPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
