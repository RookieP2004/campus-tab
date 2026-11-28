<?php

namespace App\Modules\Reports\Domain;

use App\Modules\Reports\Models\ReportCard;
use App\Modules\Reports\Models\ReportTemplate;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the card's printable HTML from the template's blocks, in their
 * order, and the frozen snapshot. Everything is escaped, and every image
 * (logo, letterhead, QR code) is inlined, so the PDF service fetches
 * nothing. Text comes from lang/<language>/reports.php in the school's
 * default language; Noto fonts cover Latin and Devanagari.
 */
class CardRenderer
{
    /**
     * @param  array{name: string, logo_path: string|null, language: string}  $school
     * @param  array{admission_no?: string|null, dob?: string|null, roll_no?: int|null}  $student
     */
    public function html(ReportCard $card, ReportTemplate $template, array $school, array $student, string $verifyUrl): string
    {
        $lang = $school['language'];
        $t = fn (string $key, array $replace = []) => e((string) __('reports.'.$key, $replace, $lang));
        $snapshot = $card->snapshot;
        $parts = [];

        if ($template->letterhead !== null && Storage::disk('local')->exists($template->letterhead['path'])) {
            $parts[] = '<img class="letterhead" alt="" src="'.self::dataUri($template->letterhead['path'], $template->letterhead['mime']).'">';
        }

        foreach ($template->blocks as $block) {
            $parts[] = match ($block['type']) {
                'header' => $this->header($card, $school, $t),
                'student' => $this->student($card, $student, $t),
                'scholastic' => $this->scholastic($snapshot, $t),
                'co_scholastic' => $this->coScholastic($card, $t),
                'attendance' => $this->attendance($card, $t),
                'descriptive' => $this->descriptive($card, $snapshot, $t),
                'remarks' => $this->remarks($card, $snapshot, $t),
                'result' => $this->result($snapshot, $t),
                'signatures' => $this->signatures($t),
                default => '',
            };
        }

        $qr = self::qr($verifyUrl);
        $issued = $card->issued_at?->timezone('Asia/Kolkata')->format('d M Y') ?? now('Asia/Kolkata')->format('d M Y');
        $parts[] = '<footer><div class="qr">'.$qr.'</div><div><p>'.$t('verify').'</p><p class="muted url">'.e($verifyUrl).'</p><p class="muted">'.$t('version', ['version' => (string) $card->version, 'date' => $issued]).'</p></div></footer>';

        return '<!doctype html><html lang="'.e($lang).'"><head><meta charset="utf-8"><title>'.$t('title').'</title><style>'.self::CSS.'</style></head><body>'.implode('', $parts).'</body></html>';
    }

