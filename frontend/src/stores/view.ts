import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

/**
 * Per-person screen choices, kept in this browser only: the academic year
 * picked in the brand stack, the role whose dashboard is shown (the role
 * switcher) and the child picked on a parent's home. Keyed by user id, so
 * two people sharing a browser never see each other's choice. These are
 * preferences, not secrets: no token or account detail is stored here.
 */
interface ViewState {
  years: Record<string, string>;
  roles: Record<string, string>;
  children: Record<string, string>;
  setYear: (userId: string, yearId: string) => void;
  setRole: (userId: string, role: string) => void;
  setChild: (userId: string, childId: string) => void;
}

export const VIEW_STORAGE_KEY = 'campus-tab:view';

export const useViewPrefs = create<ViewState>()(
  persist(
    (set) => ({
      years: {},
      roles: {},
      children: {},
      setYear: (userId, yearId) => set((s) => ({ years: { ...s.years, [userId]: yearId } })),
      setRole: (userId, role) => set((s) => ({ roles: { ...s.roles, [userId]: role } })),
      setChild: (userId, childId) => set((s) => ({ children: { ...s.children, [userId]: childId } })),
    }),
    {
      name: VIEW_STORAGE_KEY,
      storage: createJSONStorage(() => localStorage),
      partialize: ({ years, roles, children }) => ({ years, roles, children }),
    },
  ),
);

/** The role view this person picked, if they hold it. */
export function useChosenRole(userId: string, roles: string[]): string | undefined {
  const chosen = useViewPrefs((s) => s.roles[userId]);
  return chosen && roles.includes(chosen) ? chosen : undefined;
}
