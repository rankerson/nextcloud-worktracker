<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Controller;

use OCA\WorkTracker\Service\EntryService;
use OCA\WorkTracker\Service\ExportService;
use OCA\WorkTracker\Service\ProjectService;
use OCA\WorkTracker\Service\TimeTrackingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private TimeTrackingService $timeService,
        private ProjectService $projectService,
        private EntryService $entryService,
        private ExportService $exportService,
    ) {
        parent::__construct($appName, $request);
    }

    /** @NoAdminRequired */
    public function status(): JSONResponse {
        return new JSONResponse($this->timeService->getStatus($this->uid()));
    }

    /** @NoAdminRequired */
    public function startWork(): JSONResponse {
        return $this->run(fn() => $this->timeService->startWork($this->uid()));
    }

    /** @NoAdminRequired */
    public function stopWork(): JSONResponse {
        return $this->run(fn() => $this->timeService->stopWork($this->uid()));
    }

    /** @NoAdminRequired */
    public function startPause(): JSONResponse {
        return $this->run(fn() => $this->timeService->startPause($this->uid()));
    }

    /** @NoAdminRequired */
    public function stopPause(): JSONResponse {
        return $this->run(fn() => $this->timeService->stopPause($this->uid()));
    }

    /** @NoAdminRequired */
    public function startProject(int $id): JSONResponse {
        return $this->run(fn() => $this->timeService->startProject($this->uid(), $id));
    }

    /** @NoAdminRequired */
    public function stopProject(): JSONResponse {
        return $this->run(fn() => $this->timeService->stopProject($this->uid()));
    }

    /** @NoAdminRequired */
    public function listProjects(): JSONResponse {
        return new JSONResponse($this->timeService->listProjects($this->uid(), true));
    }

    /** @NoAdminRequired */
    public function createProject(): JSONResponse {
        try {
            $id = $this->projectService->create($this->uid(), $this->request->getParams());
            return new JSONResponse(['success' => true, 'id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function updateProject(int $id): JSONResponse {
        try {
            $this->projectService->update($this->uid(), $id, $this->request->getParams());
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function deleteProject(int $id): JSONResponse {
        try {
            $this->projectService->archive($this->uid(), $id);
            return new JSONResponse(['success' => true]);
        } catch (\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function entries(): JSONResponse {
        try {
            [$from, $to] = $this->range();
            return new JSONResponse($this->entryService->list($this->uid(), $from, $to));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function createEntry(): JSONResponse {
        try {
            $id = $this->entryService->create($this->uid(), $this->request->getParams());
            return new JSONResponse(['success' => true, 'id' => $id], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function updateEntry(string $type, int $id): JSONResponse {
        try {
            $this->entryService->update($this->uid(), $type, $id, $this->request->getParams());
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function deleteEntry(string $type, int $id): JSONResponse {
        try {
            $this->entryService->delete($this->uid(), $type, $id);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /** @NoAdminRequired */
    public function exportCsv(): DataResponse {
        [$from, $to] = $this->range();
        $csv = $this->exportService->csv($this->exportService->rows($this->uid(), $from, $to));
        return new DataResponse($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="worktracker.csv"',
        ]);
    }

    /** @NoAdminRequired */
    public function exportXlsx(): DataResponse {
        [$from, $to] = $this->range();
        $xlsx = $this->exportService->xlsx($this->exportService->rows($this->uid(), $from, $to));
        return new DataResponse($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="worktracker.xlsx"',
        ]);
    }

    private function run(callable $action): JSONResponse {
        try {
            $action();
            return new JSONResponse(['success' => true, 'status' => $this->timeService->getStatus($this->uid())]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Interner Fehler bei der Zeiterfassung.'], 500);
        }
    }

    private function range(): array {
        $from = (int)$this->request->getParam('from', strtotime('first day of this month 00:00:00'));
        $to = (int)$this->request->getParam('to', time() + 1);
        if ($from <= 0 || $to <= $from) throw new \InvalidArgumentException('Ungültiger Auswertungszeitraum.');
        return [$from, $to];
    }

    private function uid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) throw new \RuntimeException('Authentication required');
        return $user->getUID();
    }
}
