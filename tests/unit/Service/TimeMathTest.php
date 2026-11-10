<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Tests\Unit\Service;

use OCA\WorkTracker\Service\TimeMath;
use PHPUnit\Framework\TestCase;

class TimeMathTest extends TestCase {
    public function testQuarterHourRounding(): void {
        $this->assertSame(0, TimeMath::roundToQuarterHour(449));
        $this->assertSame(900, TimeMath::roundToQuarterHour(450));
        $this->assertSame(900, TimeMath::roundToQuarterHour(899));
        $this->assertSame(900, TimeMath::roundToQuarterHour(900));
        $this->assertSame(1800, TimeMath::roundToQuarterHour(1350));
    }

    public function testIntervalIsSplitAtLocalMidnight(): void {
        $start = (new \DateTimeImmutable('2026-11-09 23:30:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $end = (new \DateTimeImmutable('2026-11-10 01:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp();
        $parts = TimeMath::splitByLocalDay($start, $end, 'Europe/Berlin');
        $this->assertSame(1800, $parts['2026-11-09']);
        $this->assertSame(3600, $parts['2026-11-10']);
    }
}
