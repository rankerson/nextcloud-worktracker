<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    public function index(): TemplateResponse {
        return new TemplateResponse('worktracker', 'main');
    }
}
