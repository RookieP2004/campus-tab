import { backgroundHeaders } from '@campus-tab/api';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { create } from 'zustand';
import type { Me } from '@/features/auth/api';
import { api } from '@/lib/api';
import { env } from '@/lib/env';
import { type NotificationList, notificationsKey } from './api';

interface SocketState {
  connected: boolean;
  setConnected: (connected: boolean) => void;
}

/** Whether the notification socket is connected. Memory only. */
export const useSocketState = create<SocketState>()((set) => ({
  connected: false,
  setConnected: (connected) => set({ connected }),
}));

/** The person's own private channel (the server checks it on /broadcasting/auth). */
export function userChannel(me: Pick<Me, 'id' | 'tenant_id'>): string {
  return `school.${me.tenant_id}.user.${me.id}`;
}

/**
 * Asks the API whether this socket may join the channel. It goes through
 * the one API client, so it carries the session cookie and the XSRF header,
 * and it is marked as a background request: the socket joining never
 * counts as use of the session.
 */
async function authorizeChannel(socketId: string, channelName: string): Promise<{ auth: string; channel_data?: string }> {
  const { data, response } = (await api.POST('/broadcasting/auth' as never, {
    body: { socket_id: socketId, channel_name: channelName },
    headers: backgroundHeaders,
  } as never)) as { data?: { auth: string; channel_data?: string }; response: Response };
  if (!response.ok || !data) throw new Error(`Channel authorisation failed (${response.status})`);
  return data;
}

interface NotificationEvent {
  id: string;
  type: string;
  unread: number;
}

/**
 * Listens on school.<tenant_id>.user.<id> for ".notification.created". On
 * each event the bell count updates at once and the list is refetched (a
 * background request); nothing readable travels over the socket. The
 * socket libraries load in their own chunk, after the shell. When the
 * socket is off or not connected, the bell polls every 60 s instead.
 */
export function useNotificationSocket(me: Pick<Me, 'id' | 'tenant_id'>): void {
  const queryClient = useQueryClient();
  const setConnected = useSocketState((s) => s.setConnected);

  useEffect(() => {
    if (!env.reverb.enabled || !me.tenant_id) return undefined;

    let stopped = false;
    let stop: (() => void) | undefined;
    const channel = userChannel(me);

    void (async () => {
      const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
      if (stopped) return;

      const secure = env.reverb.scheme === 'https';
      const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: env.reverb.key,
        wsHost: env.reverb.host,
        wsPort: env.reverb.port,
        wssPort: env.reverb.port,
        forceTLS: secure,
        enabledTransports: secure ? ['wss'] : ['ws'],
        withoutInterceptors: true,
        channelAuthorization: {
          endpoint: `${env.apiBaseUrl}/broadcasting/auth`,
          transport: 'ajax',
          customHandler: ({ socketId, channelName }: { socketId: string; channelName: string }, callback: (error: Error | null, data: { auth: string } | null) => void) => {
            authorizeChannel(socketId, channelName).then(
              (data) => callback(null, data),
              (error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null),
            );
          },
        },
      });

      const connection = echo.connector.pusher.connection;
      connection.bind('state_change', ({ current }: { current: string }) => setConnected(current === 'connected'));

      echo.private(channel).listen('.notification.created', (event: NotificationEvent) => {
        queryClient.setQueryData<NotificationList>(notificationsKey, (old) => (old ? { ...old, unread: event.unread } : old));
        void queryClient.invalidateQueries({ queryKey: notificationsKey });
        if (event.type === 'notice') void queryClient.invalidateQueries({ queryKey: ['notices'] });
        // A leave request moved on: the open timeline, the lists and the inbox refetch (live steps).
        if (event.type === 'results.published' || event.type === 'marks.correction') {
          void queryClient.invalidateQueries({ queryKey: ['marks'] });
          void queryClient.invalidateQueries({ queryKey: ['approvals'] });
        }
        if (event.type.startsWith('leave.')) {
          void queryClient.invalidateQueries({ queryKey: ['leave'] });
          void queryClient.invalidateQueries({ queryKey: ['approvals'] });
          void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
        }
      });

      stop = () => {
        echo.leave(channel);
        echo.disconnect();
      };
    })().catch(() => setConnected(false));

    return () => {
      stopped = true;
      stop?.();
      setConnected(false);
    };
    // Reconnect only when the person (or their school) changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [me.id, me.tenant_id, queryClient, setConnected]);
}
