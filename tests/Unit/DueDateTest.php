<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Support\DueDate;
use Carbon\Carbon;

class DueDateTest extends TestCase
{
    public function testDueOnPresentation()
    {
        $date = Carbon::create(2022, 1, 15);
        $formattedDate = DueDate::due_on_presentation($date);

        $this->assertEquals('2022-01-15', $formattedDate);
    }

    public function testFirstOfMonthMonthly()
    {
        $date = Carbon::create(2022, 1, 15);
        $formattedDate = DueDate::first_of_month($date, 'M');

        $this->assertEquals('2022-02-01', $formattedDate);
    }

    public function testFirstOfMonthYearly()
    {
        $date = Carbon::create(2022, 1, 15);
        $formattedDate = DueDate::first_of_month($date, 'Y');

        $this->assertEquals('2023-02-01', $formattedDate);
    }

    public function testFirstOfMonthDefault()
    {
        $date = Carbon::create(2022, 1, 15);
        $formattedDate = DueDate::first_of_month($date);

        $this->assertEquals('2022-02-01', $formattedDate);
    }
}