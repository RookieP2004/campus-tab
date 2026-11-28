import { useMutation } from '@tanstack/react-query';
import { ArrowLeft, KeyRound, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card } from '@/components/ui/form';
import { allSections, useClasses } from '@/features/academic/api';
import { errorText } from '@/features/auth/errors';
import { ApiError } from '@/lib/api';
import { type SetupCodeSheet, students } from './api';
import { PageHeader, TableFrame, td, th } from './ui';

/** Prints the code sheet alone, in a hidden frame (the shell stays off the page). */
function printSheet(title: string, headers: string[], sheet: SetupCodeSheet, note: string) {
  const frame = document.createElement('iframe');
  frame.style.position = 'fixed';
  frame.style.width = '0';
  frame.style.height = '0';
  frame.style.border = '0';
  document.body.appendChild(frame);
  const doc = frame.contentDocument;
  if (doc) {
    // A constructed stylesheet, not a <style> element: the Content-Security-Policy
    // forbids inline styles, and the frame inherits the page's policy.
    const css =
      'body{font:13px system-ui,sans-serif;margin:16px}h1{font-size:18px}table{border-collapse:collapse;width:100%}th,td{border:1px solid currentColor;padding:6px 8px;text-align:left}td.code{font:15px monospace;letter-spacing:2px}p{font-size:12px}';
    const View = doc.defaultView as (Window & typeof globalThis) | null;
    if (View && 'adoptedStyleSheets' in doc) {
      const sheetStyle = new View.CSSStyleSheet();
      sheetStyle.replaceSync(css);
      doc.adoptedStyleSheets = [sheetStyle];
    }
    const h1 = doc.createElement('h1');
    h1.textContent = title;
    doc.body.appendChild(h1);
    const table = doc.createElement('table');
    const head = table.insertRow();
    for (const h of headers) {
      const cell = doc.createElement('th');
      cell.textContent = h;
      head.appendChild(cell);
    }
    for (const s of sheet.students) {
      const row = table.insertRow();
      [String(s.roll_no ?? ''), s.name, s.login_name, s.code ?? ''].forEach((value, i) => {
        const cell = row.insertCell();
        cell.textContent = value;
        if (i === 3) cell.className = 'code';
      });
    }
    doc.body.appendChild(table);
    const p = doc.createElement('p');
    p.textContent = note;
    doc.body.appendChild(p);
    frame.contentWindow?.print();
  }
  setTimeout(() => frame.remove(), 1000);
}

export function SetupCodesPage() {
  const { id = '' } = useParams();
  const { t } = useTranslation();
  const classes = useClasses();
  const section = allSections(classes.data).find((s) => s.id === id);
  const issue = useMutation({ mutationFn: () => students.setupCodes(id) });
  const sheet = issue.data;
  const off = issue.error instanceof ApiError && issue.error.code === 'student_login_off';
  const label = sheet?.section.label ?? section?.label ?? '';
  const headers = [t('setupCodes.table.roll'), t('setupCodes.table.name'), t('setupCodes.table.login'), t('setupCodes.table.code')];

  return (
    <div className="flex flex-col gap-4">
      <Link to={`/students?section=${id}`} className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
        <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
        {t('students.profile.back')}
      </Link>
      <PageHeader title={t('setupCodes.title', { section: label })} intro={t('setupCodes.intro')} />

      {!sheet ? (
        <Card className="flex flex-col gap-3">
          <ul className="flex list-disc flex-col gap-1 ps-5 text-sm">
            <li>{t('setupCodes.how1')}</li>
            <li>{t('setupCodes.how2')}</li>
            <li>{t('setupCodes.how3')}</li>
          </ul>
          {off ? <Alert tone="warning">{t('setupCodes.loginOff')}</Alert> : null}
          {issue.isError && !off ? <Alert tone="danger">{errorText(t, issue.error)}</Alert> : null}
          <div>
            <Button disabled={issue.isPending} onClick={() => issue.mutate()}>
              <KeyRound aria-hidden="true" />
              {t('setupCodes.issue')}
            </Button>
          </div>
        </Card>
      ) : (
        <Card className="flex flex-col gap-3">
          <Alert tone="warning">{t('setupCodes.once', { hours: sheet.valid_hours })}</Alert>
          <div className="flex justify-end">
            <Button variant="outline" onClick={() => printSheet(t('setupCodes.title', { section: label }), headers, sheet, t('setupCodes.once', { hours: sheet.valid_hours }))}>
              <Printer aria-hidden="true" />
              {t('setupCodes.print')}
            </Button>
          </div>
          {sheet.students.length === 0 ? (
            <p className="text-sm text-muted">{t('setupCodes.noStudents')}</p>
          ) : (
            <TableFrame>
              <thead>
                <tr>
                  {headers.map((h) => (
                    <th key={h} scope="col" className={th}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {sheet.students.map((s) => (
                  <tr key={s.student_id}>
                    <td className={td}>{s.roll_no ?? '—'}</td>
                    <td className={td}>{s.name}</td>
                    <td className={`${td} font-mono`}>{s.login_name}</td>
                    <td className={`${td} font-mono tracking-widest`}>{s.code ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </TableFrame>
          )}
        </Card>
      )}
    </div>
  );
}
