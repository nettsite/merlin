<?php

namespace App\Modules\Purchasing\Services\Pdf;

use App\Exceptions\InvalidFileTypeException;
use App\Modules\Purchasing\DTO\MagikaResult;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class MagikaService
{
    private const TIMEOUT = 15;

    private ?bool $available = null;

    public function __construct(
        private readonly string $binaryPath = 'magika',
    ) {}

    /**
     * Assert the file is a supported invoice format (PDF, DOCX, XLSX, CSV), throwing if not.
     *
     * @throws InvalidFileTypeException
     */
    public function assertIsSupportedFormat(string $absolutePath): MagikaResult
    {
        $result = $this->detect($absolutePath);

        if (! $result->isSupportedFormat()) {
            throw InvalidFileTypeException::unsupportedFormat($absolutePath, $result->mimeType);
        }

        return $result;
    }

    /**
     * Assert the file is a PDF, throwing if it is not.
     *
     * @throws InvalidFileTypeException
     */
    public function assertIsPdf(string $absolutePath): MagikaResult
    {
        $result = $this->detect($absolutePath);

        if (! $result->isPdf()) {
            throw InvalidFileTypeException::notPdf($absolutePath, $result->mimeType);
        }

        return $result;
    }

    /**
     * Detect the file type. Never throws — falls back to finfo on any failure.
     */
    public function detect(string $absolutePath): MagikaResult
    {
        if ($this->isMagikaAvailable()) {
            return $this->detectWithMagika($absolutePath);
        }

        Log::debug('MagikaService: binary not found, using PHP fallback', [
            'binary' => $this->binaryPath,
        ]);

        return $this->detectWithFallback($absolutePath);
    }

    private function isMagikaAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        return $this->available = (new ExecutableFinder)->find($this->binaryPath) !== null;
    }

    private function detectWithMagika(string $absolutePath): MagikaResult
    {
        $process = new Process([$this->binaryPath, '--json', $absolutePath]);
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('MagikaService: process failed, using PHP fallback', [
                'error' => $process->getErrorOutput(),
                'exit' => $process->getExitCode(),
            ]);

            return $this->detectWithFallback($absolutePath);
        }

        /** @var mixed $data */
        $data = json_decode($process->getOutput(), true);

        $item = $data[0] ?? null;
        $output = $item['result']['value']['output'] ?? null;

        if (
            ! is_array($data)
            || ($item['result']['status'] ?? null) !== 'ok'
            || ! is_array($output)
            || ! is_string($output['label'] ?? null)
            || ! is_string($output['mime_type'] ?? null)
        ) {
            Log::warning('MagikaService: unexpected output, using PHP fallback', [
                'output' => $process->getOutput(),
            ]);

            return $this->detectWithFallback($absolutePath);
        }

        return MagikaResult::fromRustOutput($item);
    }

    private function detectWithFallback(string $absolutePath): MagikaResult
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($absolutePath) ?: 'application/octet-stream';

        // Belt-and-suspenders: check the %PDF magic bytes directly.
        // finfo can be fooled on some systems by a renamed file.
        if ($mimeType !== 'application/pdf') {
            $handle = @fopen($absolutePath, 'rb');
            if ($handle !== false) {
                $header = fread($handle, 4);
                fclose($handle);
                if ($header === '%PDF') {
                    $mimeType = 'application/pdf';
                }
            }
        }

        // finfo reports DOCX/XLSX as application/zip on older libmagic versions.
        // Inspect the ZIP entries to distinguish them.
        if ($mimeType === 'application/zip') {
            $mimeType = $this->resolveZipMimeType($absolutePath);
        }

        return MagikaResult::fromFallback($mimeType);
    }

    private function resolveZipMimeType(string $absolutePath): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return 'application/zip';
        }

        $hasWord = false;
        $hasXl = false;

        for ($i = 0; $i < min($zip->numFiles, 20); $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (str_starts_with($name, 'word/')) {
                $hasWord = true;
            }
            if (str_starts_with($name, 'xl/')) {
                $hasXl = true;
            }
        }

        $zip->close();

        if ($hasWord) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if ($hasXl) {
            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return 'application/zip';
    }
}
