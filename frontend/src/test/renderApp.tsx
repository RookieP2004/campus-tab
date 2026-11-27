import { QueryClient } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { Providers } from '@/app/Providers';
import { routes } from '@/app/routes';
import { i18nReady } from '@/i18n';

/** Renders the whole app at `path` with fresh query cache. */
export async function renderApp(path = '/') {
  await i18nReady;
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <Providers queryClient={queryClient}>
      <RouterProvider router={router} />
    </Providers>,
  );
}
