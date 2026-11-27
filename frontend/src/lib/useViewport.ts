import { useSyncExternalStore } from 'react';

/** The three shell layouts: bottom tabs, icon rail, full sidebar. */
export type Viewport = 'phone' | 'tablet' | 'desktop';

const DESKTOP = '(min-width: 1024px)';
const TABLET = '(min-width: 768px)';

function read(): Viewport {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return 'desktop';
  if (window.matchMedia(DESKTOP).matches) return 'desktop';
  if (window.matchMedia(TABLET).matches) return 'tablet';
  return 'phone';
}

function subscribe(onChange: () => void): () => void {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return () => undefined;
  const queries = [window.matchMedia(DESKTOP), window.matchMedia(TABLET)];
  queries.forEach((q) => q.addEventListener?.('change', onChange));
  window.addEventListener('resize', onChange);

  return () => {
    queries.forEach((q) => q.removeEventListener?.('change', onChange));
    window.removeEventListener('resize', onChange);
  };
}

/**
 * Which layout fits the window: under 768 px phone, 768-1023 px tablet,
 * 1024 px and up desktop. Only one navigation is rendered at a time, so
 * screen readers and keyboard users never meet a hidden duplicate.
 */
export function useViewport(): Viewport {
  return useSyncExternalStore(subscribe, read, () => 'desktop');
}
