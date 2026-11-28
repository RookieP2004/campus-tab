<?php

namespace App\Modules\People\Domain\Import;

use App\Support\Exports\ExportGuard;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Options\HeaderFooter;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Reads and writes the import spreadsheets (OpenSpout, streaming, so a large
 * file never sits in memory at once). The first row holds the column names;
 * dates become YYYY-MM-DD and numbers that were typed as text stay text.
 */
final class Spreadsheet
{
    public const MAX_ROWS = 5000;

    /**
     * @return list<array<string, string>> one entry per data row, keyed by normalised header
     */
    public static function read(string $path, string $name): array
    {
        $reader = str_ends_with(mb_strtolower($name), '.csv') ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $headers = null;
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(self::cell(...), $row->toArray());

                    // The export watermark (first row of every file we hand out) is not data.
                    if ($headers === null && str_starts_with((string) ($cells[0] ?? ''), ExportGuard::MARK)) {
                        continue;
                    }

                    if ($headers === null) {
                        $headers = array_map(fn ($h) => self::header($h), $cells);

                        continue;
                    }

                    if (implode('', $cells) === '' || str_starts_with((string) ($cells[0] ?? ''), '#')) {
                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw new RuntimeException('A file can hold at most '.self::MAX_ROWS.' rows.');
                    }

                    $assoc = [];
                    foreach ($headers as $i => $header) {
                        if ($header !== '') {
                            $assoc[$header] = trim($cells[$i] ?? '');
                        }
                    }

                    $rows[] = $assoc;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|null>>  $rows
     * @param  array{text: string, school: string, by: string, at: string}|null  $watermark  from ExportGuard::watermark()
     */
    public static function write(string $path, array $headers, array $rows, ?array $watermark = null): void
    {
        // Who, when and which school: in the file's properties, in the
        // printed footer of every page, and as the first row.
        $options = $watermark === null ? new XlsxOptions : new XlsxOptions(
            // OpenSpout writes this into the sheet's XML as it is: escape it here.
            headerFooter: new HeaderFooter(oddFooter: htmlspecialchars('&L'.self::footer($watermark['text']).'&RPage &P of &N', ENT_XML1 | ENT_QUOTES, 'UTF-8')),
            properties: new Properties(
                title: 'Campus TAB export', subject: $watermark['school'], application: 'Campus TAB', creator: $watermark['by'], lastModifiedBy: $watermark['by'],
                description: $watermark['text'], category: 'Confidential',
            ),
        );
        $writer = new XlsxWriter($options);
        $writer->openToFile($path);
        if ($watermark !== null) {
            $writer->addRow(Row::fromValues([$watermark['text']]));
        }
        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    /** Excel header/footer codes start with "&": a literal ampersand is doubled. */
    private static function footer(string $text): string
    {
        return str_replace('&', '&&', mb_substr($text, 0, 240));
    }

    public static function header(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s*\(.*\)\s*$/', '', $value) ?? $value;

        return trim(preg_replace('/[^a-z0-9:_]+/', '_', $value) ?? '', '_');
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_bool($value) => $value ? 'yes' : 'no',
            $value === null => '',
            default => (string) $value,
        };
    }
}
