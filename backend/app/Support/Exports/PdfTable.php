<?php

namespace App\Support\Exports;

/**
 * A data export as a printable PDF page (Phase 9): a plain table with the
 * watermark (who, when, which school) as a faint diagonal on every page and
 * in the footer. The HTML has no scripts and no outside files; Gotenberg
 * renders it with JavaScript off.
 */
final class PdfTable
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|null>>  $rows
     * @param  array{text: string, school: string, by: string, at: string}  $watermark
     */
    public static function html(string $title, array $headers, array $rows, array $watermark): string
    {
        $e = fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $head = implode('', array_map(fn ($h) => '<th>'.$e($h).'</th>', $headers));
        $body = implode('', array_map(fn (array $r) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.$e($c).'</td>', $r)).'</tr>', $rows));
        $mark = $e($watermark['school'].' · '.$watermark['by'].' · '.$watermark['at']);

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{$e($title)}</title>
<style>
  @page { size: A4 landscape; margin: 14mm 10mm 16mm; }
  body { font-family: "Noto Sans", sans-serif; font-size: 9pt; color: #111; }
  h1 { font-size: 13pt; margin: 0 0 2mm; }
  p.meta { margin: 0 0 4mm; color: #444; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 0.3pt solid #999; padding: 1.2mm 1.6mm; text-align: left; vertical-align: top; }
  th { background: #eef2ff; }
  tr { page-break-inside: avoid; }
  .mark { position: fixed; top: 40%; left: -5%; width: 110%; transform: rotate(-24deg); text-align: center; font-size: 26pt; color: rgba(29, 78, 216, 0.09); white-space: nowrap; z-index: 9; pointer-events: none; }
  .foot { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7.5pt; color: #555; }
</style></head>
<body>
  <div class="mark">{$mark}</div>
  <div class="foot">{$e($watermark['text'])}</div>
  <h1>{$e($title)}</h1>
  <p class="meta">{$e($watermark['text'])}</p>
  <table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
</body></html>
HTML;
    }
}
