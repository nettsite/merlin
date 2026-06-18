<?php

namespace App\Modules\Billing\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Spatie\Holidays\Holidays;

class WorkingDayCalculator
{
    public function isWorkingDay(CarbonInterface $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        return ! in_array($date->toDateString(), $this->holidaysForYear($date->year), strict: true);
    }

    public function latestWorkingDayOnOrBefore(CarbonInterface $d): Carbon
    {
        $date = Carbon::instance($d);

        while (! $this->isWorkingDay($date)) {
            $date->subDay();
        }

        return $date;
    }

    public function earliestWorkingDayOnOrAfter(CarbonInterface $d): Carbon
    {
        $date = Carbon::instance($d);

        while (! $this->isWorkingDay($date)) {
            $date->addDay();
        }

        return $date;
    }

    public function addBusinessDays(CarbonInterface $d, int $n): Carbon
    {
        $date = Carbon::instance($d);
        $step = $n > 0 ? 1 : -1;
        $remaining = abs($n);

        while ($remaining > 0) {
            $date->addDays($step);

            if ($this->isWorkingDay($date)) {
                $remaining--;
            }
        }

        return $date;
    }

    /** @return array<string> */
    public function holidaysForYear(int $year): array
    {
        return Cache::remember("za_holidays_{$year}", now()->addYear(), function () use ($year): array {
            $holidays = Holidays::for('za', $year)->get();

            return array_map(
                fn ($holiday) => $holiday->date->toDateString(),
                $holidays,
            );
        });
    }
}
