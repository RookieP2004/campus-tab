// Works out the build-time settings for an app build from the API address
// and writes them to $GITHUB_ENV.
//   node .github/scripts/app-env.mjs <shell: mobile|desktop> <api address> [public web address] [push: true|false]
// The API address is the server root, e.g. https://api.example.in or, for a
// phone test on Wi-Fi, http://192.168.31.85:8000. Reverb (live
// notifications) is assumed on the same host: port 8080 over plain HTTP in
// development, 443 behind TLS in production.
import { appendFileSync } from 'node:fs';

const [shell, apiArg, webArg = '', push = 'false'] = process.argv.slice(2);
if (!['mobile', 'desktop'].includes(shell) || !apiArg) {
  console.error('Usage: app-env.mjs <mobile|desktop> <api address> [web address] [push]');
  process.exit(1);
}
const api = new URL(apiArg);
const secure = api.protocol === 'https:';
const lines = {
  VITE_APP_SHELL: shell,
  VITE_API_BASE_URL: `${api.origin}/api/v1`,
  VITE_PUBLIC_WEB_URL: webArg,
  VITE_PUSH_ENABLED: push === 'true' ? 'true' : 'false',
  VITE_REVERB_HOST: api.hostname,
  VITE_REVERB_PORT: secure ? '443' : '8080',
  VITE_REVERB_SCHEME: secure ? 'https' : 'http',
  CAMPUS_DEV_HTTP: secure ? '0' : '1',
  CAMPUS_DEEP_LINK_HOST: webArg ? new URL(webArg).hostname : 'app.campustab.invalid',
};
const out = Object.entries(lines).map(([k, v]) => `${k}=${v}`).join('\n');
console.log(out);
if (process.env.GITHUB_ENV) appendFileSync(process.env.GITHUB_ENV, `${out}\n`);
