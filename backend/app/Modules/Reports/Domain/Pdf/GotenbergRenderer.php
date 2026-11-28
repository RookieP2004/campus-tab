<?php

namespace App\Modules\Reports\Domain\Pdf;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Renders through Gotenberg's Chromium route (infra/docker-compose.yml runs
 * gotenberg/gotenberg:8, which ships the Noto fonts for Latin and
 * Devanagari). The HTML is self-contained: images are data: URIs, so
 * Gotenberg never fetches anything.
 */
class GotenbergRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        $response = Http::timeout((int) config('reports.pdf.timeout_seconds', 60))
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html'])
            ->post(rtrim((string) config('reports.pdf.gotenberg_url'), '/').'/forms/chromium/convert/html', [
                'paperWidth' => '8.27', 'paperHeight' => '11.69',
                'marginTop' => '0.4', 'marginBottom' => '0.4', 'marginLeft' => '0.4', 'marginRight' => '0.4',
                'printBackground' => 'true',
            ]);

        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
            throw new RuntimeException('The PDF service did not return a PDF (status '.$response->status().').');
        }

        return $response->body();
    }
}
