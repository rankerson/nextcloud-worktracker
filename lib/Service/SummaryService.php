<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

use OCP\IDBConnection;
use OCP\IConfig;

class SummaryService {
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    public function rebuildPreviousDayForAllUsers(): void {
        $query = $this->db->getQueryBuilder();
        $query->selectDistinct('user_id')->from('worktracker_work');
        $result = $query->executeQuery();
        $users = [];
        while ($row = $result->fetch()) $users[] = (string)$row['user_id'];
        $result->closeCursor();

        foreach ($users as $uid) {
            $timezone = $this->config->getUserValue($uid, 'core', 'timezone', 'UTC') ?: 'UTC';
            $date = (new \DateTimeImmutable('yesterday', new \DateTimeZone($timezone)))->format('Y-m-d');
            $this->rebuildDay($uid, $date, $timezone);
        }
    }

    public function rebuildDay(string $uid, string $date, string $timezone): void {
        $tz = new \DateTimeZone($timezone ?: 'UTC');
        $start = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
        $end = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
        $now = time();

        $grossWork = $this->sumOverlap('worktracker_work', $uid, $start, $end, $now);
        $pauseExact = 0;
        $pauseRounded = 0;
        foreach ($this->fetchIntervals('worktracker_pauses', $uid, $start, $end) as $pause) {
            $pauseStart = (int)$pause['start_ts'];
            $pauseEnd = (int)($pause['end_ts'] ?? $now);
            $overlapStart = max($start, $pauseStart);
            $overlapEnd = min($end, $pauseEnd);
            if ($overlapEnd <= $overlapStart) continue;

            $pauseExact += $overlapEnd - $overlapStart;
            // A pause is rounded once as an individual interval, and its rounded
            // value belongs to the calendar day on which that pause began.
            $rounded = TimeMath::roundToQuarterHour(max(0, $pauseEnd - $pauseStart));
            $this->storePauseRounding((int)$pause['id'], $rounded);
            if ($pauseStart >= $start && $pauseStart < $end) $pauseRounded += $rounded;
        }

        $netWork = max(0, $grossWork - $pauseExact);
        $this->upsertDay($uid, $date, $netWork, $pauseExact, $pauseRounded);

        // Rebuild project-day aggregates from exact intervals. Rounding happens
        // only after all intervals for a project and local calendar day are summed.
        $this->deleteProjectDays($uid, $date);
        $totals = [];
        foreach ($this->fetchProjectIntervals($uid, $start, $end) as $row) {
            $s = max($start, (int)$row['start_ts']);
            $e = min($end, (int)($row['end_ts'] ?? $now));
            if ($e > $s) {
                $pid = (int)$row['project_id'];
                $totals[$pid] = ($totals[$pid] ?? 0) + ($e - $s);
            }
        }
        foreach ($totals as $pid => $seconds) {
            $this->insertProjectDay($uid, (int)$pid, $date, $seconds);
        }
    }

    private function fetchIntervals(string $table, string $uid, int $start, int $end): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'start_ts', 'end_ts')->from($table)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('end_ts'),
                $qb->expr()->gt('end_ts', $qb->createNamedParameter($start))
            ));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function fetchProjectIntervals(string $uid, int $start, int $end): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('project_id', 'start_ts', 'end_ts')->from('worktracker_project_times')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gt('end_ts', $qb->createNamedParameter($start))));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function sumOverlap(string $table, string $uid, int $start, int $end, int $now): int {
        $sum = 0;
        foreach ($this->fetchIntervals($table, $uid, $start, $end) as $row) {
            $s = max($start, (int)$row['start_ts']);
            $e = min($end, (int)($row['end_ts'] ?? $now));
            if ($e > $s) $sum += $e - $s;
        }
        return $sum;
    }

    private function storePauseRounding(int $id, int $rounded): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('worktracker_pauses')
            ->set('rounded_seconds', $qb->createNamedParameter($rounded))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->executeStatement();
    }

    private function upsertDay(string $uid, string $date, int $work, int $pauseExact, int $pauseRounded): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('worktracker_day_summaries')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('work_date', $qb->createNamedParameter($date)));
        $result = $qb->executeQuery();
        $existing = $result->fetch();
        $result->closeCursor();
        $data = [
            'work_exact_seconds' => $work,
            'work_rounded_seconds' => TimeMath::roundToQuarterHour($work),
            'pause_exact_seconds' => $pauseExact,
            'pause_rounded_seconds' => $pauseRounded,
            'updated_at' => time(),
        ];
        if ($existing) {
            $update = $this->db->getQueryBuilder();
            $update->update('worktracker_day_summaries');
            foreach ($data as $key => $value) $update->set($key, $update->createNamedParameter($value));
            $update->where($update->expr()->eq('id', $update->createNamedParameter((int)$existing['id'])))->executeStatement();
        } else {
            $insert = $this->db->getQueryBuilder();
            $insert->insert('worktracker_day_summaries')
                ->values([
                    'user_id' => $insert->createNamedParameter($uid),
                    'work_date' => $insert->createNamedParameter($date),
                ]);
            foreach ($data as $key => $value) $insert->setValue($key, $insert->createNamedParameter($value));
            $insert->executeStatement();
        }
    }

    private function deleteProjectDays(string $uid, string $date): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('worktracker_project_days')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('work_date', $qb->createNamedParameter($date)))
            ->executeStatement();
    }

    private function insertProjectDay(string $uid, int $projectId, string $date, int $seconds): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('worktracker_project_days')->values([
            'user_id' => $qb->createNamedParameter($uid),
            'project_id' => $qb->createNamedParameter($projectId),
            'work_date' => $qb->createNamedParameter($date),
            'exact_seconds' => $qb->createNamedParameter($seconds),
            'rounded_seconds' => $qb->createNamedParameter(TimeMath::roundToQuarterHour($seconds)),
            'updated_at' => $qb->createNamedParameter(time()),
        ])->executeStatement();
    }
}
