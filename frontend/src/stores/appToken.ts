import { create } from 'zustand';

/**
 * The apps' sign-in token (Phase 9, the owner's sign-in rule). It lives in
 * this JavaScript memory only: never localStorage, sessionStorage, a cookie,
 * the device keychain or a file. Closing the app throws it away, which is
 * the sign-out; there is no biometric or PIN unlock. The browser never uses
 * it (the browser has its session cookie).
 */
interface AppTokenState {
  token: string | null;
  set: (token: string | null) => void;
}

export const useAppToken = create<AppTokenState>()((set) => ({
  token: null,
  set: (token) => set({ token }),
}));
