<?php

namespace App\Support\Uploads;

/**
 * Scans an uploaded file before it is stored (Phase 9 security pass).
 */
interface VirusScanner
{
    /**
     * @return string|null the name of what was found, or null when the file is clean
     *
     * @throws ScannerUnavailable when the scanner cannot be reached: the upload is refused (fail closed)
     */
    public function scan(string $path): ?string;
}