    public static function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(120, 1), new SvgImageBackEnd)))->writeString($url);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    private const CSS = '
        @page { size: A4; }
        * { box-sizing: border-box; }
        body { font-family: "Noto Sans", "Noto Sans Devanagari", sans-serif; color: #0f172a; font-size: 11pt; margin: 0; }
        .letterhead { width: 100%; max-height: 120px; object-fit: contain; margin-bottom: 8px; }
        header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #1d4ed8; padding-bottom: 8px; margin-bottom: 12px; }
        header img { height: 56px; width: 56px; object-fit: contain; }
        h1 { font-size: 16pt; margin: 0; } h2 { font-size: 12pt; margin: 14px 0 6px; color: #1d4ed8; }
        .muted { color: #475569; font-size: 9pt; margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: 600; } td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
        .result { font-size: 13pt; font-weight: 600; margin: 8px 0; }
        .signs { display: flex; justify-content: space-between; margin-top: 48px; } .signs div { border-top: 1px solid #0f172a; width: 30%; padding-top: 4px; text-align: center; }
        footer { display: flex; gap: 12px; align-items: center; margin-top: 24px; border-top: 1px solid #cbd5e1; padding-top: 8px; }
        footer .qr svg { width: 96px; height: 96px; } .url { word-break: break-all; font-size: 7pt; }
    ';

    /**
     * @param  array{name: string, logo_path: string|null, language: string}  $school
     */
    private function header(ReportCard $card, array $school, \Closure $t): string
    {
        $logo = $school['logo_path'] !== null && Storage::disk('local')->exists($school['logo_path'])
            ? '<img alt="" src="'.self::dataUri($school['logo_path'], str_ends_with($school['logo_path'], '.svg') ? 'image/svg+xml' : 'image/png').'">'
            : '';

        return '<header>'.$logo.'<div><h1>'.e($school['name']).'</h1><p class="muted">'.$t('title').' · '.$t('year', ['year' => $card->year_name]).'</p></div></header>';
    }

    /**
     * @param  array{admission_no?: string|null, dob?: string|null, roll_no?: int|null}  $student
     */
    private function student(ReportCard $card, array $student, \Closure $t): string
    {
        $rows = [
            $t('student') => $card->student_name,
            $t('class') => $card->section_label,
            $t('admission_no') => $student['admission_no'] ?? null,
            $t('roll_no') => isset($student['roll_no']) ? (string) $student['roll_no'] : null,
        ];
        $html = '';
        foreach ($rows as $label => $value) {
            $html .= '<div><strong>'.$label.':</strong> '.e($value ?? '—').'</div>';
        }

        return '<section class="grid">'.$html.'</section>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function scholastic(array $snapshot, \Closure $t): string
    {
        $subjects = $snapshot['subjects'] ?? [];
        if ($subjects === []) {
            return '';
        }
        $terms = $snapshot['scheme']['terms'] ?? [];
        $head = '<tr><th>'.$t('subject').'</th>';
        foreach ($terms as $term) {
            $head .= '<th>'.e($term).'</th>';
        }
        $head .= '<th>'.$t('total').'</th><th>'.$t('grade').'</th></tr>';
        $body = '';
        foreach ($subjects as $s) {
            $body .= '<tr><td>'.e($s['subject']).'</td>';
            foreach ($s['terms'] as $term) {
                $cells = [];
                foreach ($term['components'] as $c) {
                    $cells[] = e($c['name']).': '.($c['obtained'] === null ? '—' : e($c['obtained']).'/'.e($c['max']));
                }
                $body .= '<td class="muted">'.implode('<br>', $cells).'</td>';
            }
            $body .= '<td class="num">'.e($s['percent'] ?? '—').($s['grace'] !== null ? ' *' : '').'</td><td>'.e($s['grade'] ?? '—').'</td></tr>';
        }
        $grace = array_filter($subjects, fn ($s) => $s['grace'] !== null) !== [] ? '<p class="muted">* '.$t('grace').'</p>' : '';

        return '<h2>'.$t('scholastic').'</h2><table>'.$head.$body.'</table>'.$grace;
    }

    private function coScholastic(ReportCard $card, \Closure $t): string
    {
        if ($card->co_scholastic === []) {
            return '';
        }
        $areas = (array) config('reports.co_scholastic.areas');
        $rows = '';
        foreach ($card->co_scholastic as $key => $grade) {
            $rows .= '<tr><td>'.e($areas[$key] ?? $key).'</td><td>'.e($grade).'</td></tr>';
        }

        return '<h2>'.$t('co_scholastic').'</h2><table><tr><th>'.$t('subject').'</th><th>'.$t('grade').'</th></tr>'.$rows.'</table>';
    }

    private function attendance(ReportCard $card, \Closure $t): string
    {
        $a = $card->attendance;
        if ($a === null || $a['working'] === 0) {
            return '';
        }
        $present = rtrim(rtrim(number_format($a['present_halves'] / 2, 1, '.', ''), '0'), '.');

        return '<h2>'.$t('attendance').'</h2><p>'.$t('attendance_line', ['present' => $present, 'working' => (string) $a['working']]).'</p>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function descriptive(ReportCard $card, array $snapshot, \Closure $t): string
    {
        $remarks = $card->remarks['subjects'] ?? [];
        if ($remarks === []) {
            return '';
        }
        $names = array_column((array) ($snapshot['subjects'] ?? []), 'subject', 'subject_id');
        $rows = '';
        foreach ($remarks as $subjectId => $text) {
            $rows .= '<tr><td>'.e($names[$subjectId] ?? '').'</td><td>'.nl2br(e($text)).'</td></tr>';
        }

        return '<h2>'.$t('descriptive').'</h2><table>'.$rows.'</table>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function remarks(ReportCard $card, array $snapshot, \Closure $t): string
    {
        $teacher = $card->remarks['class_teacher'] ?? null;
        if ($teacher === null || $teacher === '') {
            return '';
        }

        return '<h2>'.$t('remarks').'</h2><p><strong>'.$t('class_teacher_remark').':</strong> '.nl2br(e($teacher)).'</p>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function result(array $snapshot, \Closure $t): string
    {
        $overall = $snapshot['overall'] ?? null;
        if (! is_array($overall) || $overall['percent'] === null) {
            return '';
        }

        return '<h2>'.$t('result').'</h2><p class="result">'.$t('results.'.$overall['result']).' · '.$t('overall', ['percent' => $overall['percent'], 'grade' => $overall['grade'] ?? '—']).'</p>';
    }

    private function signatures(\Closure $t): string
    {
        return '<div class="signs"><div>'.$t('signatures.class_teacher').'</div><div>'.$t('signatures.principal').'</div><div>'.$t('signatures.parent').'</div></div>';
    }

    private static function dataUri(string $path, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('local')->get($path));
    }
}
