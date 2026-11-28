// Fails CI on high or critical advisories; lists lower ones as warnings.
//   composer audit --locked --format=json | node audit-gate.mjs composer
//   npm audit --json | node audit-gate.mjs npm <label>
let input = '';
process.stdin.on('data', (c) => (input += c)).on('end', () => {
  const [kind, label = kind] = process.argv.slice(2);
  const body = JSON.parse(input.slice(input.indexOf('{')) || '{}');
  const found = [];
  if (kind === 'composer') {
    for (const [pkg, list] of Object.entries(body.advisories ?? {})) {
      for (const a of Object.values(list)) found.push({ pkg, severity: (a.severity ?? 'unknown').toLowerCase(), title: a.title, id: a.cve ?? a.advisoryId });
    }
  } else {
    for (const [pkg, v] of Object.entries(body.vulnerabilities ?? {})) found.push({ pkg, severity: v.severity, title: (v.via ?? []).map((x) => x.title ?? x).join('; '), id: '' });
  }
  const blocking = found.filter((f) => ['high', 'critical', 'unknown'].includes(f.severity));
  for (const f of found) {
    const line = `${label}: ${f.pkg} ${f.severity} ${f.id ?? ''} ${f.title ?? ''}`.trim();
    console.log(blocking.includes(f) ? `::error::${line}` : `::warning::${line}`);
  }
  console.log(`${label}: ${found.length} advisories, ${blocking.length} high or critical`);
  process.exit(blocking.length > 0 ? 1 : 0);
});
