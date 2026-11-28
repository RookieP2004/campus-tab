<?php

namespace App\Support\Uploads;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every file in a school request is scanned before any controller sees it
 * (notice attachments, leave documents, letterheads, register photos, the
 * school logo, imports). It runs in the `school` middleware group, so no
 * upload route can skip it.
 *
 *  - Infected: 422 file_infected; nothing is stored; an audit entry
 *    (upload.infected: field, size, SHA-256 and what was found, never the
 *    content) and a warning in the log.
 *  - Scanner down: 503 virus_scan_unavailable; the upload is refused
 *    (fail closed). Everything else keeps working.
 */
class ScanUploadedFiles
{
    public function __construct(private readonly VirusScanner $scanner, private readonly AuditRecorder $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::flatten($request->allFiles()) as $field => $file) {
            if (! $file->isValid()) {
                continue;
            }
            try {
                $found = $this->scanner->scan((string) $file->getRealPath());
            } catch (ScannerUnavailable $e) {
                Log::error('Upload refused: virus scanner unavailable', ['route' => $request->route()?->getName(), 'error' => $e->getMessage()]);

                throw new ApiException('virus_scan_unavailable', 'Files cannot be checked for viruses right now, so they are not accepted. Try again in a few minutes.', 503);
            }
            if ($found !== null) {
                $facts = ['field' => $field, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', (string) $file->getRealPath()), 'found' => $found, 'route' => $request->route()?->getName()];
                Log::warning('Upload refused: infected file', $facts);
                $this->audit->record('upload.infected', 'security', 'upload', null, null, $facts);

                throw new ApiException('file_infected', 'This file contains a virus or malware and was not accepted.', 422, ['field' => $field]);
            }
        }

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<string, UploadedFile>
     */
    private static function flatten(array $files, string $prefix = ''): array
    {
        $out = [];
        foreach ($files as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if ($value instanceof UploadedFile) {
                $out[$name] = $value;
            } elseif (is_array($value)) {
                $out += self::flatten($value, $name);
            }
        }

        return $out;
    }
}
