<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

use OCP\DB\IDBConnection;

class EntryService {
    private const TABLES = [
        'work' => 'worktracker_work',
        'pause' => 'worktracker_pauses',
        'project' => 'worktracker_project_times',
    ];

    public function __construct(
        private IDBConnection $db,
        private TimeTrackingService $timeService,
    ) {
    }

    public function list(string $uid, int $from, int $to): array {
        if ($to <= $from) throw new \InvalidArgumentException('Ungültiger Zeitraum.');
        $entries = [];
        foreach (self::TABLES as $type => $table) {
            $qb = $this->db->getQueryBuilder();
            $columns = ['id', 'start_ts', 'end_ts'];
            if ($type === 'project') $columns[] = 'project_id';
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
        $start = filter_var($data['start_ts'] ?? null, FILTER_VALIDATE_INT);
        $end = filter_var($data['end_ts'] ?? null, FILTER_VALIDATE_INT);
        if ($start === false || $end === false || $end <= $start) {
            throw new \InvalidArgumentException('Beginn und Ende müssen gültige Zeitstempel sein; das Ende muss nach dem Beginn liegen.');
        }
        $values = [
            'user_id' => $uid,
            'start_ts' => $start,
            'end_ts' => $end,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        if ($type === 'project') {
            $projectId = (int)($data['project_id'] ?? 0);
            $allowed = array_column($this->timeService->listProjects($uid, true), 'id');
            if (!in_array($projectId, array_map('intval', $allowed), true)) {
                throw new \DomainException('Projekt nicht gefunden oder nicht freigegeben.');
            }
            $values['project_id'] = $projectId;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        foreach ($values as $key => $value) $qb->setValue($key, $qb->createNamedParameter($value));
        $qb->executeStatement();
        return (int)$this->db->lastInsertId('*PREFIX*' . $table);
    }

    public function update(string $uid, string $type, int $id, array $data): void {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new \InvalidArgumentException('Unbekannter Buchungstyp.');
        $start = filter_var($data['start_ts'] ?? null, FILTER_VALIDATE_INT);
        $end = filter_var($data['end_ts'] ?? null, FILTER_VALIDATE_INT);
        if ($start === false || $end === false || $end <= $start) {
            throw new \InvalidArgumentException('Ungültiger Beginn oder Ende.');
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('start_ts', $qb->createNamedParameter($start))
            ->set('end_ts', $qb->createNamedParameter($end))
            ->set('updated_at', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $affected = $qb->executeStatement();
        if ($affected === 0) throw new \DomainException('Zeitbuchung nicht gefunden.');
        $this->rebuildAffectedDays($uid, $start, $end);
    }

    public function delete(string $uid, string $type, int $id): void {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new \InvalidArgumentException('Unbekannter Buchungstyp.');
        $qb = $this->db->getQueryBuilder();
        $qb->delete($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->executeStatement();
    }

    private function projectName(int $id): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')->from('worktracker_projects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ? (string)$row['name'] : '(gelöschtes Projekt)';
    }

    private function rebuildAffectedDays(string $uid, int $start, int $end): void {
        // Summaries are rebuilt lazily by the next report request and by the nightly job.
    }
}
