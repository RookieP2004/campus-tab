<?php

namespace App\Modules\Reports\Domain\Pdf;

/** Turns one self-contained HTML page into PDF bytes. */
interface PdfRenderer
{
    public function render(string $html): string;
}
