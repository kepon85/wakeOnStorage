<?php
session_start();
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

function wos_log_event($pdo, $host, $action, $user = null) {
    $stmt = $pdo->prepare("INSERT INTO events (host, action, user, ip) VALUES (?,?,?,?)");
    $stmt->execute([$host, $action, $user, $_SERVER['REMOTE_ADDR'] ?? '']);
}


// --- Authentication handling ---
$authMethods = $cfg['auth']['method'] ?? ['none'];
$authKey = 'wos_auth_' . $host;
$authenticatedUser = $_SESSION[$authKey] ?? null;
$error = null;

function wos_check_file($path, $user, $pass) {
    if (!$path || !file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) {
        if (strpos($l, ':') !== false) {
            list($u, $p) = array_map('trim', explode(':', $l, 2));
            if ($u === $user) {
                if (password_verify($pass, $p) || $p === $pass) {
                    return true;
                }
            }
        }
    }
    return false;
}

function wos_check_imap($cfg, $user, $pass) {
    if (!extension_loaded('imap')) return false;
    $server = $cfg['imap']['server'] ?? 'localhost';
    $port = $cfg['imap']['port'] ?? 143;
    $secure = $cfg['imap']['secure'] ?? '';
    $mailbox = '{' . $server . ':' . $port;
    if ($secure === 'ssl') $mailbox .= '/ssl';
    elseif ($secure === 'tls') $mailbox .= '/tls';
    $mailbox .= '}INBOX';
    $imap = @imap_open($mailbox, $user, $pass);
    if ($imap) { imap_close($imap); return true; }
    return false;
}

function wos_check_uniq($cfg, $pass) {
    if (!empty($cfg['uniq']['password_hash'])) {
        return password_verify($pass, $cfg['uniq']['password_hash']);
    }
    return $pass === ($cfg['uniq']['password'] ?? '');
}

if (!$authenticatedUser) {
    if (in_array('none', $authMethods)) {
        $authenticatedUser = 'guest';
        $_SESSION[$authKey] = $authenticatedUser;
        wos_log_event($pdo, $host, 'login_success', 'guest');
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        $ok = false;
        foreach ($authMethods as $m) {
            switch ($m) {
                case 'uniq':
                    if (wos_check_uniq($cfg['auth'], $pass)) { $ok = true; $user = 'uniq'; }
                    break;
                case 'file':
                    if (wos_check_file($cfg['auth']['file']['path'] ?? '', $user, $pass)) $ok = true;
                    break;
                case 'imap':
                    if (wos_check_imap($cfg['auth'], $user, $pass)) $ok = true;
                    break;
            }
            if ($ok) break;
        }
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION[$authKey] = $user ?: true;
            wos_log_event($pdo, $host, 'login_success', $user ?: '');
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            wos_log_event($pdo, $host, 'login_fail', $user);
            $error = 'Invalid credentials';
        }
    }
}

if (!$authenticatedUser) {
    $needsUser = in_array('file', $authMethods) || in_array('imap', $authMethods);
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Authentification</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-5">
  <h1 class="h4 mb-3">Authentification requise</h1>
  <?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post">
    <?php if ($needsUser): ?>
    <div class="mb-3">
      <label class="form-label">Utilisateur</label>
      <input type="text" name="username" class="form-control" required>
    </div>
    <?php endif; ?>
    <div class="mb-3">
      <label class="form-label">Mot de passe</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" name="login" class="btn btn-primary">Se connecter</button>
  </form>
</body>
</html>
<?php
    exit;
}

$action = $_GET['action'] ?? null;
$message = '';
if ($action === 'up' || $action === 'down') {
    wos_log_event($pdo, $host, $action, $authenticatedUser);
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
