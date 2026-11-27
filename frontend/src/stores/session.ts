import { create } from 'zustand';

/**
 * Whether this tab's session has ended while a screen was open. Memory only:
 * the session itself is the cookie, and nothing about it is ever saved in
 * localStorage or sessionStorage.
 *
 * When `ended` is true the sign-in overlay covers the page. The page stays
 * mounted underneath, so forms keep what was typed.
 */
interface SessionState {
  ended: boolean;
  reason: string | null;
  end: (reason: string) => void;
  resume: () => void;
}

export const useSessionState = create<SessionState>()((set, get) => ({
  ended: false,
  reason: null,
  end: (reason) => {
    if (!get().ended) set({ ended: true, reason });
  },
  resume: () => set({ ended: false, reason: null }),
}));
