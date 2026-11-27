<?php

namespace App\Modules\People\Domain\Import;

use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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
     */
    public static function write(string $path, array $headers, array $rows): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
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
