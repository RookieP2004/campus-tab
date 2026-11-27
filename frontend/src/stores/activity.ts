import { create } from 'zustand';

/**
 * When this tab last made a real request (not a background one). The idle
 * warning counts from here, as the server does. Memory only.
 */
interface ActivityState {
  lastRealRequestAt: number;
  touch: () => void;
}

export const useActivity = create<ActivityState>()((set) => ({
  lastRealRequestAt: Date.now(),
  touch: () => set({ lastRealRequestAt: Date.now() }),
}));
