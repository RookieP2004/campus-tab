#!/usr/bin/env node
/**
 * Language coverage report.
 *
 * Compares every language listed in src/i18n/languages.ts with English
 * (src/locales/en/common.json) and writes a "missing keys per language"
 * table into docs/report/languages.md, between the markers
 *   <!-- generated:i18n:start --> and <!-- generated:i18n:end -->.
 * Text outside the markers is left alone. A language with no file yet is
 * listed as missing every key: it falls back to English, key by key.
 *
 *   npm run i18n:report -w @campus-tab/frontend   write the table
 *   npm run i18n:check  -w @campus-tab/frontend   exit 1 if the file is out of date
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const frontend = join(here, '..');
const repo = join(frontend, '..');
const reportPath = join(repo, 'docs', 'report', 'languages.md');
const START = '<!-- generated:i18n:start -->';
const END = '<!-- generated:i18n:end -->';
const DETAIL_LIMIT = 50;

const INTRO = `# Languages

English is the default for everyone; each person can pick another language at
sign-up or later, and the choice changes only their own screens. Every
language listed in \`frontend/src/i18n/languages.ts\` is compared with English
below. A missing key is shown in English (i18next falls back key by key), so
a half-translated language still works.

To translate a language: copy \`frontend/src/locales/en/common.json\` to
\`frontend/src/locales/<code>/common.json\`, translate the values (keep every
\`{{placeholder}}\`), set \`shipped: true\` in \`languages.ts\` once it is
complete and reviewed, then run \`npm run i18n:report -w @campus-tab/frontend\`.
CI runs \`npm run i18n:check -w @campus-tab/frontend\`, which fails when this
page is out of date.

`;

/** The languages from languages.ts, without loading TypeScript. */
function readLanguages() {
  const source = readFileSync(join(frontend, 'src', 'i18n', 'languages.ts'), 'utf8');
  const pattern = /\{\s*code:\s*'([^']+)',\s*name:\s*'([^']+)',\s*nativeName:\s*'([^']+)'[^}]*?shipped:\s*(true|false)\s*\}/g;
  const found = [];
  for (const match of source.matchAll(pattern)) {
    found.push({ code: match[1], name: match[2], nativeName: match[3], shipped: match[4] === 'true' });
  }
  if (found.length === 0) throw new Error('No languages found in src/i18n/languages.ts');
  return found;
}

/** Dotted keys of every string in a locale file. */
function flatten(value, prefix = '') {
  if (value === null || typeof value !== 'object') return [prefix];
  return Object.entries(value).flatMap(([k, v]) => flatten(v, prefix ? `${prefix}.${k}` : k));
}

function localeKeys(code) {
  const file = join(frontend, 'src', 'locales', code, 'common.json');
  if (!existsSync(file)) return null;
  return new Set(flatten(JSON.parse(readFileSync(file, 'utf8'))));
}

function buildTable() {
  const english = [...(localeKeys('en') ?? [])].sort();
  const total = english.length;
  const rows = [];
  const details = [];

  for (const language of readLanguages()) {
    if (language.code === 'en') continue;
    const keys = localeKeys(language.code);
    let missingText;
    let missing = [];
    if (keys === null) {
      missingText = `all ${total} keys missing, falls back to English`;
    } else {
      missing = english.filter((k) => !keys.has(k));
      missingText = missing.length === 0 ? 'none (complete)' : `${missing.length} of ${total} missing, shown in English`;
    }
    rows.push(`| \`${language.code}\` | ${language.name} (${language.nativeName}) | ${language.shipped ? 'yes' : 'no'} | ${keys === null ? 'no' : 'yes'} | ${missingText} |`);
    if (keys !== null && missing.length > 0) {
      const shown = missing.slice(0, DETAIL_LIMIT).map((k) => `\`${k}\``).join(', ');
      const more = missing.length > DETAIL_LIMIT ? `, and ${missing.length - DETAIL_LIMIT} more` : '';
      details.push(`- **${language.name}:** ${shown}${more}`);
    }
  }

  return [
    START,
    `English has ${total} keys.`,
    '',
    '| Code | Language | Shipped | File | Missing keys |',
    '| --- | --- | --- | --- | --- |',
    ...rows,
    ...(details.length > 0 ? ['', '### Missing keys', '', ...details] : []),
    END,
  ].join('\n');
}

function render(current) {
  const table = buildTable();
  if (current === null) return `${INTRO}${table}\n`;
  const start = current.indexOf(START);
  const end = current.indexOf(END);
  if (start === -1 || end === -1 || end < start) return `${current.replace(/\s*$/, '')}\n\n${table}\n`;
  return current.slice(0, start) + table + current.slice(end + END.length);
}

const check = process.argv.includes('--check');
const current = existsSync(reportPath) ? readFileSync(reportPath, 'utf8') : null;
const next = render(current);
const shown = relative(repo, reportPath);

if (check) {
  if (current !== next) {
    console.error(`${shown} is out of date. Run: npm run i18n:report -w @campus-tab/frontend`);
    process.exit(1);
  }
  console.log(`${shown} is up to date.`);
} else if (current === next) {
  console.log(`${shown} is already up to date.`);
} else {
  writeFileSync(reportPath, next);
  console.log(`Wrote ${shown}.`);
}
