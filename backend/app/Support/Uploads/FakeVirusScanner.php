<?php

namespace App\Support\Uploads;

/**
 * Tests only: finds the EICAR test string (the harmless file every
 * antivirus treats as a virus), and can pretend the scanner is down.
 */
class FakeVirusScanner implements VirusScanner
{
    // Built from two halves so that no file in the repository contains the
    // whole test string (desktop antivirus would quarantine it).
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public bool $down = false;

    /** @var list<string> */
    public array $scanned = [];

    public function scan(string $path): ?string
    {
        if ($this->down) {
            throw new ScannerUnavailable('The virus scanner is not reachable (test).');
        }
        $this->scanned[] = $path;

        return str_contains((string) file_get_contents($path), self::EICAR) ? 'Eicar-Test-Signature' : null;
    }
}
