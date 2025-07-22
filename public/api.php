<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use WakeOnStorage\Router;
use WakeOnStorage\Storage;
use WakeOnStorage\Logger;

function http_get_json(string $url, array $headers = [], int $timeout = 5): ?array {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
        ],
    ];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);
    if ($res === false) {
        return null;
    }
    return json_decode($res, true);
}

function cache_fetch(PDO $pdo, string $key, callable $callback, int $ttl, bool $debug, array &$log, string $label): ?array {
    $stmt = $pdo->prepare('SELECT value, updated_at FROM data_cache WHERE key=?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && time() - (int)$row['updated_at'] < $ttl) {
        if ($debug) $log[] = "$label cache hit";
        return json_decode($row['value'], true);
    }
    if ($debug) $log[] = "$label cache expired, calling API";
    $data = $callback();
    if ($debug) $log[] = $data !== null ? "$label API success" : "$label API failed";
    if ($data !== null) {
        $stmt = $pdo->prepare('REPLACE INTO data_cache (key, value, updated_at) VALUES (?,?,?)');
        $stmt->execute([$key, json_encode($data), time()]);
    }
    return $data;
}

$global = Yaml::parseFile(__DIR__ . '/../config/global-default.yml');
$override = __DIR__ . '/../config/global.yml';
if (file_exists($override)) {
    $global = array_replace_recursive($global, Yaml::parseFile($override));
}
$debugEnabled = !empty($global['debug']);
$debugLog = [];
$host = $_SERVER['HTTP_HOST'] ?? 'default';
$host = preg_replace('/:\d+$/', '', $host);
$configDir = __DIR__ . '/../' . ($global['interface_config_dir'] ?? 'config/interfaces');
$file = "$configDir/{$host}.yml";
if (!file_exists($file)) {
    $file = "$configDir/default.yml";
}
$cfg = Yaml::parseFile($file);

$dbRelative = $global['db_path'] ?? 'data/wakeonstorage.sqlite';
$dbPath = realpath(__DIR__ . '/..') . '/' . ltrim($dbRelative, '/');
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS data_cache (key TEXT PRIMARY KEY, value TEXT, updated_at INTEGER)");
$pdo->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, host TEXT, action TEXT, user TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS spool (".
    "id INTEGER PRIMARY KEY AUTOINCREMENT,".
    " host TEXT, action TEXT, run_at INTEGER,".
    " user TEXT, ip TEXT, attempts INTEGER DEFAULT 0)");

