import { useQueryClient } from '@tanstack/react-query';
import { ClipboardList, FileUp, type LucideIcon, Megaphone, UserPlus, UserRoundPlus } from 'lucide-react';
import { useNavigate } from 'react-router';
import { signIn, type Me } from '@/features/auth/api';
import { holds } from '@/features/auth/permissions';
import { useNotifications } from '@/features/notifications/api';
import { useSocketState } from '@/features/notifications/socket';

/** The unread count, from the socket's nudges or the 60 s fallback poll. */
export function useUnreadCount(): number {
  const connected = useSocketState((s) => s.connected);
  const { data } = useNotifications(!connected);
  return data?.unread ?? 0;
}

/** Signs out, forgets every cached answer and goes to sign-in. */
export function useSignOut() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  return async () => {
    await signIn.logout().catch(() => undefined);
    queryClient.clear();
    navigate('/sign-in', { replace: true });
  };
}

export interface CreateAction {
  to: string;
  label: string;
  icon: LucideIcon;
}

/** Quick-create actions: only what the person's permissions allow (the server checks again). */
export function createActions(me: Pick<Me, 'permissions'>): CreateAction[] {
  const actions: CreateAction[] = [];
  if (holds(me, 'attendance.mark')) actions.push({ to: '/attendance', label: 'quickCreate.attendance', icon: ClipboardList });
  if (holds(me, 'students.admit')) actions.push({ to: '/students/new', label: 'quickCreate.admit', icon: UserPlus });
  if (holds(me, 'notices.send')) actions.push({ to: '/notices/new', label: 'quickCreate.notice', icon: Megaphone });
  if (holds(me, 'staff.create') || holds(me, 'staff.draft')) actions.push({ to: '/staff?new=1', label: 'quickCreate.staff', icon: UserRoundPlus });
  if (holds(me, 'imports.students')) actions.push({ to: '/imports/students', label: 'quickCreate.import', icon: FileUp });
  return actions;
}
