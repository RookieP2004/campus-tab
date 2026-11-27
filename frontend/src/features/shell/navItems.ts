import {
  Baby,
  Bell,
  Building2,
  CalendarDays,
  ClipboardList,
  Clock,
  IdCard,
  ClipboardCheck,
  LayoutDashboard,
  type LucideIcon,
  Megaphone,
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
 * Shell navigation. An item shows when the person holds any permission that
 * starts with one of `permission` (for example "students.view"); hiding is
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
  return navItems.filter((item) => !item.permission || me.permissions.some((p) => item.permission?.some((needed) => p.startsWith(needed))));
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