$action = $_POST['action'] ?? ($_GET['action'] ?? null);
if (in_array($action, ['storage_up', 'storage_down', 'extend_up'])) {
    $userKey = 'wos_auth_' . $host;
    $user = $_SESSION[$userKey] ?? '';
    $ok = false;
    $logAct = [];
    if ($action === 'storage_up') {
        $cfgAct = $cfg['storage']['up'] ?? null;
        if ($cfgAct) {
            $ok = Storage::trigger($cfgAct, $debugEnabled, $logAct);
        }
        $stmt = $pdo->prepare("INSERT INTO events (host, action, user, ip) VALUES (?,?,?,?)");
        $stmt->execute([$host, $action, is_string($user) ? $user : '', $_SERVER['REMOTE_ADDR'] ?? '']);
        if ($ok) {
            $dur = floatval($_POST['duration'] ?? ($_GET['duration'] ?? 0));
            if ($dur > 0) {
                $runAt = time() + (int)($dur * 3600);
                $row = $pdo->prepare("SELECT id, run_at FROM spool WHERE host=? AND action='storage_down' ORDER BY run_at DESC LIMIT 1");
                $row->execute([$host]);
                $r = $row->fetch(PDO::FETCH_ASSOC);
                if ($r && (int)$r['run_at'] > time()) {
                    if ($runAt > (int)$r['run_at']) {
                        $upd = $pdo->prepare('UPDATE spool SET run_at=?, user=?, ip=? WHERE id=?');
                        $upd->execute([$runAt, is_string($user) ? $user : '', $_SERVER['REMOTE_ADDR'] ?? '', $r['id']]);
                    }
                } else {
                    $ins = $pdo->prepare('INSERT INTO spool (host, action, run_at, user, ip) VALUES (?,?,?,?,?)');
                    $ins->execute([$host, 'storage_down', $runAt, is_string($user) ? $user : '', $_SERVER['REMOTE_ADDR'] ?? '']);
                }
                Logger::logEvent($pdo, $host, 'schedule_down', is_string($user) ? $user : '');
            }
        }
    } elseif ($action === 'storage_down') {
        $reason = null;
        $stmt = $pdo->prepare("SELECT id, run_at, user, ip FROM spool WHERE host=? AND action='storage_down' ORDER BY run_at DESC LIMIT 1");
        $stmt->execute([$host]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $allow = true;
        if ($row && (int)$row['run_at'] > time()) {
            $scheduledRun = (int)$row['run_at'];
            $scheduledUser = $row['user'];
            $scheduledIp = $row['ip'] ?? '';
            if ((string)$row['user'] !== (string)$user || ($row['ip'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
                $allow = false;
                $reason = 'not_owner';
            } else {
                $pdo->prepare('DELETE FROM spool WHERE id=?')->execute([$row['id']]);
            }
        }
        if ($allow) {
            $cfgAct = $cfg['storage']['down'] ?? null;
            if ($cfgAct) {
                $ok = Storage::trigger($cfgAct, $debugEnabled, $logAct);
            }
            $stmt = $pdo->prepare("INSERT INTO events (host, action, user, ip) VALUES (?,?,?,?)");
            $stmt->execute([$host, $action, is_string($user) ? $user : '', $_SERVER['REMOTE_ADDR'] ?? '']);
            if ($ok) {
                $pdo->prepare("DELETE FROM spool WHERE host=? AND action='storage_down'")->execute([$host]);
                Logger::logEvent($pdo, $host, 'storage_down', is_string($user) ? $user : '');
            }
        } else {
            $ok = false;
        }
    } elseif ($action === 'extend_up') {
        $dur = floatval($_POST['duration'] ?? ($_GET['duration'] ?? 0));
        if ($dur > 0) {
            $row = $pdo->prepare("SELECT id, run_at, user FROM spool WHERE host=? AND action='storage_down' ORDER BY run_at DESC LIMIT 1");
            $row->execute([$host]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            $add = (int)($dur * 3600);
            if ($r && (int)$r['run_at'] > time()) {
                $newRun = (int)$r['run_at'] + $add;
                $pdo->prepare('UPDATE spool SET run_at=?, user=?, ip=? WHERE id=?')->execute([
                    $newRun,
                    is_string($user) ? $user : '',
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $r['id']
                ]);
            } else {
                $newRun = time() + $add;
                $pdo->prepare('INSERT INTO spool (host, action, run_at, user, ip) VALUES (?,?,?,?,?)')
                    ->execute([$host, 'storage_down', $newRun, is_string($user) ? $user : '', $_SERVER['REMOTE_ADDR'] ?? '']);
            }
            Logger::logEvent($pdo, $host, 'extend_up', is_string($user) ? $user : '');
            $ok = true;
        }
    }
    header('Content-Type: application/json');
    $resp = ['success' => $ok];
    if (isset($reason)) {
        $resp['reason'] = $reason;
        if (isset($scheduledRun)) {
            $resp['scheduled_down'] = $scheduledRun;
            $resp['scheduled_down_user'] = $scheduledUser ?? '';
            $resp['scheduled_down_ip'] = $scheduledIp ?? '';
        }
    }
    if ($debugEnabled) {
        $resp['debug'] = $logAct;
        $debugLog = array_merge($debugLog, $logAct);
    }
    echo json_encode($resp);
    exit;
}

$now = time();
$routerSince = isset($_GET['router_since']) ? (int)$_GET['router_since'] : 0;
$routerRefresh = (int)($global['ajax']['router_refresh'] ?? 10);
$batterySince = isset($_GET['battery_since']) ? (int)$_GET['battery_since'] : 0;
$batteryRefresh = (int)($global['ajax']['batterie_refresh'] ?? 600);
$solarSince = isset($_GET['solar_since']) ? (int)$_GET['solar_since'] : 0;
$solarRefresh = (int)($global['ajax']['production_solaire_refresh'] ?? 600);
$forecastSince = isset($_GET['forecast_since']) ? (int)$_GET['forecast_since'] : 0;
$forecastRefresh = (int)($global['ajax']['production_solaire_estimation_refresh'] ?? 1800);
$storageSince = isset($_GET['storage_since']) ? (int)$_GET['storage_since'] : 0;
$storageRefresh = (int)($global['ajax']['storage_refresh'] ?? 10);

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

if ($now - $batterySince >= $batteryRefresh && !empty($global['data']['batterie'][0])) {
    $cfgBat = $global['data']['batterie'][0];
    $ttl = (int)($cfgBat['ttl'] ?? 0);
    $data = cache_fetch($pdo, 'batterie0', function() use ($cfgBat) {
        $headers = ['Content-Type: application/json'];
        if (!empty($cfgBat['token'])) {
            $headers[] = 'Authorization: Bearer ' . $cfgBat['token'];
        }
        return http_get_json($cfgBat['url'], $headers);
    }, $ttl, $debugEnabled, $debugLog, 'BATTERIE');
    $value = $data['state'] ?? null;
    $result['batterie'] = [['value' => ($value !== null ? $value : 'NA')]];
    $result['battery_timestamp'] = $now;
}

if ($now - $solarSince >= $solarRefresh && !empty($global['data']['production_solaire'])) {
    $cfgSolar = $global['data']['production_solaire'];
    $ttl = (int)($cfgSolar['ttl'] ?? 0);
    $data = cache_fetch($pdo, 'production_solaire', function() use ($cfgSolar) {
        $headers = ['Content-Type: application/json'];
        if (!empty($cfgSolar['token'])) {
            $headers[] = 'Authorization: Bearer ' . $cfgSolar['token'];
        }
        return http_get_json($cfgSolar['url'], $headers);
    }, $ttl, $debugEnabled, $debugLog, 'PROD_SOL');
    $value = $data['state'] ?? null;
    $result['production_solaire'] = ['value' => ($value !== null ? $value : 'NA')];
    $result['solar_timestamp'] = $now;
}

if ($now - $forecastSince >= $forecastRefresh && !empty($global['data']['production_solaire_estimation'])) {
    $cfgFor = $global['data']['production_solaire_estimation'];
    $ttl = (int)($cfgFor['ttl'] ?? 0);
    $raw = cache_fetch($pdo, 'production_solaire_estimation', function() use ($cfgFor) {
        $headers = ['Content-Type: application/json'];
        if (!empty($cfgFor['token'])) {
            $headers[] = 'Authorization: Bearer ' . $cfgFor['token'];
        }
        return http_get_json($cfgFor['url'], $headers);
    }, $ttl, $debugEnabled, $debugLog, 'FORECAST');
    $forecast = [];
    if ($raw && !empty($raw['forecasts'])) {
        $nowTs = time();
        $start = null;
        foreach ($raw['forecasts'] as $f) {
            $ts = strtotime($f['period_end'] ?? '');
            $val = $f['pv_estimate'] ?? 0;
            if ($ts === false) continue;
            if ($start === null) {
                if ($ts >= $nowTs && $val > 0) {
                    $start = true;
                } else {
                    continue;
                }
            }
            if ($start && $val <= 0 && $ts > $nowTs) {
                break;
            }
            if ($start) {
                if ($ts >= $nowTs) {
                    $forecast[] = ['period_end' => $f['period_end'], 'pv_estimate' => $val];
                }
            }
        }
        if (!$forecast) {
            $start = false;
            foreach ($raw['forecasts'] as $f) {
                $ts = strtotime($f['period_end'] ?? '');
                $val = $f['pv_estimate'] ?? 0;
                if ($ts === false) continue;
                if (!$start && $val > 0) { $start = true; }
                if ($start) {
                    $forecast[] = ['period_end' => $f['period_end'], 'pv_estimate' => $val];
                    if ($val <= 0 && count($forecast) > 1) break;
                }
            }
        }
    }
    $result['production_solaire_estimation'] = ['values' => ($forecast ?: 'NA')];
    $result['forecast_timestamp'] = $now;
}

if ($now - $storageSince >= $storageRefresh && !empty($cfg['storage']['check'])) {
    $status = Storage::checkStatus($cfg['storage']['check'], $debugEnabled, $debugLog);
    if ($status !== null) {
        $result['storage'] = ['status' => $status];
    } else {
        $result['storage'] = [];
    }
    $result['storage_timestamp'] = $now;
}

$stmt = $pdo->prepare("SELECT run_at, user, ip FROM spool WHERE host=? AND action='storage_down' ORDER BY run_at DESC LIMIT 1");
$stmt->execute([$host]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && (int)$row['run_at'] > $now) {
    if (!isset($result['storage'])) $result['storage'] = [];
    $result['storage']['scheduled_down'] = (int)$row['run_at'];
    $result['storage']['scheduled_down_user'] = (string)$row['user'];
    $result['storage']['scheduled_down_ip'] = (string)($row['ip'] ?? '');
}

header('Content-Type: application/json');
if ($debugEnabled) {
    $result['debug'] = $debugLog;
}
echo json_encode($result);

