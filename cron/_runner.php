<?php

declare(strict_types=1);

use App\Database;
use App\Support;
use App\SyncService;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$task = $argv[1] ?? 'all';
$lockName = 'auto_lq_' . preg_replace('/[^a-z_]/', '', $task);
$lock = Database::fetch('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
if ((int) ($lock['acquired'] ?? 0) !== 1) {
    exit("Task is already running: {$task}\n");
}

try {
    $service = new SyncService();
    $result = match ($task) {
        'products' => $service->syncProducts(),
        'pull' => $service->pullOrders(),
        'dispatch' => $service->dispatchOrders(),
        'target-status' => $service->syncStatuses(),
        'nas-status' => $service->pushStatuses(),
        default => $service->runAll(),
    };
    echo date('c') . ' ' . Support::json($result) . PHP_EOL;
} finally {
    Database::fetch('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
}

