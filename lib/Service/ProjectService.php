<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

use OCP\IDBConnection;
use OCP\IUserManager;

class ProjectService {
    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
    ) {
    }

    public function create(string $uid, array $data): int {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Bitte einen Projektnamen mit maximal 255 Zeichen angeben.');
        }
        $validFrom = $this->dateOrNull($data['valid_from'] ?? null);
        $validUntil = $this->dateOrNull($data['valid_until'] ?? null);
        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new \InvalidArgumentException('Das Gültig-bis-Datum liegt vor dem Gültig-ab-Datum.');
        }
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('worktracker_projects')->values([
            'owner_uid' => $qb->createNamedParameter($uid),
            'name' => $qb->createNamedParameter($name),
            'code' => $qb->createNamedParameter($this->nullableString($data['code'] ?? null, 64)),
            'wbs' => $qb->createNamedParameter($this->nullableString($data['wbs'] ?? null, 128)),
            'description' => $qb->createNamedParameter($this->nullableString($data['description'] ?? null, 4000)),
            'valid_from' => $qb->createNamedParameter($validFrom),
            'valid_until' => $qb->createNamedParameter($validUntil),
            'color' => $qb->createNamedParameter($this->nullableString($data['color'] ?? null, 16)),
            'sort_order' => $qb->createNamedParameter((int)($data['sort_order'] ?? 0)),
            'is_shared' => $qb->createNamedParameter(0),
            'created_at' => $qb->createNamedParameter($now),
            'updated_at' => $qb->createNamedParameter($now),
        ])->executeStatement();
        $id = (int)$this->db->lastInsertId('*PREFIX*worktracker_projects');
        $this->replaceShares($id, $uid, $data['shared_with'] ?? []);
        return $id;
    }

    public function update(string $uid, int $id, array $data): void {
        $this->assertOwner($uid, $id);
        $fields = [
            'name' => trim((string)($data['name'] ?? '')),
            'code' => $this->nullableString($data['code'] ?? null, 64),
            'wbs' => $this->nullableString($data['wbs'] ?? null, 128),
            'description' => $this->nullableString($data['description'] ?? null, 4000),
            'valid_from' => $this->dateOrNull($data['valid_from'] ?? null),
            'valid_until' => $this->dateOrNull($data['valid_until'] ?? null),
            'color' => $this->nullableString($data['color'] ?? null, 16),
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ];
        if ($fields['name'] === '' || mb_strlen($fields['name']) > 255) {
            throw new \InvalidArgumentException('Ungültiger Projektname.');
        }
        if ($fields['valid_from'] !== null && $fields['valid_until'] !== null && $fields['valid_until'] < $fields['valid_from']) {
            throw new \InvalidArgumentException('Das Gültig-bis-Datum liegt vor dem Gültig-ab-Datum.');
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('worktracker_projects');
        foreach ($fields as $key => $value) $qb->set($key, $qb->createNamedParameter($value));
        $qb->set('updated_at', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
            ->executeStatement();
        if (array_key_exists('shared_with', $data)) {
            $this->replaceShares($id, $uid, $data['shared_with']);
        }
    }

    public function archive(string $uid, int $id): void {
        $this->assertOwner($uid, $id);
        // Historical intervals keep their project reference. Archiving is represented
        // by ending validity today instead of deleting the project.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $qb = $this->db->getQueryBuilder();
        $qb->update('worktracker_projects')
            ->set('valid_until', $qb->createNamedParameter($today))
            ->set('updated_at', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
            ->executeStatement();
    }

    private function assertOwner(string $uid, int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('worktracker_projects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
            ->setMaxResults(1);
        if (!$qb->executeQuery()->fetch()) {
            throw new \DomainException('Projekt nicht gefunden oder keine Änderungsberechtigung.');
        }
    }

    private function replaceShares(int $projectId, string $ownerUid, mixed $users): void {
        if (!is_array($users)) {
            throw new \InvalidArgumentException('shared_with muss eine Liste von Benutzer-IDs sein.');
        }
        $delete = $this->db->getQueryBuilder();
        $delete->delete('worktracker_project_shares')
            ->where($delete->expr()->eq('project_id', $delete->createNamedParameter($projectId)))
            ->executeStatement();
        foreach (array_unique($users) as $userId) {
            $userId = trim((string)$userId);
            if ($userId === '' || $userId === $ownerUid) continue;
            if (!$this->userManager->userExists($userId)) {
                throw new \InvalidArgumentException('Unbekannter Nextcloud-Benutzer: ' . $userId);
            }
            $qb = $this->db->getQueryBuilder();
            $qb->insert('worktracker_project_shares')->values([
                'project_id' => $qb->createNamedParameter($projectId),
                'user_id' => $qb->createNamedParameter($userId),
                'created_at' => $qb->createNamedParameter(time()),
            ])->executeStatement();
        }
    }

    private function dateOrNull(mixed $value): ?string {
        if ($value === null || $value === '') return null;
        $value = (string)$value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Datumswerte müssen im Format JJJJ-MM-TT angegeben werden.');
        }
        return $value;
    }

    private function nullableString(mixed $value, int $maxLength): ?string {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Ein Projektfeld überschreitet die zulässige Länge.');
        }
        return $value;
    }
}
