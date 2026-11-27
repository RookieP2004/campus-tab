// CI check: the committed client (src/schema.ts) must be exactly what the
// committed OpenAPI spec (openapi.json) generates. The backend CI job checks
// that openapi.json itself matches the code (php artisan scramble:export).
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const pkgPath = require.resolve('openapi-typescript/package.json');
const pkg = JSON.parse(readFileSync(pkgPath, 'utf8'));
const bin = join(dirname(pkgPath), typeof pkg.bin === 'string' ? pkg.bin : pkg.bin['openapi-typescript']);

const dir = mkdtempSync(join(tmpdir(), 'campus-tab-api-'));
const out = join(dir, 'schema.ts');

try {
  execFileSync(process.execPath, [bin, join(root, 'openapi.json'), '--output', out, '--root-types', '--alphabetize'], { stdio: 'pipe' });

  const fresh = readFileSync(out, 'utf8');
  const committed = readFileSync(join(root, 'src', 'schema.ts'), 'utf8');

  if (fresh !== committed) {
    console.error('packages/api/src/schema.ts is out of date with packages/api/openapi.json.');
    console.error('Run: docker compose exec api php artisan scramble:export');
    console.error('then: docker compose exec web npm run generate -w @campus-tab/api');
    process.exit(1);
  }

  console.log('The API client is up to date with the OpenAPI spec.');
} finally {
  rmSync(dir, { recursive: true, force: true });
}
