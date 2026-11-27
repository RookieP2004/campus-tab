import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

/**
 * The school code this browser last used, so the sign-in screen can open
 * straight on that school. A school code is public (it is printed on the
 * school's notices); no account detail or token is ever stored here.
 */
interface SchoolState {
  code: string | null;
  setCode: (code: string | null) => void;
}

export const SCHOOL_STORAGE_KEY = 'campus-tab:school';

export const useSchoolCode = create<SchoolState>()(
  persist(
    (set) => ({
      code: null,
      setCode: (code) => set({ code: code ? code.trim().toUpperCase() : null }),
    }),
    { name: SCHOOL_STORAGE_KEY, storage: createJSONStorage(() => localStorage), partialize: ({ code }) => ({ code }) },
  ),
);
