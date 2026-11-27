import { type LucideIcon, Settings2, SlidersHorizontal, UserRound, Users } from 'lucide-react';

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
}

export const navItems: NavItemDefinition[] = [
  { to: '/setup', label: 'nav.setup', icon: Settings2, permission: ['academic.manage'] },
  { to: '/students', label: 'nav.students', icon: Users, permission: ['students.view'] },
  { to: '/staff', label: 'nav.staff', icon: UserRound, permission: ['staff.view'] },
  { to: '/settings/custom-fields', label: 'nav.customFields', icon: SlidersHorizontal, permission: ['custom_fields.manage', 'custom_fields.clerical'] },
];
