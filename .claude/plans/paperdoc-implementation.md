# Paperdoc Multi-Format Invoice Input

## What This Is

Integration of [Paperdoc](https://paperdoc.dev/documents) to allow DOCX, XLSX, and CSV supplier invoices to be uploaded alongside PDFs. The LLM extraction step (Claude API) is unchanged — Paperdoc supplements the text extraction layer and the upload UI is widened to accept more formats.

## How to Resume

Each step has a checkbox. When a session completes a step, mark it `[x]`. Start the next session from the first unchecked box. If a step is partially done, leave it unchecked and add a note below it.

---

## Steps

- [x] **Step 1 — Install & configure Paperdoc**
  - `composer require paperdoc-dev/paperdoc-lib`
  - `php artisan vendor:publish --tag=paperdoc-config`
  - Verify PHP extensions are present: `ext-dom`, `ext-mbstring`, `ext-zip`, `ext-zlib`

- [x] **Step 2 — Create `DocumentTextExtractor`**
  - New file: `app/Modules/Purchasing/Services/DocumentTextExtractor.php`
  - PDF → existing `PdfExtractor` (pdftotext + Claude vision fallback — fast path preserved)
  - DOCX / XLSX / CSV → `Paperdoc::open($path)` then `Paperdoc::renderAs($doc, 'md')`
  - Route by MIME type detected by `MagikaService`

- [x] **Step 3 — Expand `MagikaService`**
  - File: `app/Modules/Purchasing/Services/Pdf/MagikaService.php`
  - Added `assertIsSupportedFormat(string $absolutePath): MagikaResult`
  - Supported MIMEs: `application/pdf`, DOCX/XLSX Office Open XML types, `text/csv`
  - `assertIsPdf()` kept; fallback detects DOCX/XLSX from ZIP entry names
  - `MagikaResult` extended with `isDocx()`, `isXlsx()`, `isCsv()`, `isSupportedFormat()`

- [x] **Step 4 — Rename media collection**
  - File: `app/Modules/Purchasing/Models/Document.php`
  - `registerMediaCollections()`: renamed `source_pdf` → `source_document`
  - Data migration: `2026_04_30_100622_rename_source_pdf_to_source_document_in_media.php` (migrated 4 existing rows)

- [x] **Step 5 — Refactor `DocumentService`**
  - File: `app/Modules/Purchasing/Services/DocumentService.php`
  - Renamed `createFromPdf()` → `createFromFile()`
  - Updated `source_pdf` → `source_document` references
  - Replaced `MagikaService::assertIsPdf()` → `assertIsSupportedFormat()`
  - Replaced `ProcessInvoicePdf::dispatch()` → `ProcessInvoiceDocument::dispatch()`

- [x] **Step 6 — Rename the processing job**
  - Created `app/Modules/Purchasing/Jobs/ProcessInvoiceDocument.php`
  - Deleted `app/Modules/Purchasing/Jobs/ProcessInvoicePdf.php`

- [x] **Step 7 — Wire `InvoiceProcessingService` to new extractor**
  - File: `app/Modules/Purchasing/Services/InvoiceProcessingService.php`
  - Replaced `PdfExtractor` injection → `DocumentTextExtractor`
  - Updated `getFirstMedia('source_pdf')` → `getFirstMedia('source_document')`

- [x] **Step 8 — Update upload UI**
  - File: `resources/views/livewire/pages/purchase-invoices/index.blade.php`
  - Validation: `mimes:pdf` → `mimes:pdf,docx,xlsx,csv`
  - File input: `accept=".pdf"` → `accept=".pdf,.docx,.xlsx,.csv"`
  - Copy: "Upload Invoice PDF" → "Upload Invoice"
  - Copy: "Upload a PDF to get started." → "Upload an invoice to get started."

- [x] **Step 9 — Tests**
  - Updated `DocumentServiceTest`: `createFromPdf` → `createFromFile`
  - Updated `InvoiceProcessingServiceTest`: `PdfExtractor` mock → `DocumentTextExtractor`, `source_pdf` → `source_document`, fixed pre-existing `model_type` morph bug
  - New `tests/Unit/Documents/DocumentTextExtractorTest.php`: 4 unit tests for routing logic
  - New `tests/Feature/Documents/PurchaseInvoiceUploadTest.php`: 6 feature tests (format validation + service call)

---

## Verification

```bash
composer run test                                           # full suite must pass
php artisan test --compact --filter=PurchaseInvoice        # invoice-specific tests
php artisan test --compact --filter=DocumentTextExtractor  # new extractor unit tests
```

Manual: upload a `.docx` invoice via UI → job dispatches → LLM extraction runs → `DocumentLine` records created.
