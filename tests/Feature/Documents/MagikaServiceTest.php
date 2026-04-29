<?php

use App\Exceptions\InvalidFileTypeException;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use Symfony\Component\Process\Process;

it('detects a PDF by magic bytes when magika is not installed', function (): void {
    $service = new MagikaService('__nonexistent_magika__');
    $file = tempnam(sys_get_temp_dir(), 'merlin_test_');
    file_put_contents($file, '%PDF-1.4 fake content');

    $result = $service->detect($file);

    expect($result->isPdf())->toBeTrue()
        ->and($result->usedFallback)->toBeTrue();

    unlink($file);
});

it('detects a non-PDF when magika is not installed', function (): void {
    $service = new MagikaService('__nonexistent_magika__');
    $file = tempnam(sys_get_temp_dir(), 'merlin_test_');
    file_put_contents($file, 'hello world, not a pdf');

    $result = $service->detect($file);

    expect($result->isPdf())->toBeFalse()
        ->and($result->usedFallback)->toBeTrue();

    unlink($file);
});

it('does not throw for a PDF in assertIsPdf', function (): void {
    $service = new MagikaService('__nonexistent_magika__');
    $file = tempnam(sys_get_temp_dir(), 'merlin_test_');
    file_put_contents($file, '%PDF-1.4 fake content');

    expect(fn () => $service->assertIsPdf($file))->not->toThrow(InvalidFileTypeException::class);

    unlink($file);
});

it('throws InvalidFileTypeException for a non-PDF in assertIsPdf', function (): void {
    $service = new MagikaService('__nonexistent_magika__');
    $file = tempnam(sys_get_temp_dir(), 'merlin_test_');
    file_put_contents($file, 'this is definitely not a pdf');

    expect(fn () => $service->assertIsPdf($file))->toThrow(InvalidFileTypeException::class);

    unlink($file);
});

it('exception message includes the filename and detected type', function (): void {
    $service = new MagikaService('__nonexistent_magika__');
    $file = sys_get_temp_dir().'/merlin_test_notapdf.txt';
    file_put_contents($file, 'plain text content');

    try {
        $service->assertIsPdf($file);
        $this->fail('Expected InvalidFileTypeException');
    } catch (InvalidFileTypeException $e) {
        expect($e->getMessage())
            ->toContain('merlin_test_notapdf.txt')
            ->toContain('detected');
    } finally {
        @unlink($file);
    }
});

it('uses the live magika binary when available', function (): void {
    $check = new Process(['which', 'magika']);
    $check->run();
    if (! $check->isSuccessful()) {
        $this->markTestSkipped('magika binary not installed');
    }

    $service = new MagikaService('magika');

    // Minimal structurally-valid PDF so Magika's model classifies it correctly.
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
        ."xref\n0 3\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n"
        ."trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n110\n%%EOF\n";

    $file = tempnam(sys_get_temp_dir(), 'merlin_test_').'.pdf';
    file_put_contents($file, $pdf);

    $result = $service->detect($file);

    expect($result->usedFallback)->toBeFalse()
        ->and($result->isPdf())->toBeTrue()
        ->and($result->mimeType)->toBe('application/pdf');

    unlink($file);
});
