<?php

namespace App\Support\Uploads;

/**
 * Talks to clamd (the ClamAV daemon, the `clamav` container) over TCP with
 * the INSTREAM command: the file is streamed in chunks, never shared as a
 * path, so the scanner needs no access to our storage.
 */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 65536;

    public function __construct(private readonly string $host, private readonly int $port, private readonly float $timeout) {}

    public function scan(string $path): ?string
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new ScannerUnavailable('The upload could not be read for scanning.');
        }
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if ($socket === false) {
            fclose($file);
            throw new ScannerUnavailable("The virus scanner is not reachable ({$error}).");
        }
        stream_set_timeout($socket, (int) ceil($this->timeout * 4));

        try {
            fwrite($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = (string) fread($file, self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        // "stream: OK" | "stream: Eicar-Test-Signature FOUND" | "INSTREAM size limit exceeded. ERROR"
        if (str_ends_with($reply, ' OK')) {
            return null;
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1) {
            return $m[1];
        }

        throw new ScannerUnavailable('The virus scanner answered: '.mb_substr($reply, 0, 120));
    }
}
