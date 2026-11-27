import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { getHealth } from '@/lib/api';
import { cn } from '@/lib/utils';

/** Small sidebar footer line showing whether GET /api/v1/health answers. */
export function ServerStatus() {
  const { t } = useTranslation();
  const { data, isPending, isError } = useQuery({
    queryKey: ['system', 'health'],
    queryFn: ({ signal }) => getHealth(signal),
    refetchInterval: 30_000,
    retry: false,
  });

  const state = isPending ? 'checking' : !isError && data?.status === 'ok' ? 'ok' : 'fail';

  return (
    <p className="flex items-center gap-2 px-1.5 text-xs text-muted" role="status" data-testid="server-status" data-state={state}>
      <span
        aria-hidden="true"
        className={cn(
          'size-2 rounded-full',
          state === 'ok' && 'bg-success-solid',
          state === 'fail' && 'bg-danger-solid',
          state === 'checking' && 'bg-border',
        )}
      />
      {t('server.label')}: {t(`server.${state}`)}
    </p>
  );
}
