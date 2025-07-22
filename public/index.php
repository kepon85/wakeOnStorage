<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use WakeOnStorage\Auth;
use WakeOnStorage\Logger;

$global = Yaml::parseFile(__DIR__ . '/../config/global-default.yml');
$override = __DIR__ . '/../config/global.yml';
if (file_exists($override)) {
    $global = array_replace_recursive($global, Yaml::parseFile($override));
}
$host = $_SERVER['HTTP_HOST'] ?? 'default';
$host = preg_replace('/:\d+$/', '', $host);
$configDir = __DIR__ . '/../' . ($global['interface_config_dir'] ?? 'config/interfaces');
$file = "$configDir/{$host}.yml";
if (!file_exists($file)) {
    $file = "$configDir/default.yml"; // fallback
}

$cfg = Yaml::parseFile($file);

$wakeTimes = $cfg['storage']['wake_time'] ?? [];

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
$authMethods = $cfg['auth']['method'] ?? ['none'];
$authKey = 'wos_auth_' . $host;
$authenticatedUser = $_SESSION[$authKey] ?? null;
$error = null;



if (!$authenticatedUser) {
    if (in_array('none', $authMethods)) {
        $authenticatedUser = 'guest';
        $_SESSION[$authKey] = $authenticatedUser;
        Logger::logEvent($pdo, $host, 'login_success', 'guest');
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        $ok = false;
        foreach ($authMethods as $m) {
            switch ($m) {
                case 'uniq':
                    if (Auth::checkUniq($cfg['auth'], $pass)) { $ok = true; $user = 'uniq'; }
                    break;
                case 'file':
                    if (Auth::checkFile($cfg['auth']['file']['path'] ?? '', $user, $pass)) $ok = true;
                    break;
                case 'imap':
                    if (Auth::checkImap($cfg['auth'], $user, $pass)) $ok = true;
                    break;
            }
            if ($ok) break;
        }
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION[$authKey] = $user ?: true;
            Logger::logEvent($pdo, $host, 'login_success', $user ?: '');
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            Logger::logEvent($pdo, $host, 'login_fail', $user);
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
    Logger::logEvent($pdo, $host, $action, $authenticatedUser);
    $message = $action === 'up' ? 'Storage started' : 'Storage stopped';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_router'])) {
    $end = $_POST['router_end'] ?? '';
    Logger::logEvent($pdo, $host, 'router_schedule', $authenticatedUser);
    $to = $global['contact_admin']['email'] ?? '';
    if ($to) {
        $subject = '[WOS] Planification routeur';
        $body = "Utilisateur $authenticatedUser a demande l'allumage du routeur sur $host jusqu'a $end.";
        @mail($to, $subject, $body);
    }
    $message = 'Planification envoy\xC3\xA9e';
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

  <div id="notifications" class="position-fixed top-0 end-0 p-3" style="z-index:1051;"></div>
  <?php if ($message): ?>
  <script>var initialMessage = <?= json_encode($message) ?>;</script>
  <?php endif; ?>

  <form id="router-plan" method="post" class="mb-3 d-none">
    <div class="mb-3">
      <p id="router-msg">Le storage ne peut être allumé pour le moment.</p>
      <label class="form-label">Durée d'allumage</label>
      <select name="router_end" class="form-select">
        <?php foreach ($wakeTimes as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="schedule_router" class="btn btn-primary">Planifier l'allumage</button>
  </form>
  <div id="router-actions" class="mb-3 d-none">
    <button id="btn-on" class="btn btn-success me-2">Allumer</button>
    <button id="btn-off" class="btn btn-danger">Eteindre</button>
  </div>
  <div id="loading" class="position-fixed top-0 bottom-0 start-0 end-0 bg-light bg-opacity-75 d-none justify-content-center align-items-center" style="z-index:1060;">
    <div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>
  </div>
</div>
<?php if (!empty($cfg['interface']['js_include'])): foreach ($cfg['interface']['js_include'] as $js): ?>
<script src="<?= htmlspecialchars($js) ?>"></script>
<?php endforeach; endif; ?>
<?php $refresh = (int)($global['ajax']['refresh'] ?? 10); ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
var refreshInterval = <?= $refresh ?> * 1000;
var routerSince = 0;
var batterySince = 0;
var solarSince = 0;
var forecastSince = 0;
var storageSince = 0;

function notify(type, text, life) {
  var cls = type === 'warn' ? 'warning' : 'info';
  var div = $('<div>').addClass('alert alert-' + cls).css('cursor','pointer').text(text);
  div.appendTo('#notifications').on('click', function() { $(this).remove(); });
  if (life === undefined) life = 3000;
  if (life > 0) {
    setTimeout(function() { div.fadeOut(200, function(){ $(this).remove(); }); }, life);
  }
  return div;
}
if (typeof initialMessage !== 'undefined') {
  notify('info', initialMessage);
}
var routerNote = null;
var nextRouterDate = null;

function parseNextTime(str) {
  if (!str) return null;
  var parts = str.split(':');
  if (parts.length < 2) return null;
  var now = new Date();
  var target = new Date(now.getFullYear(), now.getMonth(), now.getDate(),
    parseInt(parts[0], 10), parseInt(parts[1], 10), 0);
  if (target <= now) target.setDate(target.getDate() + 1);
  return target;
}

function updateCountdown() {
  if (!nextRouterDate) return;
  var now = new Date();
  var diff = nextRouterDate - now;
  if (diff < 0) diff = 0;
  var minutes = Math.floor(diff / 60000);
  var hours = Math.floor(minutes / 60);
  minutes = minutes % 60;
  $('#router-msg').text(
    'Le storage ne peut être allumé pour le moment, il le sera d\'ici ' +
    hours + ' heure(s) et ' + minutes +
    ' minute(s), vous pouvez planifier un allumage : '
  );
}

setInterval(updateCountdown, 60000);

function updateAll() {
  $.getJSON('api.php', {
      router_since: routerSince,
      battery_since: batterySince,
      solar_since: solarSince,
      forecast_since: forecastSince,
      storage_since: storageSince
  }, function(data) {
    if (data.router_timestamp) {
      routerSince = data.router_timestamp;
    }
    if (data.router) {

      var plan = $('#router-plan');
      var actions = $('#router-actions');
      if (data.router.available === false) {
        var msg = 'Routeur injoignable';
        if (data.router.next) {
          msg += ' - prochain allumage ' + data.router.next;
          nextRouterDate = parseNextTime(data.router.next);
          updateCountdown();
        } else {
          nextRouterDate = null;
          $('#router-msg').text('Le storage ne peut être allumé pour le moment.');
        }
        if (!routerNote) routerNote = notify('warn', msg, 0); else routerNote.text(msg);
        plan.removeClass('d-none');
        actions.addClass('d-none');
      } else {
        if (routerNote) { routerNote.remove(); routerNote = null; }
        nextRouterDate = null;
        plan.addClass('d-none');
        actions.removeClass('d-none');
      }
    }
    if (data.battery_timestamp) { batterySince = data.battery_timestamp; }
    if (data.solar_timestamp) { solarSince = data.solar_timestamp; }
    if (data.forecast_timestamp) { forecastSince = data.forecast_timestamp; }
    if (data.storage_timestamp) { storageSince = data.storage_timestamp; }
    if (data.storage) {
        if (data.storage.status === 'up') {
            $('#btn-on').prop('disabled', true);
            $('#btn-off').prop('disabled', false);
        } else if (data.storage.status === 'down') {
            $('#btn-on').prop('disabled', false);
            $('#btn-off').prop('disabled', true);
        }
    }
    if (data.batterie) console.log('batterie', data.batterie);
    if (data.production_solaire) console.log('prod', data.production_solaire);
    if (data.production_solaire_estimation) console.log('forecast', data.production_solaire_estimation);
    if (data.debug) console.debug('api debug', data.debug);
  }).always(function() {
    setTimeout(updateAll, refreshInterval);
  });
}
$(updateAll);

function doStorageAction(act) {
  $('#loading').removeClass('d-none');
  $.post('api.php', {action: act}, function(res) {
    if (res && res.success) {
      notify('info', act === 'storage_up' ? 'Allumage demand\xE9' : 'Extinction demand\xE9e');
      storageSince = 0; // force refresh
    } else {
      notify('warn', 'Erreur lors de l\'action');
    }
  }, 'json').always(function(){
    $('#loading').addClass('d-none');
  });
}

$('#btn-on').on('click', function(e){ e.preventDefault(); doStorageAction('storage_up'); });
$('#btn-off').on('click', function(e){ e.preventDefault(); doStorageAction('storage_down'); });
</script>
</body>
</html>
