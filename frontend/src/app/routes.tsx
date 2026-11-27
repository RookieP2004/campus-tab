import type { RouteObject } from 'react-router';
import { AppShell } from '@/features/shell/AppShell';
import { HomePage } from '@/features/home/HomePage';
import { NotFoundPage } from '@/features/home/NotFoundPage';

/** Route table. Each phase adds its screens as children of the shell. */
export const routes: RouteObject[] = [
  {
    path: '/',
    element: <AppShell />,
    children: [
      { index: true, element: <HomePage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
