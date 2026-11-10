<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET'],
        ['name' => 'api#startWork', 'url' => '/api/work/start', 'verb' => 'POST'],
        ['name' => 'api#stopWork', 'url' => '/api/work/stop', 'verb' => 'POST'],
        ['name' => 'api#startPause', 'url' => '/api/pause/start', 'verb' => 'POST'],
        ['name' => 'api#stopPause', 'url' => '/api/pause/stop', 'verb' => 'POST'],
        ['name' => 'api#startProject', 'url' => '/api/projects/{id}/start', 'verb' => 'POST'],
        ['name' => 'api#stopProject', 'url' => '/api/projects/stop', 'verb' => 'POST'],
        ['name' => 'api#listProjects', 'url' => '/api/projects', 'verb' => 'GET'],
        ['name' => 'api#createProject', 'url' => '/api/projects', 'verb' => 'POST'],
        ['name' => 'api#updateProject', 'url' => '/api/projects/{id}', 'verb' => 'PUT'],
        ['name' => 'api#deleteProject', 'url' => '/api/projects/{id}', 'verb' => 'DELETE'],
        ['name' => 'api#entries', 'url' => '/api/entries', 'verb' => 'GET'],
        ['name' => 'api#createEntry', 'url' => '/api/entries', 'verb' => 'POST'],
        ['name' => 'api#updateEntry', 'url' => '/api/entries/{type}/{id}', 'verb' => 'PUT'],
        ['name' => 'api#deleteEntry', 'url' => '/api/entries/{type}/{id}', 'verb' => 'DELETE'],
        ['name' => 'api#exportCsv', 'url' => '/api/export.csv', 'verb' => 'GET'],
        ['name' => 'api#exportXlsx', 'url' => '/api/export.xlsx', 'verb' => 'GET'],
    ],
];
