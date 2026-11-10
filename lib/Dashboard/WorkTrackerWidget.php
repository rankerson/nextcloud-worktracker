<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

class WorkTrackerWidget implements IWidget {
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getId(): string {
        return 'worktracker';
    }

    public function getTitle(): string {
        return $this->l10n->t('Work Tracker');
    }

    public function getOrder(): int {
        return 35;
    }

    public function getIconClass(): string {
        return 'icon-worktracker';
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('worktracker.page.index');
    }

    public function load(): void {
        Util::addScript('worktracker', 'dashboard');
        Util::addStyle('worktracker', 'dashboard');
    }
}
