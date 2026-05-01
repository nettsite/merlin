<?php

namespace Tests\Unit\Documents;

use App\Modules\Purchasing\DTO\MagikaResult;
use App\Modules\Purchasing\Services\DocumentTextExtractor;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Services\Pdf\PdfExtractor;
use Mockery;
use Paperdoc\Contracts\DocumentInterface;
use Paperdoc\Facades\Paperdoc;
use Tests\TestCase;

class DocumentTextExtractorTest extends TestCase
{
    private function makeResult(string $mimeType): MagikaResult
    {
        $labelMap = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/csv' => 'csv',
        ];

        return new MagikaResult(
            label: $labelMap[$mimeType] ?? 'unknown',
            score: 1.0,
            description: 'stub',
            mimeType: $mimeType,
            group: 'document',
            isText: $mimeType === 'text/csv',
            usedFallback: false,
        );
    }

    public function test_pdf_routes_to_pdf_extractor(): void
    {
        $path = '/tmp/invoice.pdf';

        $magika = Mockery::mock(MagikaService::class);
        $magika->expects('detect')->with($path)->andReturn($this->makeResult('application/pdf'));

        $pdf = Mockery::mock(PdfExtractor::class);
        $pdf->expects('extract')->with($path, null)->andReturn('extracted pdf text');

        $extractor = new DocumentTextExtractor($pdf, $magika);

        $this->assertSame('extracted pdf text', $extractor->extract($path));
    }

    public function test_docx_routes_to_paperdoc(): void
    {
        $path = '/tmp/invoice.docx';
        $fakeDoc = Mockery::mock(DocumentInterface::class);

        $magika = Mockery::mock(MagikaService::class);
        $magika->expects('detect')->with($path)->andReturn(
            $this->makeResult('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        );

        $pdf = Mockery::mock(PdfExtractor::class);
        $pdf->shouldNotReceive('extract');

        Paperdoc::shouldReceive('open')->with($path)->andReturn($fakeDoc);
        Paperdoc::shouldReceive('renderAs')->with($fakeDoc, 'md')->andReturn('# Invoice markdown');

        $extractor = new DocumentTextExtractor($pdf, $magika);

        $this->assertSame('# Invoice markdown', $extractor->extract($path));
    }

    public function test_xlsx_routes_to_paperdoc(): void
    {
        $path = '/tmp/invoice.xlsx';
        $fakeDoc = Mockery::mock(DocumentInterface::class);

        $magika = Mockery::mock(MagikaService::class);
        $magika->expects('detect')->with($path)->andReturn(
            $this->makeResult('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        );

        $pdf = Mockery::mock(PdfExtractor::class);
        $pdf->shouldNotReceive('extract');

        Paperdoc::shouldReceive('open')->with($path)->andReturn($fakeDoc);
        Paperdoc::shouldReceive('renderAs')->with($fakeDoc, 'md')->andReturn('| Item | Price |');

        $extractor = new DocumentTextExtractor($pdf, $magika);

        $this->assertSame('| Item | Price |', $extractor->extract($path));
    }

    public function test_csv_routes_to_paperdoc(): void
    {
        $path = '/tmp/invoice.csv';
        $fakeDoc = Mockery::mock(DocumentInterface::class);

        $magika = Mockery::mock(MagikaService::class);
        $magika->expects('detect')->with($path)->andReturn($this->makeResult('text/csv'));

        $pdf = Mockery::mock(PdfExtractor::class);
        $pdf->shouldNotReceive('extract');

        Paperdoc::shouldReceive('open')->with($path)->andReturn($fakeDoc);
        Paperdoc::shouldReceive('renderAs')->with($fakeDoc, 'md')->andReturn('item,price');

        $extractor = new DocumentTextExtractor($pdf, $magika);

        $this->assertSame('item,price', $extractor->extract($path));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
