import { useTranslation } from 'react-i18next';

/** Empty home screen. Phase 3 replaces it with each role's dashboard. */
export function HomePage() {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="home-title" className="mx-auto max-w-3xl rounded-card border border-border bg-surface p-6 lg:p-8">
      <h1 id="home-title" className="text-2xl font-semibold tracking-tight">
        {t('home.title')}
      </h1>
      <p className="mt-3 text-base leading-relaxed text-muted">{t('home.body')}</p>
    </section>
  );
}
