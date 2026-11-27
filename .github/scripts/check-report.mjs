#!/usr/bin/env node
// Fails a pull request that changes code without updating the Application
// Report (docs/report/). Rules, from docs/BLUEPRINT.md "Rules that keep it true":
//   1. Any change under backend/, frontend/, packages/, apps/ or infra/
//      needs a docs/report/CHANGELOG.md change.
//   2. A change inside backend/app/Modules/<Module>/ or
//      backend/tests/Feature/<Module>/ needs docs/report/modules/<module>.md.
//   3. A change under frontend/ or packages/ui/ needs docs/report/frontend.md
//      or a module page.
//
// Usage: node .github/scripts/check-report.mjs [base-ref]   (default: origin/main)
// Run it locally before opening a pull request to see what is missing.
import { execFileSync } from 'node:child_process';

const base = process.argv[2] ?? 'origin/main';

function changedFiles() {
  const out = execFileSync('git', ['diff', '--name-only', `${base}...HEAD`], { encoding: 'utf8' });

  return out.split('\n').map((f) => f.trim()).filter(Boolean);
}

/** Module folders whose report page has a longer name. */
const PAGE_ALIASES = { Identity: 'identity-and-access' };

/** "AcademicStructure" -> "academic-structure" */
function kebab(name) {
  return PAGE_ALIASES[name] ?? name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
}

const CODE = /^(backend|frontend|packages|apps|infra)\//;
const MODULE = /^backend\/(?:app\/Modules|tests\/Feature)\/([A-Za-z]+)\//;

const files = changedFiles();
const changed = new Set(files);
const codeFiles = files.filter((f) => CODE.test(f) && !f.endsWith('.md'));
const problems = [];

if (codeFiles.length > 0 && !changed.has('docs/report/CHANGELOG.md')) {
  problems.push('Code changed but docs/report/CHANGELOG.md has no new entry.');
}

const modules = new Set(files.map((f) => f.match(MODULE)?.[1]).filter(Boolean));

for (const module of modules) {
  const page = `docs/report/modules/${kebab(module)}.md`;

  if (!changed.has(page)) {
    problems.push(`Module ${module} changed but ${page} was not updated.`);
  }
}

const frontendChanged = files.some((f) => /^(frontend|packages\/ui)\//.test(f) && !f.endsWith('.md'));
const frontendDocs = changed.has('docs/report/frontend.md') || files.some((f) => f.startsWith('docs/report/modules/'));

if (frontendChanged && !frontendDocs) {
  problems.push('Front-end code changed but neither docs/report/frontend.md nor a module page was updated.');
}

console.log(`Compared ${base}...HEAD: ${files.length} files changed, ${codeFiles.length} code files.`);

if (problems.length > 0) {
  console.error('\nApplication Report check failed:');
  for (const p of problems) console.error(`  - ${p}`);
  console.error('\nUpdate docs/report/ in the same change (see docs/CONVENTIONS.md, rule 6).');
  process.exit(1);
}

console.log('Application Report check passed.');
