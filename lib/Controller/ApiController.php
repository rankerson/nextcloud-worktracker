<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Controller;

use OCA\WorkTracker\Service\TimeTrackingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private TimeTrackingService $timeService,
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
        return new JSONResponse($this->timeService->listProjects($this->uid()));
    }

    private function run(callable $action): JSONResponse {
        try {
            $action();
            return new JSONResponse(['success' => true, 'status' => $this->timeService->getStatus($this->uid())]);
        } catch (\DomainException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Interner Fehler bei der Zeiterfassung.'], 500);
        }
    }

    private function uid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }
        return $user->getUID();
    }
}
