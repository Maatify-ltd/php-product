<?php

declare(strict_types=1);

namespace Maatify\Product\Verification;

use RuntimeException;

/**
 * Runs test workers that print one ready line, wait for a release line and report an outcome.
 * Readiness and output have separate deadlines. Stderr goes to files so a worker's diagnostics
 * cannot fill a pipe that the parent is not reading.
 */
final class BarrierProcessGroup
{
    private const int MAX_OUTPUT_BYTES = 65536;

    /**
     * @param list<list<string>> $commands
     * @param array<string, string>|null $environment
     * @return list<string>
     */
    public static function run(array $commands, ?array $environment = null, int $startupSeconds = 15, int $resultSeconds = 90): array
    {
        $workers = [];
        try {
            $readyDeadline = hrtime(true) + $startupSeconds * 1_000_000_000;
            foreach ($commands as $command) {
                $stderr = tempnam(sys_get_temp_dir(), 'product-worker-');
                if ($stderr === false) {
                    throw new RuntimeException('Could not create a worker diagnostic file.');
                }
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes, null, $environment);
                if (!is_resource($process)) {
                    unlink($stderr);
                    throw new RuntimeException('Could not start a worker.');
                }
                $workers[] = [$process, $pipes[0], $pipes[1], $stderr];
                stream_set_blocking($pipes[1], false);
            }

            foreach ($workers as [$process, $stdin, $stdout, $stderr]) {
                if (self::read($stdout, $readyDeadline, true, $stderr) !== "ready\n") {
                    throw new RuntimeException('Worker did not become ready. ' . self::diagnostic($stderr));
                }
            }
            $outputDeadline = hrtime(true) + $resultSeconds * 1_000_000_000;
            foreach ($workers as [$process, $stdin, $stdout, $stderr]) {
                if (fwrite($stdin, "go\n") !== 3) {
                    throw new RuntimeException('Could not release worker. ' . self::diagnostic($stderr));
                }
                fclose($stdin);
            }

            $outcomes = [];
            foreach ($workers as [$process, $stdin, $stdout, $stderr]) {
                $outcome = self::read($stdout, $outputDeadline, false, $stderr);
                fclose($stdout);
                while (proc_get_status($process)['running']) {
                    if (hrtime(true) >= $outputDeadline) {
                        throw new RuntimeException('Worker did not exit before the result deadline. ' . self::diagnostic($stderr));
                    }
                    usleep(10_000);
                }
                if (proc_close($process) !== 0) {
                    throw new RuntimeException('Worker failed. ' . self::diagnostic($stderr) . $outcome);
                }
                $outcomes[] = $outcome;
            }

            return $outcomes;
        } finally {
            foreach ($workers as [$process, $stdin, $stdout, $stderr]) {
                foreach ([$stdin, $stdout] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }
                    proc_close($process);
                }
                unlink($stderr);
            }
        }
    }

    /** @param resource $stream */
    private static function read($stream, int $deadline, bool $line, string $stderr): string
    {
        $output = '';
        while (!feof($stream)) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Timed out waiting for worker output. ' . self::diagnostic($stderr));
            }
            $read = [$stream];
            $write = [];
            $except = [];
            $ready = stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1000));
            if ($ready === false) {
                throw new RuntimeException('Could not wait for worker output. ' . self::diagnostic($stderr));
            }
            if ($ready === 0) {
                continue;
            }

            $chunk = $line ? fgets($stream, 4096) : fread($stream, 4096);
            if ($chunk !== false) {
                $output .= $chunk;
            }
            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                throw new RuntimeException('Worker exceeded the output limit. ' . self::diagnostic($stderr));
            }
            if ($line && str_ends_with($output, "\n")) {
                break;
            }
        }

        return $output;
    }

    private static function diagnostic(string $path): string
    {
        $output = file_get_contents($path, length: self::MAX_OUTPUT_BYTES);

        return $output === false ? '' : $output;
    }
}
