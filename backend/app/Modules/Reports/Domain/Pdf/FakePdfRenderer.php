<?php

namespace App\Modules\Reports\Domain\Pdf;

use LogicException;

/**
 * Testing only: a tiny valid PDF that carries the HTML's text, so tests can
 * check what a card says without a Gotenberg container.
 */
class FakePdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        if (! app()->environment('testing')) {
            throw new LogicException('The fake PDF renderer runs only in tests.');
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(style|svg)[^>]*>.*?<\/\1>/s', '', $html) ?? '')) ?? '');

        return "%PDF-1.4\n% fake report card\n".$text."\n%%EOF\n";
    }
}
