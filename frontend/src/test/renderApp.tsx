import { QueryClient } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { Providers } from '@/app/Providers';
import { routes } from '@/app/routes';
import { i18nReady } from '@/i18n';
import { useSessionState } from '@/stores/session';
import { fakeApi } from './setup';

const PUBLIC = ['/school', '/sign-in', '/password', '/activate', '/mfa', '/console'];

/**
 * Renders the whole app at `path` with a fresh query cache. For a signed-in
 * screen it waits until the shell has loaded GET /me.
 */
export async function renderApp(path = '/') {
  await i18nReady;
  useSessionState.setState({ ended: false, reason: null });
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  const result = render(
    <Providers queryClient={queryClient}>
      <RouterProvider router={router} />
    </Providers>,
  );

  if (fakeApi.me && !PUBLIC.some((p) => path.startsWith(p))) await screen.findByTestId('signed-in-name');

  return Object.assign(result, { router, queryClient });
}
