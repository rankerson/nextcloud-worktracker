<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

use OCP\IDBConnection;
use OCP\IConfig;

class TimeTrackingService {
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private SummaryService $summaryService,
    ) {
    }

    public function startWork(string $uid): void {
        if ($this->getOpen('worktracker_work', $uid)) {
            throw new \DomainException('Arbeitszeit läuft bereits.');
        }
        $this->insertInterval('worktracker_work', $uid);
    }

    public function stopWork(string $uid): void {
        $this->closeOpen('worktracker_project_times', $uid);
        $this->closeOpen('worktracker_pauses', $uid);
        $this->closeOpen('worktracker_work', $uid);
        $this->rebuildToday($uid);
    }

    public function startPause(string $uid): void {
        if (!$this->getOpen('worktracker_work', $uid)) {
            throw new \DomainException('Eine Pause kann nur während der Arbeitszeit gestartet werden.');
        }
        if ($this->getOpen('worktracker_pauses', $uid)) {
            throw new \DomainException('Eine Pause läuft bereits.');
        }
        $this->closeOpen('worktracker_project_times', $uid);
        $this->insertInterval('worktracker_pauses', $uid);
        $this->rebuildToday($uid);
    }

    public function stopPause(string $uid): void {
        if (!$this->getOpen('worktracker_pauses', $uid)) {
            throw new \DomainException('Es läuft derzeit keine Pause.');
        }
        $this->closeOpen('worktracker_pauses', $uid);
        $this->rebuildToday($uid);
    }

    public function startProject(string $uid, int $projectId): void {
        if (!$this->getOpen('worktracker_work', $uid)) {
            throw new \DomainException('Projektzeit kann nur während der Arbeitszeit gestartet werden.');
        }
        $project = $this->getAccessibleProject($uid, $projectId);
        if (!$project) {
            throw new \DomainException('Projekt nicht gefunden oder nicht freigegeben.');
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone($uid))))->format('Y-m-d');
        if (($project['valid_from'] !== null && $project['valid_from'] > $today)
            || ($project['valid_until'] !== null && $project['valid_until'] < $today)) {
            throw new \DomainException('Das Projekt ist heute nicht gültig.');
        }

        // Starting a project resumes work from a pause and switches any previous project.
        $this->closeOpen('worktracker_pauses', $uid);
        $this->closeOpen('worktracker_project_times', $uid);
        $qb = $this->db->getQueryBuilder();
        $qb->insert('worktracker_project_times')
            ->values([
                'user_id' => $qb->createNamedParameter($uid),
                'project_id' => $qb->createNamedParameter($projectId),
                'start_ts' => $qb->createNamedParameter(time()),
                'end_ts' => $qb->createNamedParameter(null),
                'created_at' => $qb->createNamedParameter(time()),
                'updated_at' => $qb->createNamedParameter(time()),
            ])->executeStatement();
        $this->rebuildToday($uid);
    }

    public function stopProject(string $uid): void {
        if (!$this->getOpen('worktracker_project_times', $uid)) {
            throw new \DomainException('Es läuft derzeit kein Projekt.');
        }
        $this->closeOpen('worktracker_project_times', $uid);
        $this->rebuildToday($uid);
    }

    public function getStatus(string $uid): array {
        $now = time();
        $work = $this->getOpen('worktracker_work', $uid);
        $pause = $this->getOpen('worktracker_pauses', $uid);
        $project = $this->getOpen('worktracker_project_times', $uid);
        $todayStart = (new \DateTimeImmutable('today', new \DateTimeZone($this->timezone($uid))))->getTimestamp();
        $gross = $this->sumToday('worktracker_work', $uid, $todayStart, $now);
        $pauseSeconds = $this->sumToday('worktracker_pauses', $uid, $todayStart, $now);
        $workSeconds = max(0, $gross - $pauseSeconds);
        $pauseRounded = $this->sumRoundedPausesToday($uid, $todayStart, $now);
        [$projectSeconds, $projectRounded] = $this->projectTotalsToday($uid, $todayStart, $now);
        return [
            'work_running' => $work !== null,
            'pause_running' => $pause !== null,
            'active_project_id' => $project ? (int)$project['project_id'] : null,
            'work_elapsed_seconds' => $workSeconds,
            'work_rounded_seconds' => TimeMath::roundToQuarterHour($workSeconds),
            'pause_elapsed_seconds' => $pause ? max(0, $now - (int)$pause['start_ts']) : 0,
            'pause_rounded_seconds' => $pauseRounded,
            'active_project_elapsed_seconds' => $project ? max(0, $now - (int)$project['start_ts']) : 0,
            'project_seconds' => $projectSeconds,
            'project_rounded_seconds' => $projectRounded,
            'server_time' => $now,
            'timezone' => $this->timezone($uid),
        ];
    }

    private function sumRoundedPausesToday(string $uid, int $start, int $end): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('start_ts', 'end_ts')->from('worktracker_pauses')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gt('end_ts', $qb->createNamedParameter($start))));
        $result = $qb->executeQuery();
        $total = 0;
        while ($row = $result->fetch()) {
            $pauseStart = (int)$row['start_ts'];
            $pauseEnd = (int)($row['end_ts'] ?? $end);
            if ($pauseStart >= $start && $pauseStart < $end) {
                $total += TimeMath::roundToQuarterHour(max(0, $pauseEnd - $pauseStart));
            }
        }
        $result->closeCursor();
        return $total;
    }

    private function projectTotalsToday(string $uid, int $start, int $end): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('project_id', 'start_ts', 'end_ts')->from('worktracker_project_times')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gt('end_ts', $qb->createNamedParameter($start))));
        $result = $qb->executeQuery();
        $totals = [];
        while ($row = $result->fetch()) {
            $s = max($start, (int)$row['start_ts']);
            $e = min($end, (int)($row['end_ts'] ?? $end));
            if ($e > $s) {
                $id = (string)$row['project_id'];
                $totals[$id] = ($totals[$id] ?? 0) + ($e - $s);
            }
        }
        $result->closeCursor();
        $rounded = [];
        foreach ($totals as $id => $seconds) $rounded[$id] = TimeMath::roundToQuarterHour($seconds);
        return [$totals, $rounded];
    }

    public function listProjects(string $uid, bool $includeExpired = false): array {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone($uid))))->format('Y-m-d');
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.id', 'p.owner_uid', 'p.name', 'p.code', 'p.wbs', 'p.description',
            'p.valid_from', 'p.valid_until', 'p.color', 'p.sort_order', 'p.is_shared')
            ->from('worktracker_projects', 'p')
            ->leftJoin('p', 'worktracker_project_shares', 's', $qb->expr()->eq('p.id', 's.project_id'))
            ->where($qb->expr()->orX(
                $qb->expr()->eq('p.owner_uid', $qb->createNamedParameter($uid)),
                $qb->expr()->eq('s.user_id', $qb->createNamedParameter($uid))
            ));
        if (!$includeExpired) {
            $qb->andWhere($qb->expr()->orX($qb->expr()->isNull('p.valid_from'), $qb->expr()->lte('p.valid_from', $qb->createNamedParameter($today))))
                ->andWhere($qb->expr()->orX($qb->expr()->isNull('p.valid_until'), $qb->expr()->gte('p.valid_until', $qb->createNamedParameter($today))));
        }
        $qb->orderBy('p.sort_order', 'ASC')->addOrderBy('p.name', 'ASC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function getAccessibleProject(string $uid, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.*')->from('worktracker_projects', 'p')
            ->leftJoin('p', 'worktracker_project_shares', 's', $qb->expr()->eq('p.id', 's.project_id'))
            ->where($qb->expr()->eq('p.id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('p.owner_uid', $qb->createNamedParameter($uid)),
                $qb->expr()->eq('s.user_id', $qb->createNamedParameter($uid))
            ))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    private function getOpen(string $table, string $uid): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->isNull('end_ts'))
            ->orderBy('start_ts', 'DESC')->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    private function insertInterval(string $table, string $uid): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert($table)->values([
            'user_id' => $qb->createNamedParameter($uid),
            'start_ts' => $qb->createNamedParameter($now),
            'end_ts' => $qb->createNamedParameter(null),
            'created_at' => $qb->createNamedParameter($now),
            'updated_at' => $qb->createNamedParameter($now),
        ])->executeStatement();
    }

    private function closeOpen(string $table, string $uid): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('end_ts', $qb->createNamedParameter($now))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->isNull('end_ts'))
            ->executeStatement();
    }

    private function sumToday(string $table, string $uid, int $start, int $end): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('start_ts', 'end_ts')->from($table)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gt('end_ts', $qb->createNamedParameter($start))));
        $result = $qb->executeQuery();
        $total = 0;
        while ($row = $result->fetch()) {
            $s = max($start, (int)$row['start_ts']);
            $e = min($end, (int)($row['end_ts'] ?? $end));
            if ($e > $s) $total += $e - $s;
        }
        $result->closeCursor();
        return $total;
    }

    private function rebuildToday(string $uid): void {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone($uid))))->format('Y-m-d');
        $this->summaryService->rebuildDay($uid, $date, $this->timezone($uid));
    }

    private function timezone(string $uid): string {
        return $this->config->getUserValue($uid, 'core', 'timezone', 'UTC') ?: 'UTC';
    }
}
