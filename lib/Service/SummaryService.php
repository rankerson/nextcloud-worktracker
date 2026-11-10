<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

use OCP\DB\IDBConnection;
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
        while ($row = $result->fetch()) {
            $users[] = $row['user_id'];
        }
        $result->closeCursor();
        foreach ($users as $uid) {
            $timezone = $this->config->getUserValue($uid, 'core', 'timezone', 'UTC');
            $date = (new \DateTimeImmutable('yesterday', new \DateTimeZone($timezone ?: 'UTC')))->format('Y-m-d');
            $this->rebuildDay($uid, $date, $timezone);
        }
    }

    public function rebuildDay(string $uid, string $date, string $timezone): void {
        $tz = new \DateTimeZone($timezone ?: 'UTC');
        $start = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
        $end = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
        $now = time();

        $work = $this->sumOverlap('worktracker_work', $uid, $start, $end, $now);
        $pauses = $this->fetchIntervals('worktracker_pauses', $uid, $start, $end, $now);
        $pauseExact = 0;
        $pauseRounded = 0;
        foreach ($pauses as $interval) {
            $s = max($start, (int)$interval['start_ts']);
            $e = min($end, (int)($interval['end_ts'] ?? $now));
            if ($e <= $s) continue;
            $duration = $e - $s;
            $pauseExact += $duration;
            // Round each pause interval independently. If a pause crosses midnight,
            // each local-day portion is treated as its own reportable pause segment.
            $pauseRounded += TimeMath::roundToQuarterHour($duration);
        }
        $netWork = max(0, $work - $pauseExact);
        $this->upsertDay($uid, $date, $netWork, $pauseExact, $pauseRounded);

        $projectRows = $this->fetchProjectIntervals($uid, $start, $end, $now);
        $totals = [];
        foreach ($projectRows as $row) {
            $s = max($start, (int)$row['start_ts']);
            $e = min($end, (int)($row['end_ts'] ?? $now));
            if ($e > $s) {
                $pid = (int)$row['project_id'];
                $totals[$pid] = ($totals[$pid] ?? 0) + ($e - $s);
            }
        }
        foreach ($totals as $pid => $seconds) {
            $this->upsertProjectDay($uid, (int)$pid, $date, $seconds);
        }
        $this->removeStaleProjectDays($uid, $date, array_keys($totals));
    }

    private function sumOverlap(string $table, string $uid, int $start, int $end, int $now): int {
        $sum = 0;
        foreach ($this->fetchIntervals($table, $uid, $start, $end, $now) as $row) {
            $s = max($start, (int)$row['start_ts']);
            $e = min($end, (int)($row['end_ts'] ?? $now));
            if ($e > $s) $sum += $e - $s;
        }
        return $sum;
    }

    private function fetchIntervals(string $table, string $uid, int $start, int $end, int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('start_ts', 'end_ts')->from($table)
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

    private function fetchProjectIntervals(string $uid, int $start, int $end, int $now): array {
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

    private function upsertDay(string $uid, string $date, int $work, int $pauseExact, int $pauseRounded): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('worktracker_day_summaries')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('work_date', $qb->createNamedParameter($date)));
        $existing = $qb->executeQuery()->fetch();
        $data = ['work_exact_seconds' => $work, 'work_rounded_seconds' => TimeMath::roundToQuarterHour($work),
            'pause_exact_seconds' => $pauseExact, 'pause_rounded_seconds' => $pauseRounded, 'updated_at' => time()];
        if ($existing) {
            $update = $this->db->getQueryBuilder();
            $update->update('worktracker_day_summaries');
            foreach ($data as $key => $value) $update->set($key, $update->createNamedParameter($value));
            $update->where($update->expr()->eq('id', $update->createNamedParameter((int)$existing['id'])))->executeStatement();
        } else {
            $insert = $this->db->getQueryBuilder();
            $insert->insert('worktracker_day_summaries');
            $insert->values(['user_id' => $insert->createNamedParameter($uid), 'work_date' => $insert->createNamedParameter($date)]);
            foreach ($data as $key => $value) $insert->setValue($key, $insert->createNamedParameter($value));
            $insert->executeStatement();
        }
    }

    private function upsertProjectDay(string $uid, int $pid, string $date, int $seconds): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('worktracker_project_days')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($pid)))
            ->andWhere($qb->expr()->eq('work_date', $qb->createNamedParameter($date)));
        $existing = $qb->executeQuery()->fetch();
        $data = ['exact_seconds' => $seconds, 'rounded_seconds' => TimeMath::roundToQuarterHour($seconds), 'updated_at' => time()];
        if ($existing) {
            $update = $this->db->getQueryBuilder();
            $update->update('worktracker_project_days');
            foreach ($data as $key => $value) $update->set($key, $update->createNamedParameter($value));
            $update->where($update->expr()->eq('id', $update->createNamedParameter((int)$existing['id'])))->executeStatement();
        } else {
            $insert = $this->db->getQueryBuilder();
            $insert->insert('worktracker_project_days');
            $insert->values(['user_id' => $insert->createNamedParameter($uid), 'project_id' => $insert->createNamedParameter($pid), 'work_date' => $insert->createNamedParameter($date)]);
            foreach ($data as $key => $value) $insert->setValue($key, $insert->createNamedParameter($value));
            $insert->executeStatement();
        }
    }

    private function removeStaleProjectDays(string $uid, string $date, array $keepIds): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('worktracker_project_days')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('work_date', $qb->createNamedParameter($date)));
        if ($keepIds !== []) {
            $qb->andWhere($qb->expr()->notIn('project_id', $qb->createNamedParameter($keepIds, \Doctrine\DBAL\ArrayParameterType::INTEGER)));
        }
        $qb->executeStatement();
    }
}
