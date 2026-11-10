<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

final class TimeMath {
    public static function roundToQuarterHour(int $seconds): int {
        if ($seconds <= 0) {
            return 0;
        }
        return intdiv($seconds + 450, 900) * 900;
    }

    public static function duration(?int $start, ?int $end, int $now): int {
        if ($start === null) {
            return 0;
        }
        return max(0, ($end ?? $now) - $start);
    }

    /** Split an interval into local calendar-day portions. */
    public static function splitByLocalDay(int $start, int $end, string $timezone): array {
        $tz = new \DateTimeZone($timezone ?: 'UTC');
        $cursor = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
        $finish = (new \DateTimeImmutable('@' . $end))->setTimezone($tz);
        $parts = [];
        while ($cursor < $finish) {
            $nextDay = $cursor->setTime(0, 0)->modify('+1 day');
            $partEnd = $nextDay < $finish ? $nextDay : $finish;
            $date = $cursor->format('Y-m-d');
            $parts[$date] = ($parts[$date] ?? 0) + max(0, $partEnd->getTimestamp() - $cursor->getTimestamp());
            $cursor = $partEnd;
        }
        return $parts;
    }
}
