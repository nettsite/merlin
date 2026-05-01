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
        $periodStart = $startDate->copy()->startOfMonth()->addDays($billingPeriodDay - 1);

        if ($periodStart->gt($startDate)) {
            $periodStart->subMonthNoOverflow();
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
