<?php

namespace App\Support;

use Carbon\Carbon;

class BusinessDate
{
    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * Get today's calendar date in India timezone as Y-m-d string.
     */
    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    /**
     * Get current timestamp in India timezone.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Get the next calendar day string (Y-m-d) after the given date (default today).
     */
    public static function nextDay(?string $date = null): string
    {
        $base = $date ? Carbon::parse($date, self::TIMEZONE) : self::now();
        return $base->copy()->addDay()->toDateString();
    }

    /**
     * Get start and end UTC Carbon instances representing a full Indian calendar day.
     * Useful for querying created_at / updated_at timestamps stored in UTC.
     *
     * @param string|null $date Date string in Y-m-d format (defaults to India today)
     * @return array [Carbon $startUtc, Carbon $endUtc]
     */
    public static function dayRangeUtc(?string $date = null): array
    {
        $targetDate = $date ?? self::today();
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$targetDate} 00:00:00", self::TIMEZONE)->utc();
        $end   = Carbon::createFromFormat('Y-m-d H:i:s', "{$targetDate} 23:59:59", self::TIMEZONE)->utc();

        return [$start, $end];
    }

    /**
     * Parse date string in India timezone.
     */
    public static function parse(string $date): Carbon
    {
        return Carbon::parse($date, self::TIMEZONE);
    }
}
