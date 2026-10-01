<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use EidCloud\Micro\App;
use EidCloud\Micro\Request;
use EidCloud\Micro\Response;

$app = App::create();

// Global Middlewares
$app->enableCors();
$app->enableTimer();
$app->enableJsonValidator();

// In-memory demo data store
$app->getContainer()->singleton('users_db', function () {
    return [
        1 => ['id' => 1, 'name' => 'Alice Martin', 'role' => 'Engineer'],
        2 => ['id' => 2, 'name' => 'Bob Davis', 'role' => 'Designer'],
        3 => ['id' => 3, 'name' => 'Charlie Smith', 'role' => 'DevOps'],
    ];
});

// Root & Health check
$app->get('/', function () {
    return [
        'name' => 'eidcloud-micro',
        'status' => 'online',
        'version' => '1.0.0',
        'engine' => 'PHP ' . PHP_VERSION,
        'docs' => '/api/info',
    ];
});

$app->get('/health', function () {
    return [
        'status' => 'pass',
        'timestamp' => time(),
        'memory_peak_bytes' => memory_get_peak_usage(true),
        'uptime_s' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
    ];
});

// Route grouping: /api
$app->group('/api', function (EidCloud\Micro\Router $router) use ($app) {
    $router->get('/info', function () {
        return [
            'service' => 'EidCloud Micro API Service',
            'version' => '1.0.0',
            'endpoints' => [
                'GET  /' => 'Root status',
                'GET  /health' => 'Health check & telemetry',
                'GET  /api/info' => 'Service metadata',
                'GET  /api/users' => 'List users',
                'POST /api/users' => 'Create user',
                'GET  /api/users/{id}' => 'Get user by numeric ID',
            ],
        ];
    });

    $router->get('/users', function () use ($app) {
        $db = $app->getContainer()->get('users_db');
        return [
            'total' => count($db),
            'items' => array_values($db),
        ];
    });

    $router->post('/users', function (Request $request) use ($app) {
        $db = $app->getContainer()->get('users_db');
        $data = $request->json() ?? [];

        if (empty($data['name'])) {
            return Response::json([
                'error' => 'Validation Failed',
                'message' => 'The "name" attribute is required.',
            ], 422);
        }

        $newId = empty($db) ? 1 : max(array_keys($db)) + 1;
        $newUser = [
            'id' => $newId,
            'name' => (string) $data['name'],
            'role' => (string) ($data['role'] ?? 'User'),
        ];
        $db[$newId] = $newUser;
        $app->getContainer()->set('users_db', $db);

        return Response::json($newUser, 201);
    });

    $router->get('/users/{id:[0-9]+}', function (string $id) use ($app) {
        $db = $app->getContainer()->get('users_db');
        $userId = (int) $id;

        if (!isset($db[$userId])) {
            return Response::json([
                'error' => 'Not Found',
                'message' => "User with ID {$userId} does not exist.",
            ], 404);
        }

        return $db[$userId];
    });
});

// Run application
$app->run();
