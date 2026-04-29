<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Settings\AccountingSettings;
use App\Modules\Purchasing\Models\Document;
use Carbon\Carbon;

class FinancialYearService
{
    public function __construct(private readonly AccountingSettings $settings) {}

    public function startMonth(): int
    {
        return $this->settings->financial_year_start_month;
    }

    /**
     * Label of the financial year that contains today's date, e.g. "2025/2026".
     */
    public function currentYearLabel(): string
    {
        $now = now();
        $startYear = $now->month >= $this->startMonth() ? $now->year : $now->year - 1;

        return $startYear.'/'.($startYear + 1);
    }

    /**
     * All available financial year labels, most recent first.
     * Starts from the year containing the earliest posted purchase invoice.
     *
     * @return array<string, string>
     */
    public function availableYears(): array
    {
        $startMonth = $this->startMonth();

        $earliestDate = Document::where('document_type', 'purchase_invoice')
            ->where('status', 'posted')
            ->min('issue_date');

        if ($earliestDate) {
            $date = Carbon::parse($earliestDate);
            $firstFyStart = $date->month >= $startMonth ? $date->year : $date->year - 1;
        } else {
            $firstFyStart = now()->year - 1;
        }

        $now = now();
        $currentFyStart = $now->month >= $startMonth ? $now->year : $now->year - 1;

        $years = [];

        for ($y = $firstFyStart; $y <= $currentFyStart; $y++) {
            $label = $y.'/'.($y + 1);
            $years[$label] = $label;
        }

        return array_reverse($years, true);
    }

    /**
     * Month select options for the financial year.
     * Keys are fiscal positions (1 = first month of FY, 12 = last month).
     *
     * @return array<int, string>
     */
    public function monthOptions(): array
    {
        $startMonth = $this->startMonth();
        $options = [];

        for ($i = 0; $i < 12; $i++) {
            $calendarMonth = (($startMonth - 1 + $i) % 12) + 1;
            $options[strval($i + 1)] = Carbon::create(2000, $calendarMonth, 1)->format('F');
        }

        return $options;
    }

    /**
     * Start and end Carbon dates for an entire financial year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function yearBounds(string $yearLabel): array
    {
        [$startYear] = explode('/', $yearLabel);
        $startYear = (int) $startYear;
        $startMonth = $this->startMonth();

        $start = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        $end = Carbon::create($startYear + 1, $startMonth, 1)->subDay()->endOfDay();

        return [$start, $end];
    }

    /**
     * Start and end Carbon dates for a fiscal month position within a year.
     * $fiscalMonth: 1 = first month of FY (e.g. March), 12 = last (e.g. February).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function monthBounds(string $yearLabel, int $fiscalMonth): array
    {
        [$startYear] = explode('/', $yearLabel);
        $startYear = (int) $startYear;
        $startMonth = $this->startMonth();

        $calendarMonth = (($startMonth - 1 + $fiscalMonth - 1) % 12) + 1;
        $year = $calendarMonth >= $startMonth ? $startYear : $startYear + 1;

        $start = Carbon::create($year, $calendarMonth, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        return [$start, $end];
    }
}
