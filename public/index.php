<?php
require_once __DIR__ . '/../lib/yaml.php';

$global = yaml_parse_simple(__DIR__ . '/../config/global.yml');
$host = $_SERVER['HTTP_HOST'] ?? 'default';
$host = preg_replace('/:\d+$/', '', $host);
$configDir = __DIR__ . '/../' . ($global['interface_config_dir'] ?? 'config/interfaces');
$file = "$configDir/{$host}.yml";
if (!file_exists($file)) {
    $file = "$configDir/exampledemo.yml"; // fallback
}
$cfg = yaml_parse_simple($file);

$dbRelative = $global['db_path'] ?? 'data/wakeonstorage.sqlite';
$dbPath = realpath(__DIR__ . '/..') . '/' . ltrim($dbRelative, '/');
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host TEXT,
    action TEXT,
    user TEXT,
    ip TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$action = $_GET['action'] ?? null;
$message = '';
if ($action === 'up' || $action === 'down') {
    $stmt = $pdo->prepare("INSERT INTO events (host, action, user, ip) VALUES (?,?,?,?)");
    $user = $_SERVER['REMOTE_USER'] ?? '';
    $stmt->execute([$host, $action, $user, $_SERVER['REMOTE_ADDR'] ?? '']);
    $message = $action === 'up' ? 'Storage started' : 'Storage stopped';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($cfg['interface']['name'] ?? 'WakeOnStorage') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<?php if (!empty($cfg['interface']['css'])): foreach ($cfg['interface']['css'] as $css): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
<?php endforeach; endif; ?>
</head>
<body>
<div class="container mt-4">
  <header class="d-flex align-items-center mb-3">
    <?php if (!empty($cfg['interface']['logo'])): ?>
    <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" height="64" class="me-3">
    <?php endif; ?>
    <h1 class="h4"><?= htmlspecialchars($cfg['interface']['name'] ?? '') ?></h1>
  </header>

  <?php if ($message): ?>
  <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="mb-3">
    <a class="btn btn-success" href="?action=up">Allumer</a>
    <a class="btn btn-danger" href="?action=down">Eteindre</a>
  </div>
</div>
<?php if (!empty($cfg['interface']['js_include'])): foreach ($cfg['interface']['js_include'] as $js): ?>
<script src="<?= htmlspecialchars($js) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
