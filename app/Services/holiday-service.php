<?php

namespace App\Services;

use Carbon\Carbon;

class HolidayService
{
    /**
     * Philippine holidays for 2024-2026
     * This includes both fixed and moveable holidays
     */
    private static $fixedHolidays = [
        // 2024
        '2024-01-01' => 'New Year\'s Day',
        '2024-04-09' => 'Araw ng Kagitingan (Day of Valor)',
        '2024-05-01' => 'Labor Day',
        '2024-06-12' => 'Independence Day',
        '2024-08-26' => 'National Heroes Day',
        '2024-11-30' => 'Bonifacio Day',
        '2024-12-25' => 'Christmas Day',
        '2024-12-30' => 'Rizal Day',
        
        // 2025
        '2025-01-01' => 'New Year\'s Day',
        '2025-04-09' => 'Araw ng Kagitingan (Day of Valor)',
        '2025-05-01' => 'Labor Day',
        '2025-06-12' => 'Independence Day',
        '2025-08-25' => 'National Heroes Day',
        '2025-11-30' => 'Bonifacio Day',
        '2025-12-25' => 'Christmas Day',
        '2025-12-30' => 'Rizal Day',
        
        // 2026
        '2026-01-01' => 'New Year\'s Day',
        '2026-04-09' => 'Araw ng Kagitingan (Day of Valor)',
        '2026-05-01' => 'Labor Day',
        '2026-06-12' => 'Independence Day',
        '2026-08-31' => 'National Heroes Day',
        '2026-11-30' => 'Bonifacio Day',
        '2026-12-25' => 'Christmas Day',
        '2026-12-30' => 'Rizal Day',
    ];

    /**
     * Moveable holidays that need to be calculated
     */
    private static $moveableHolidays = [
        // 2024
        '2024-02-10' => 'Chinese New Year',
        '2024-03-28' => 'Maundy Thursday',
        '2024-03-29' => 'Good Friday',
        '2024-03-30' => 'Black Saturday',
        '2024-04-10' => 'Eid al-Fitr',
        '2024-06-17' => 'Eid al-Adha',
        '2024-11-01' => 'All Saints\' Day',
        
        // 2025
        '2025-01-29' => 'Chinese New Year',
        '2025-04-17' => 'Maundy Thursday',
        '2025-04-18' => 'Good Friday',
        '2025-04-19' => 'Black Saturday',
        '2025-03-31' => 'Eid al-Fitr',
        '2025-06-07' => 'Eid al-Adha',
        '2025-11-01' => 'All Saints\' Day',
        
        // 2026
        '2026-02-17' => 'Chinese New Year',
        '2026-04-02' => 'Maundy Thursday',
        '2026-04-03' => 'Good Friday',
        '2026-04-04' => 'Black Saturday',
        '2026-03-20' => 'Eid al-Fitr',
        '2026-05-27' => 'Eid al-Adha',
        '2026-11-01' => 'All Saints\' Day',
    ];

    /**
     * Check if a given date is a Philippine holiday
     */
    public static function isHoliday($date)
    {
        $dateString = Carbon::parse($date)->format('Y-m-d');
        
        return isset(self::$fixedHolidays[$dateString]) || 
               isset(self::$moveableHolidays[$dateString]);
    }

    /**
     * Get holiday name for a given date
     */
    public static function getHolidayName($date)
    {
        $dateString = Carbon::parse($date)->format('Y-m-d');
        
        return self::$fixedHolidays[$dateString] ?? 
               self::$moveableHolidays[$dateString] ?? 
               null;
    }

    /**
     * Get all holidays for a given year
     */
    public static function getHolidaysForYear($year)
    {
        $holidays = [];
        
        foreach (array_merge(self::$fixedHolidays, self::$moveableHolidays) as $date => $name) {
            if (Carbon::parse($date)->year == $year) {
                $holidays[$date] = $name;
            }
        }
        
        return $holidays;
    }

    /**
     * Check if a date is a weekend (Saturday or Sunday)
     */
    public static function isWeekend($date)
    {
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;
        return $dayOfWeek == Carbon::SATURDAY || $dayOfWeek == Carbon::SUNDAY;
    }

    /**
     * Check if a date is a working day (not weekend and not holiday)
     */
    public static function isWorkingDay($date)
    {
        return !self::isWeekend($date) && !self::isHoliday($date);
    }

    /**
     * Get next working day from a given date
     */
    public static function getNextWorkingDay($date)
    {
        $nextDay = Carbon::parse($date)->addDay();
        
        while (!self::isWorkingDay($nextDay)) {
            $nextDay->addDay();
        }
        
        return $nextDay;
    }

    /**
     * Get working days in a date range
     */
    public static function getWorkingDaysInRange($startDate, $endDate)
    {
        $workingDays = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        
        while ($current->lte($end)) {
            if (self::isWorkingDay($current)) {
                $workingDays[] = $current->copy();
            }
            $current->addDay();
        }
        
        return $workingDays;
    }
}
