<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

use OCP\IDBConnection;
use OCP\IConfig;

class EntryService {
    private const TABLES = [
        'work' => 'worktracker_work',
        'pause' => 'worktracker_pauses',
        'project' => 'worktracker_project_times',
    ];

    public function __construct(
        private IDBConnection $db,
        private TimeTrackingService $timeService,
        private SummaryService $summaryService,
        private IConfig $config,
    ) {
    }

    public function list(string $uid, int $from, int $to): array {
        if ($to <= $from) throw new \InvalidArgumentException('Ungültiger Zeitraum.');
        $entries = [];
        foreach (self::TABLES as $type => $table) {
            $columns = ['id', 'start_ts', 'end_ts'];
            if ($type === 'project') $columns[] = 'project_id';
            if ($type === 'pause') $columns[] = 'rounded_seconds';
            $qb = $this->db->getQueryBuilder();
            $qb->select(...$columns)->from($table)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($to)))
                ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gt('end_ts', $qb->createNamedParameter($from))))
                ->orderBy('start_ts', 'ASC');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $item = [
                    'id' => (int)$row['id'],
                    'type' => $type,
                    'start_ts' => (int)$row['start_ts'],
                    'end_ts' => $row['end_ts'] === null ? null : (int)$row['end_ts'],
                ];
                if ($type === 'project') {
                    $item['project_id'] = (int)$row['project_id'];
                    $item['project_name'] = $this->projectName((int)$row['project_id']);
                }
                if ($type === 'pause') $item['rounded_seconds'] = (int)$row['rounded_seconds'];
                $entries[] = $item;
            }
            $result->closeCursor();
        }
        usort($entries, static fn(array $a, array $b): int => $a['start_ts'] <=> $b['start_ts']);
        return $entries;
    }

    public function create(string $uid, array $data): int {
        $type = (string)($data['type'] ?? '');
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new \InvalidArgumentException('Unbekannter Buchungstyp.');
        [$start, $end] = $this->validateRange($data);
        $this->validateNoOverlap($uid, $type, $start, $end);

        $values = [
            'user_id' => $uid,
            'start_ts' => $start,
            'end_ts' => $end,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        if ($type === 'pause') $values['rounded_seconds'] = TimeMath::roundToQuarterHour($end - $start);
        if ($type === 'project') $values['project_id'] = $this->validateProject($uid, (int)($data['project_id'] ?? 0));

        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        foreach ($values as $key => $value) $qb->setValue($key, $qb->createNamedParameter($value));
        $qb->executeStatement();
        $id = (int)$this->db->lastInsertId('*PREFIX*' . $table);
        $this->rebuildAffectedDays($uid, $start, $end);
        return $id;
    }

    public function update(string $uid, string $type, int $id, array $data): void {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new \InvalidArgumentException('Unbekannter Buchungstyp.');
        $old = $this->findOwned($table, $uid, $id);
        if (!$old) throw new \DomainException('Zeitbuchung nicht gefunden.');
        [$start, $end] = $this->validateRange($data);
        $this->validateNoOverlap($uid, $type, $start, $end, $id);
        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('start_ts', $qb->createNamedParameter($start))
            ->set('end_ts', $qb->createNamedParameter($end))
            ->set('updated_at', $qb->createNamedParameter(time()));
        if ($type === 'pause') {
            $qb->set('rounded_seconds', $qb->createNamedParameter(TimeMath::roundToQuarterHour($end - $start)));
        }
        if ($type === 'project') {
            $projectId = $this->validateProject($uid, (int)($data['project_id'] ?? $old['project_id']));
            $qb->set('project_id', $qb->createNamedParameter($projectId));
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->executeStatement();
        $this->rebuildAffectedDays($uid, (int)$old['start_ts'], (int)($old['end_ts'] ?? $old['start_ts']));
        $this->rebuildAffectedDays($uid, $start, $end);
    }

    public function delete(string $uid, string $type, int $id): void {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new \InvalidArgumentException('Unbekannter Buchungstyp.');
        $old = $this->findOwned($table, $uid, $id);
        if (!$old) throw new \DomainException('Zeitbuchung nicht gefunden.');
        $qb = $this->db->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->executeStatement();
        $this->rebuildAffectedDays($uid, (int)$old['start_ts'], (int)($old['end_ts'] ?? $old['start_ts']));
    }

    private function validateRange(array $data): array {
        $start = filter_var($data['start_ts'] ?? null, FILTER_VALIDATE_INT);
        $end = filter_var($data['end_ts'] ?? null, FILTER_VALIDATE_INT);
        if ($start === false || $end === false || $end <= $start) {
            throw new \InvalidArgumentException('Beginn und Ende müssen gültige Zeitstempel sein; das Ende muss nach dem Beginn liegen.');
        }
        return [(int)$start, (int)$end];
    }

    private function validateNoOverlap(string $uid, string $type, int $start, int $end, ?int $excludeId = null): void {
        if ($type === 'pause' || $type === 'project') {
            if (!$this->isWithinWork($uid, $start, $end)) {
                throw new \DomainException('Pausen und Projektzeiten müssen vollständig innerhalb einer Arbeitszeit liegen.');
            }
        }
        $table = self::TABLES[$type];
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lt('start_ts', $qb->createNamedParameter($end)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gt('end_ts', $qb->createNamedParameter($start))));
        if ($excludeId !== null) $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
        if ($type === 'work' || $type === 'pause' || $type === 'project') {
            $qb->setMaxResults(1);
            if ($qb->executeQuery()->fetch()) {
                throw new \DomainException('Die Zeitbuchung überschneidet sich mit einer bestehenden Buchung desselben Typs.');
            }
        }
    }

    private function isWithinWork(string $uid, int $start, int $end): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('worktracker_work')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->lte('start_ts', $qb->createNamedParameter($start)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNull('end_ts'), $qb->expr()->gte('end_ts', $qb->createNamedParameter($end))))
            ->setMaxResults(1);
        return (bool)$qb->executeQuery()->fetch();
    }

    private function validateProject(string $uid, int $projectId): int {
        $allowed = array_map('intval', array_column($this->timeService->listProjects($uid, true), 'id'));
        if ($projectId <= 0 || !in_array($projectId, $allowed, true)) {
            throw new \DomainException('Projekt nicht gefunden oder nicht freigegeben.');
        }
        return $projectId;
    }

    private function findOwned(string $table, string $uid, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ?: null;
    }

    private function projectName(int $id): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')->from('worktracker_projects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ? (string)$row['name'] : '(gelöschtes Projekt)';
    }

    private function rebuildAffectedDays(string $uid, int $start, int $end): void {
        $timezone = $this->config->getUserValue($uid, 'core', 'timezone', 'UTC') ?: 'UTC';
        $tz = new \DateTimeZone($timezone);
        $first = (new \DateTimeImmutable('@' . $start))->setTimezone($tz)->setTime(0, 0);
        $last = (new \DateTimeImmutable('@' . max($start, $end - 1)))->setTimezone($tz)->setTime(0, 0);
        $days = 0;
        for ($day = $first; $day <= $last && $days < 370; $day = $day->modify('+1 day'), $days++) {
            $this->summaryService->rebuildDay($uid, $day->format('Y-m-d'), $timezone);
        }
    }
}
