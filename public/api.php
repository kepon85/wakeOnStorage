<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use WakeOnStorage\Router;

$global = Yaml::parseFile(__DIR__ . '/../config/global.yml');
$host = $_SERVER['HTTP_HOST'] ?? 'default';
$host = preg_replace('/:\d+$/', '', $host);
$configDir = __DIR__ . '/../' . ($global['interface_config_dir'] ?? 'config/interfaces');
$file = "$configDir/{$host}.yml";
if (!file_exists($file)) {
    $file = "$configDir/exampledemo.yml";
}
$cfg = Yaml::parseFile($file);

$now = time();
$routerSince = isset($_GET['router_since']) ? (int)$_GET['router_since'] : 0;
$routerRefresh = (int)($global['ajax']['router_refresh'] ?? 10);

$result = ['timestamp' => $now];

if ($now - $routerSince >= $routerRefresh) {
    $routerAvailable = true;
    $nextRouter = null;
    if (!empty($cfg['router']['router_check'])) {
        $rc = $cfg['router']['router_check'];
        if (($rc['methode'] ?? '') === 'ping') {
            $hostCheck = $rc['host'] ?? 'localhost';
            $count = (int)($rc['count'] ?? 1);
            $timeout = (int)($rc['timeout'] ?? 1);
            $routerAvailable = Router::ping($hostCheck, $count, $timeout);
        }
    }
    if (!$routerAvailable) {
        $nextRouter = Router::nextSchedule($cfg['router']['router_up'] ?? []);
    }
    $result['router'] = [
        'available' => $routerAvailable,
        'next' => $nextRouter,
    ];
    $result['router_timestamp'] = $now;
}

header('Content-Type: application/json');
echo json_encode($result);

