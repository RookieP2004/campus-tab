import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { buttonVariants } from '@/components/ui/button';

export function NotFoundPage() {
  const { t } = useTranslation();

  return (
    <section className="mx-auto max-w-3xl rounded-card border border-border bg-surface p-6">
      <h1 className="text-2xl font-semibold">{t('notFound.title')}</h1>
      <Link to="/" className={buttonVariants({ variant: 'outline', className: 'mt-4' })}>
        {t('notFound.back')}
      </Link>
    </section>
  );
}
