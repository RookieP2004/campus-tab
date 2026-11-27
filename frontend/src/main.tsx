import '@fontsource-variable/inter';
import '@fontsource-variable/noto-sans-devanagari';
import './styles/index.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { Providers } from '@/app/Providers';
import { routes } from '@/app/routes';
import { i18nReady } from '@/i18n';

const router = createBrowserRouter(routes);
const root = document.getElementById('root');

if (!root) {
  throw new Error('Missing #root element in index.html');
}

void i18nReady.then(() => {
  createRoot(root).render(
    <StrictMode>
      <Providers>
        <RouterProvider router={router} />
      </Providers>
    </StrictMode>,
  );
});
