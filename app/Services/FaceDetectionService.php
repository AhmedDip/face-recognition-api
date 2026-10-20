<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class FaceDetectionService
{
    public function detectFromBase64(string $base64Image): array
    {
        $raw = $this->decodeBase64Image($base64Image);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'face_');

        if ($temporaryFile === false || file_put_contents($temporaryFile, $raw) === false) {
            throw new RuntimeException('Unable to prepare image for face detection');
        }

        try {
            return $this->runNode($temporaryFile);
        } finally {
            @unlink($temporaryFile);
        }
    }

    public function decodeBase64Image(string $base64Image): string
    {
        if (str_contains($base64Image, ',')) {
            [$header, $base64Image] = explode(',', $base64Image, 2);
            if (! preg_match('/^data:image\/[a-z0-9.+-]+;base64$/i', $header)) {
                throw new InvalidArgumentException('Invalid image data URI');
            }
        }

        $raw = base64_decode($base64Image, true);
        if ($raw === false || $raw === '' || @getimagesizefromstring($raw) === false) {
            throw new InvalidArgumentException('Invalid image payload');
        }

        return $raw;
    }

    private function runNode(string $imagePath): array
    {
        $node    = config('services.face_service.node_binary', 'node');
        $script  = config('services.face_service.script_path');
        $timeout = (int) config('services.face_service.timeout', 20);

        $cmd = sprintf(
            '%s %s %s',
            escapeshellcmd($node),
            escapeshellarg($script),
            escapeshellarg($imagePath),
        );

        $output = $this->shellExecWithTimeout($cmd, $timeout);

        if ($output === null || $output === '') {
            throw new RuntimeException('Face detection failed (no output)');
        }

        $result = json_decode($output, true);
        if (! is_array($result)) {
            throw new RuntimeException('Face detection returned invalid JSON');
        }
        if (isset($result['error'])) {
            throw new RuntimeException('Face detection error: '.$result['error']);
        }

        return $result;
    }

    private function shellExecWithTimeout(string $cmd, int $timeoutSeconds): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start  = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }
            if (time() - $start >= $timeoutSeconds) {
                proc_terminate($process, 9);
                break;
            }
            usleep(50_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $output = trim($stdout);
        if ($output === '' && trim($stderr) !== '') {
            throw new RuntimeException('Face detection process failed: '.trim($stderr));
        }

        return $output;
    }
}
