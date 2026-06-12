<?php

namespace App\Modules\Billing\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class ProRataCalculator
{
    /**
     * Calculate the pro rata factor for a partial first billing period.
     *
     * If startDate falls exactly on the billingPeriodDay, factor is 1.0 (full period).
     *
     * @return array{factor: float, period_start: Carbon, period_end: Carbon, days_active: int, days_in_period: int}
     */
    public function calculate(CarbonInterface $startDate, int $billingPeriodDay): array
    {
        if ($startDate->day === $billingPeriodDay) {
            $periodEnd = $startDate->copy()->addMonthNoOverflow()->subDay();
            $daysInPeriod = (int) $startDate->diffInDays($periodEnd) + 1;

            return [
                'factor' => 1.0,
                'period_start' => $startDate->copy(),
                'period_end' => $periodEnd,
                'days_active' => $daysInPeriod,
                'days_in_period' => $daysInPeriod,
            ];
        }

        // Find the start of the billing period that contains startDate.
        // Period starts on billingPeriodDay; if that day hasn't arrived yet this
        // month relative to startDate, the period started the previous month.
        // The day is clamped to the month's length so day 31 in February
        // anchors to Feb 28 instead of overflowing into March.
        $periodStart = $startDate->copy()->startOfMonth();
        $periodStart->day(min($billingPeriodDay, $periodStart->daysInMonth));

        if ($periodStart->gt($startDate)) {
            $periodStart->subMonthNoOverflow();
            $periodStart->day(min($billingPeriodDay, $periodStart->daysInMonth));
        }

        $periodEnd = $periodStart->copy()->addMonthNoOverflow()->subDay();

        $daysInPeriod = (int) $periodStart->diffInDays($periodEnd) + 1;
        $daysActive = (int) $startDate->diffInDays($periodEnd) + 1;

        return [
            'factor' => round($daysActive / $daysInPeriod, 6),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'days_active' => $daysActive,
            'days_in_period' => $daysInPeriod,
        ];
    }

    public function isFullPeriod(CarbonInterface $startDate, int $billingPeriodDay): bool
    {
        return $startDate->day === $billingPeriodDay;
    }
}
