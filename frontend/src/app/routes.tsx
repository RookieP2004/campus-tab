import type { ComponentType } from 'react';
import type { RouteObject } from 'react-router';
import { RequireSession } from '@/features/auth/RequireSession';
import { AppShell } from '@/features/shell/AppShell';

/**
 * Loads one screen's code only when its route is opened, so the first
 * download stays small on low-end phones and slow networks. The shell and
 * the session guard stay in the main bundle (every signed-in screen needs
 * them); every page, and heavy libraries such as TanStack Table or the QR
 * code, come in their own chunks.
 */
function page<M extends Record<string, unknown>>(load: () => Promise<M>, name: keyof M & string): RouteObject['lazy'] {
  return async () => ({ Component: (await load())[name] as ComponentType });
}

/**
 * Route table.
 *  - Before sign-in: school code, sign-in (password + MFA, or parent SMS
 *    code), password links, licence activation, MFA enrolment.
 *  - /console: the platform console (our team only), its own sign-in.
 *  - Everything else needs a signed-in school user (RequireSession), inside
 *    the shell. Each phase adds its screens as children of the shell, with
 *    page(() => import(...), 'Name') so they stay in their own chunk.
 */
export const routes: RouteObject[] = [
  { path: '/school', lazy: page(() => import('@/features/auth/SchoolCodePage'), 'SchoolCodePage') },
  { path: '/sign-in', lazy: page(() => import('@/features/auth/SignInPage'), 'SignInPage') },
  { path: '/sign-in/parent', lazy: page(() => import('@/features/auth/ParentOtpPage'), 'ParentOtpPage') },
  { path: '/password/forgot', lazy: page(() => import('@/features/auth/PasswordPages'), 'ForgotPasswordPage') },
  { path: '/password/:purpose', lazy: page(() => import('@/features/auth/PasswordPages'), 'SetPasswordPage') },
  { path: '/activate', lazy: page(() => import('@/features/auth/ActivatePage'), 'ActivatePage') },
  { path: '/mfa/enrol', lazy: page(() => import('@/features/auth/MfaEnrolPage'), 'MfaEnrolPage') },

  // The page a report card's QR code opens: public, no sign-in.
  { path: '/verify/:school/:code', lazy: page(() => import('@/features/reports/VerifyPage'), 'VerifyPage') },
  { path: '/console/sign-in', lazy: page(() => import('@/features/console/ConsoleAuthPages'), 'ConsoleSignInPage') },
  { path: '/console/enrol', lazy: page(() => import('@/features/console/ConsoleAuthPages'), 'ConsoleEnrolPage') },
  { path: '/console/set-password', lazy: page(() => import('@/features/console/ConsoleAuthPages'), 'ConsoleSetPasswordPage') },
  {
    path: '/console',
    lazy: page(() => import('@/features/console/ConsoleLayout'), 'ConsoleLayout'),
    children: [
      { index: true, lazy: page(() => import('@/features/console/ConsoleSchoolsPages'), 'ConsoleSchoolsPage') },
      { path: 'schools/:code', lazy: page(() => import('@/features/console/ConsoleSchoolsPages'), 'ConsoleSchoolPage') },
      { path: 'alerts', lazy: page(() => import('@/features/console/ConsoleSchoolExtras'), 'ConsoleAlertsPage') },
      { path: 'audit', lazy: page(() => import('@/features/console/ConsoleSchoolsPages'), 'ConsoleAuditPage') },
      { path: 'sessions', lazy: page(() => import('@/features/console/ConsoleSchoolsPages'), 'ConsoleSessionsPage') },
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
      { index: true, lazy: page(() => import('@/features/dashboard/DashboardPage'), 'DashboardPage') },
      { path: 'notices', lazy: page(() => import('@/features/notices/NoticePages'), 'NoticeListPage') },
      { path: 'notices/new', lazy: page(() => import('@/features/notices/NoticePages'), 'NoticeComposerPage') },
      { path: 'notices/:id', lazy: page(() => import('@/features/notices/NoticePages'), 'NoticeDetailPage') },
      { path: 'notifications', lazy: page(() => import('@/features/notifications/NotificationPages'), 'InboxPage') },
      { path: 'account/notifications', lazy: page(() => import('@/features/notifications/NotificationPages'), 'NotificationSettingsPage') },
      { path: 'more', lazy: page(() => import('@/features/shell/MorePage'), 'MorePage') },
      { path: 'settings/school', lazy: page(() => import('@/features/settings/SchoolSettingsPage'), 'SchoolSettingsPage') },
      { path: 'attendance', lazy: page(() => import('@/features/attendance/AttendanceHomePage'), 'AttendanceHomePage') },
      { path: 'attendance/summary', lazy: page(() => import('@/features/attendance/ReportPages'), 'AttendanceSummaryPage') },
      { path: 'attendance/register', lazy: page(() => import('@/features/attendance/ReportPages'), 'AttendanceRegisterPage') },
      { path: 'attendance/me', lazy: page(() => import('@/features/attendance/StaffPages'), 'MyAttendancePage') },
      { path: 'attendance/staff', lazy: page(() => import('@/features/attendance/StaffPages'), 'StaffAttendancePage') },
      { path: 'attendance/staff/qr', lazy: page(() => import('@/features/attendance/StaffPages'), 'StaffQrPage') },
      { path: 'attendance/:sectionId', lazy: page(() => import('@/features/attendance/TakerPage'), 'AttendanceTakerPage') },
      { path: 'leave', lazy: page(() => import('@/features/leave/LeavePages'), 'LeaveHomePage') },
      { path: 'leave/apply', lazy: page(() => import('@/features/leave/LeavePages'), 'ApplyLeavePage') },
      { path: 'leave/child', lazy: page(() => import('@/features/leave/LeavePages'), 'ChildLeavePage') },
      { path: 'leave/calendar', lazy: page(() => import('@/features/leave/LeavePages'), 'LeaveCalendarPage') },
      { path: 'leave/requests/:id', lazy: page(() => import('@/features/leave/LeavePages'), 'LeaveRequestPage') },
      { path: 'leave/settings', lazy: page(() => import('@/features/leave/LeaveSettingsPage'), 'LeaveSettingsPage') },
      { path: 'tests', lazy: page(() => import('@/features/assessment/TestsPages'), 'TestsHomePage') },
      { path: 'tests/new', lazy: page(() => import('@/features/assessment/TestsPages'), 'NewAssessmentPage') },
      { path: 'tests/corrections', lazy: page(() => import('@/features/assessment/TestsPages'), 'CorrectionsPage') },
      { path: 'tests/schemes', lazy: page(() => import('@/features/assessment/SchemesPage'), 'SchemesPage') },
      { path: 'tests/papers/:id', lazy: page(() => import('@/features/assessment/MarkbookPage'), 'MarkbookPage') },
      { path: 'tests/:id', lazy: page(() => import('@/features/assessment/TestsPages'), 'AssessmentPage') },
      { path: 'results', lazy: page(() => import('@/features/assessment/TestsPages'), 'ResultsPage') },
      { path: 'report-cards', lazy: page(() => import('@/features/reports/ReportCardPages'), 'ReportCardsHomePage') },
      { path: 'report-cards/templates', lazy: page(() => import('@/features/reports/TemplatesPage'), 'TemplatesPage') },
      { path: 'report-cards/sections/:id', lazy: page(() => import('@/features/reports/ReportCardPages'), 'SectionCardsPage') },
      { path: 'report-cards/:id', lazy: page(() => import('@/features/reports/ReportCardPages'), 'ReportCardPage') },
      { path: 'year-end', lazy: page(() => import('@/features/reports/PromotionPage'), 'PromotionPage') },
      { path: 'fees', lazy: page(() => import('@/features/fees/FeesHomePage'), 'FeesHomePage') },
      { path: 'fees/my', lazy: page(() => import('@/features/fees/MyFeesPage'), 'MyFeesPage') },
      { path: 'fees/counter', lazy: page(() => import('@/features/fees/CounterPage'), 'CounterPage') },
      { path: 'fees/closings', lazy: page(() => import('@/features/fees/ClosingsPage'), 'ClosingsPage') },
      { path: 'fees/adjustments', lazy: page(() => import('@/features/fees/AdjustmentsPage'), 'AdjustmentsPage') },
      { path: 'fees/reports', lazy: page(() => import('@/features/fees/ReportsPage'), 'ReportsPage') },
      { path: 'fees/setup', lazy: page(() => import('@/features/fees/SetupPage'), 'SetupPage') },
      { path: 'fees/online', lazy: page(() => import('@/features/fees/OnlinePage'), 'OnlinePage') },
      { path: 'fees/students/:id', lazy: page(() => import('@/features/fees/StudentFeesPage'), 'StudentFeesPage') },
      { path: 'fees/receipts/:id', lazy: page(() => import('@/features/fees/ReceiptPage'), 'ReceiptPage') },
      { path: 'approvals', lazy: page(() => import('@/features/approvals/ApprovalsPage'), 'ApprovalsPage') },
      { path: 'calendar', lazy: page(() => import('@/features/attendance/CalendarPage'), 'CalendarPage') },
      { path: 'account/sessions', lazy: page(() => import('@/features/auth/DeviceSessionsPage'), 'DeviceSessionsPage') },
      { path: 'setup', lazy: page(() => import('@/features/setup/SetupWizardPage'), 'SetupWizardPage') },
      { path: 'students', lazy: page(() => import('@/features/people/StudentListPage'), 'StudentListPage') },
      { path: 'students/new', lazy: page(() => import('@/features/people/AdmitStudentPage'), 'AdmitStudentPage') },
      { path: 'students/:id', lazy: page(() => import('@/features/people/StudentProfilePage'), 'StudentProfilePage') },
      { path: 'staff', lazy: page(() => import('@/features/people/StaffPages'), 'StaffListPage') },
      { path: 'staff/:id', lazy: page(() => import('@/features/people/StaffPages'), 'StaffProfilePage') },
      { path: 'settings/custom-fields', lazy: page(() => import('@/features/customisation/CustomFieldsPage'), 'CustomFieldsPage') },
      { path: 'imports/:entity', lazy: page(() => import('@/features/people/ImportPage'), 'ImportPage') },
      { path: 'sections/:id/setup-codes', lazy: page(() => import('@/features/people/SetupCodesPage'), 'SetupCodesPage') },
      { path: '*', lazy: page(() => import('@/features/home/NotFoundPage'), 'NotFoundPage') },
    ],
  },
];
