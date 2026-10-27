<?php

declare(strict_types=1);

use App\Config;
require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$database = (string) Config::get('DB_DATABASE', 'auto_lq');
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), Config::get('DB_PORT', '3306'));
$pdo = new \PDO($dsn, (string) Config::get('DB_USERNAME', 'root'), (string) Config::get('DB_PASSWORD', ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$sql = preg_replace('/CREATE DATABASE IF NOT EXISTS auto_lq/', 'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $database) . '`', (string) $sql, 1);
$sql = preg_replace('/USE auto_lq;/', 'USE `' . str_replace('`', '', $database) . '`;', (string) $sql, 1);
$pdo->exec((string) $sql);
echo "Database installed: {$database}\n";
