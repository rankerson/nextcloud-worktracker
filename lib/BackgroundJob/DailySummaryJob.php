<?php
declare(strict_types=1);

namespace OCA\WorkTracker\BackgroundJob;

use OCA\WorkTracker\Service\SummaryService;
use OCP\BackgroundJob\TimedJob;

class DailySummaryJob extends TimedJob {
    public function __construct(private SummaryService $summaryService) {
        parent::__construct();
        $this->setInterval(900);
    }

    protected function run($argument): void {
        $this->summaryService->rebuildPreviousDayForAllUsers();
    }
}
