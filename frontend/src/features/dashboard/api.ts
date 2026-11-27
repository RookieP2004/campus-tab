import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { Me } from '@/features/auth/api';
import { apiGet } from '@/lib/api';
import { useChosenRole } from '@/stores/view';

/**
 * One card of GET /me/dashboard. The server builds every card from what the
 * person may see (Rule 1): the screen renders what it receives, in order,
 * and never filters or hides a card itself.
 */
export interface DashboardCard {
  id: string;
  kind: string;
  /** An i18n key (dashboard.cards.*), translated with `params`. */
  title: string;
  params?: Record<string, string | number>;
  priority: number;
  link: string | null;
  data: Record<string, unknown>;
}

export interface Dashboard {
  role: string | null;
  roles: { name: string; label: string }[];
  cards: DashboardCard[];
}

export interface SearchResult {
  type: 'student' | 'staff';
  label: string;
  detail: string | null;
  link: string;
}

export const dashboardKey = (role: string | undefined) => ['dashboard', role ?? 'default'] as const;

/**
 * My dashboard, for the role picked in the role switcher (?as=). Refetched
 * when the window regains focus; a refetch is a background request, so it
 * never keeps an idle session alive.
 */
export function useDashboard(me: Pick<Me, 'id' | 'roles'>) {
  const role = useChosenRole(me.id, me.roles);

  return useQuery({
    queryKey: dashboardKey(role),
    queryFn: apiGet<Dashboard>('/me/dashboard', role ? { as: role } : undefined),
    refetchOnWindowFocus: true,
    placeholderData: keepPreviousData,
  });
}

export function useSearch(q: string) {
  const term = q.trim();

  return useQuery({
    queryKey: ['search', term],
    queryFn: apiGet<SearchResult[]>('/me/search', { q: term }),
    enabled: term.length >= 2,
    staleTime: 30_000,
    retry: false,
  });
}
