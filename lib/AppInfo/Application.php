<?php
declare(strict_types=1);

namespace OCA\WorkTracker\AppInfo;

use OCA\WorkTracker\BackgroundJob\DailySummaryJob;
use OCA\WorkTracker\Dashboard\WorkTrackerWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
    public const APP_ID = 'worktracker';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(WorkTrackerWidget::class);
    }

    public function boot(IBootContext $context): void {
        $context->getAppContainer()->get(IJobList::class)->add(DailySummaryJob::class);
    }
}
