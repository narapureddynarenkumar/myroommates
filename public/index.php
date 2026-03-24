<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/**
 * Remove base path (/roommate)
 */
$basePath = '/roommate';
if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

if ($uri === '') {
    $uri = '/';
}

/**
 * Route map
 */
$routes = [
    '/rooms'       => __DIR__ . '/../routes/rooms.php',
    '/members'     => __DIR__ . '/../routes/members.php',
    '/expenses'    => __DIR__ . '/../routes/expenses.php',
    '/rules'       => __DIR__ . '/../routes/rules.php',
    '/settlements' => __DIR__ . '/../routes/settlements.php',
];

/**
 * Match routes
 */
foreach ($routes as $path => $file) {
    if ($uri === $path || strpos($uri, $path . '/') === 0) {
        require $file;
        exit;
    }
}

response(404, ['message' => 'Route not found']);
?>