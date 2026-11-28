import {
  Baby,
  FileText,
  Bell,
  Building2,
  CalendarDays,
  ClipboardList,
  Clock,
  IdCard,
  IndianRupee,
  ClipboardCheck,
  LayoutDashboard,
  type LucideIcon,
  Megaphone,
  Plane,
  GraduationCap,
  Trophy,
  MonitorSmartphone,
  Settings2,
  SlidersHorizontal,
  UserPlus,
  UserRound,
  Users,
} from 'lucide-react';
import type { Me } from '@/features/auth/api';
import { holds, scopeOf } from '@/features/auth/permissions';

/**
 * Shell navigation. An item shows when the person holds one of `permission`:
 * an ability in any scope ("students.view") or an exact ability:scope
 * ("results.view:child"). A longer ability that merely starts the same
 * (fees.receipt_cancel_request) does not count. Hiding is
 * only for tidiness, the server checks every request. Each phase adds its
 * screens here.
 */
export interface NavItemDefinition {
  to: string;
  label: string;
  icon: LucideIcon;
  permission?: string[];
  end?: boolean;
}

export const homeItem: NavItemDefinition = { to: '/', label: 'nav.home', icon: LayoutDashboard, end: true };

export const navItems: NavItemDefinition[] = [
  { to: '/attendance', label: 'nav.attendance', icon: ClipboardList, permission: ['attendance.view', 'attendance.mark'], end: true },
  { to: '/attendance/me', label: 'nav.myAttendance', icon: Clock, permission: ['staff_attendance.mark'] },
  { to: '/attendance/staff', label: 'nav.staffAttendance', icon: IdCard, permission: ['staff_attendance.manage', 'staff_attendance.enter', 'staff_attendance.view:school'] },
  { to: '/calendar', label: 'nav.calendar', icon: CalendarDays, permission: ['calendar.view'] },
  {
    to: '/tests',
    label: 'nav.tests',
    icon: GraduationCap,
    permission: ['marks.enter', 'marks.verify', 'marks.lock', 'results.publish', 'exams.schedule', 'tests.create', 'assessment.scheme', 'marks.correction_request', 'marks.correction_approve'],
  },
  { to: '/report-cards', label: 'nav.reportCards', icon: FileText, permission: ['results.publish', 'remarks.edit', 'report_templates.manage', 'year.promote'] },
  { to: '/results', label: 'nav.results', icon: Trophy, permission: ['results.view:child', 'results.view:own'] },
  { to: '/fees', label: 'nav.fees', icon: IndianRupee, permission: ['fees.dues_view', 'fees.collect', 'fees.setup', 'fees.reports', 'fees.gateway_view', 'fees.gateway_edit', 'fees.closing_confirm'] },
  { to: '/leave', label: 'nav.leave', icon: Plane, permission: ['leave.apply', 'student_leave.apply', 'leave.manage_types'] },
  {
    to: '/approvals',
    label: 'nav.approvals',
    icon: ClipboardCheck,
    permission: [
      'leave.verify', 'leave.decide', 'leave.decide_principal', 'leave.fast_track', 'student_leave.approve', 'marks.correction_approve',
      'fees.adjust_approve', 'fees.adjust', 'fees.receipt_cancel_approve', 'fees.receipt_cancel_propose', 'fees.receipt_cancel', 'fees.closing_confirm',
    ],
  },
  { to: '/notices', label: 'nav.notices', icon: Megaphone, permission: ['notices.view'] },
  { to: '/setup', label: 'nav.setup', icon: Settings2, permission: ['academic.manage'] },
  { to: '/students', label: 'nav.students', icon: Users, permission: ['students.view'] },
  { to: '/staff', label: 'nav.staff', icon: UserRound, permission: ['staff.view'] },
  { to: '/settings/school', label: 'nav.schoolSettings', icon: Building2, permission: ['config.view', 'config.edit'] },
  { to: '/settings/custom-fields', label: 'nav.customFields', icon: SlidersHorizontal, permission: ['custom_fields.manage', 'custom_fields.clerical'] },
];

/** Account screens every signed-in person has. */
export const accountItems: NavItemDefinition[] = [
  { to: '/account/notifications', label: 'nav.notificationSettings', icon: Bell },
  { to: '/account/sessions', label: 'nav.devices', icon: MonitorSmartphone },
];

export function visibleNavItems(me: Pick<Me, 'permissions'>): NavItemDefinition[] {
  return navItems.filter((item) => !item.permission || me.permissions.some((p) => item.permission?.some((needed) => p === needed || p.startsWith(`${needed}:`))));
}

/**
 * The role's main job, the second bottom tab on phones ("two taps to the
 * daily job"): Attendance for class teachers, check-in for staff, approvals
 * for the Principal; other roles get their closest screen.
 */
export function mainJob(role: string | null | undefined, me: Pick<Me, 'permissions' | 'scope'>): NavItemDefinition | null {
  const scope = scopeOf(me);

  switch (role) {
    case 'parent':
      return scope.children[0] ? { to: `/students/${scope.children[0]}`, label: 'nav.myChild', icon: Baby } : null;
    case 'student':
      return null;
    case 'class_teacher':
      if (holds(me, 'attendance.mark')) return { to: '/attendance', label: 'nav.attendance', icon: ClipboardList };
      return holds(me, 'students.view') ? { to: '/students', label: 'nav.myStudents', icon: Users } : null;
    case 'staff':
      if (holds(me, 'staff_attendance.mark')) return { to: '/attendance/me', label: 'nav.myAttendance', icon: Clock };
      break;
    case 'subject_teacher':
      return holds(me, 'students.view') ? { to: '/students', label: 'nav.myStudents', icon: Users } : null;
    case 'chief':
    case 'principal':
    case 'vice_principal':
      if (holds(me, 'leave.decide') || holds(me, 'leave.decide_principal')) return { to: '/approvals', label: 'nav.approvals', icon: ClipboardCheck };
      if (holds(me, 'staff.approve')) return { to: '/staff?status=draft', label: 'nav.approvals', icon: ClipboardCheck };
      break;
    case 'clerk':
      if (holds(me, 'students.admit')) return { to: '/students/new', label: 'nav.admissions', icon: UserPlus };
      break;
    default:
      break;
  }

  return visibleNavItems(me).find((item) => item.to !== '/notices') ?? null;
}
