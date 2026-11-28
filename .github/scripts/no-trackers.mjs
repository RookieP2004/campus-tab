// No tracking, profiling or third-party analytics anywhere (DPDP, Phase 9).
// Fails if a dependency of the web app, the apps or the API is an analytics
// or tracking package, or if the built web app mentions a tracking host.
//   node .github/scripts/no-trackers.mjs            (after the web build)
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const packages = [
  /analytics/i, /^@segment\//, /^mixpanel/, /^amplitude/, /^@amplitude\//, /hotjar/i, /^posthog/, /^@posthog\//, /^react-ga/, /^ga-4/,
  /^@vercel\/analytics/, /^@firebase\/analytics/, /^firebase$/, /^@capacitor-firebase\/analytics/, /fullstory/i, /^logrocket/, /clarity/i, /^@datadog\/browser-rum/,
  /facebook-pixel/i, /^react-facebook-pixel/, /^plausible/, /^matomo/i, /^@sentry\/(browser|react|replay)/,
];
const hosts = [
  'google-analytics.com', 'googletagmanager.com', 'doubleclick.net', 'connect.facebook.net', 'facebook.com/tr', 'hotjar.com', 'segment.io', 'segment.com/analytics',
  'mixpanel.com', 'amplitude.com', 'clarity.ms', 'posthog.com', 'fullstory.com', 'plausible.io', 'matomo.cloud', 'app-measurement.com', 'firebase-analytics',
];

const problems = [];
const manifests = ['package.json', 'frontend/package.json', 'packages/ui/package.json', 'packages/api/package.json', 'apps/mobile/package.json', 'apps/desktop/package.json'];
for (const file of manifests) {
  if (!existsSync(file)) continue;
  const pkg = JSON.parse(readFileSync(file, 'utf8'));
  for (const name of Object.keys({ ...pkg.dependencies, ...pkg.devDependencies })) {
    if (packages.some((re) => re.test(name))) problems.push(`${file}: dependency ${name}`);
  }
}
if (existsSync('backend/composer.json')) {
  const composer = JSON.parse(readFileSync('backend/composer.json', 'utf8'));
  for (const name of Object.keys({ ...composer.require, ...composer['require-dev'] })) {
    if (/analytics|segmentio|mixpanel|google\/analytics/i.test(name)) problems.push(`backend/composer.json: dependency ${name}`);
  }
}

function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path);
    else if (/\.(js|html|css|json)$/.test(entry)) {
      const text = readFileSync(path, 'utf8');
      for (const host of hosts) if (text.includes(host)) problems.push(`${path}: mentions ${host}`);
    }
  }
}
for (const dir of ['frontend/dist', 'apps/mobile/android/app/src/main/AndroidManifest.xml']) {
  if (existsSync(dir) && statSync(dir).isDirectory()) walk(dir);
}

if (problems.length > 0) {
  for (const p of problems) console.log(`::error::No tracking allowed: ${p}`);
  process.exit(1);
}
console.log('No tracking, profiling or third-party analytics found.');
