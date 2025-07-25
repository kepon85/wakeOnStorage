<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use WakeOnStorage\Auth;
use WakeOnStorage\Logger;
use WakeOnStorage\Mailer;

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

$maintenanceActive = !empty($global['maintenance']);
$maintenanceMessage = $global['maintenance_message'] ?? '';
if ($maintenanceActive) {
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Maintenance</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container mt-5 text-center">
<?php if (!empty($cfg['interface']['logo'])): ?>
<img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" class="mb-4" style="max-height:150px;">
<?php endif; ?>
<?= $maintenanceMessage ?: '<p>En maintenance...</p>' ?>
</body>
</html>
<?php
    exit;
}

$maintenanceBanner = $maintenanceMessage;

// Allow overriding the post.up page via ?post_up=<url>
$overridePostUp = $_GET['post_up'] ?? null;
if ($overridePostUp) {
    if (!isset($cfg['storage']['up']['post'])) {
        $cfg['storage']['up']['post'] = [
            'methode' => 'redirect',
            'page' => $overridePostUp,
        ];
    } else {
        $cfg['storage']['up']['post']['page'] = $overridePostUp;
    }
}

$wakeTimes = $cfg['storage']['wake_time'] ?? [];
$routerUps = $cfg['router']['router_up'] ?? [];
$routerUpOptions = [];
if ($routerUps) {
    $now = new \DateTimeImmutable('now');
    foreach ($routerUps as $t) {
        $label = trim($t);
        $dt = \DateTimeImmutable::createFromFormat('H:i', $label);
        if ($dt) {
            $candidate = $dt->setDate(
                (int)$now->format('Y'),
                (int)$now->format('m'),
                (int)$now->format('d')
            );
            if ($candidate <= $now) {
                $label .= ' (Demain)';
            }
        }
        $routerUpOptions[] = ['value' => $t, 'label' => $label];
    }
}

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
$pdo->exec("CREATE TABLE IF NOT EXISTS spool (".
    "id INTEGER PRIMARY KEY AUTOINCREMENT,".
    " host TEXT, action TEXT, run_at INTEGER,".
    " user TEXT, ip TEXT, email TEXT, duration REAL DEFAULT 0,".
    " attempts INTEGER DEFAULT 0)");
$cols = $pdo->query("PRAGMA table_info(spool)")->fetchAll(PDO::FETCH_COLUMN,1);
if (!in_array('email', $cols)) {
    $pdo->exec("ALTER TABLE spool ADD COLUMN email TEXT");
}
if (!in_array('duration', $cols)) {
    $pdo->exec("ALTER TABLE spool ADD COLUMN duration REAL DEFAULT 0");
}
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
    $startOpt = $_POST['router_start'] ?? 'asap';
    $notify = filter_var($_POST['notify_email'] ?? '', FILTER_VALIDATE_EMAIL);
    $durHours = floatval($end);
    $runAt = time();
    if ($startOpt && $startOpt !== 'asap') {
        $tm = \DateTimeImmutable::createFromFormat('H:i', $startOpt);
        if ($tm) {
            $now = new \DateTimeImmutable('now');
            $cand = $tm->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
            if ($cand <= $now) {
                $cand = $cand->modify('+1 day');
            }
            $runAt = $cand->getTimestamp();
        }
    }
    $downAt = $durHours > 0 ? $runAt + (int)($durHours * 3600) : 0;
    Logger::logEvent($pdo, $host, 'router_schedule', $authenticatedUser);
    $row = $pdo->prepare("SELECT id FROM spool WHERE host=? AND action='storage_up' LIMIT 1");
    $row->execute([$host]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $pdo->prepare('UPDATE spool SET run_at=?, email=?, duration=?, user=?, ip=? WHERE id=?')->execute([
            $runAt,
            $notify ?: '',
            $durHours,
            is_string($authenticatedUser) ? $authenticatedUser : '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            $r['id']
        ]);
    } else {
        $pdo->prepare('INSERT INTO spool (host, action, run_at, user, ip, email, duration) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $host,
                'storage_up',
                $runAt,
                is_string($authenticatedUser) ? $authenticatedUser : '',
                $_SERVER['REMOTE_ADDR'] ?? '',
                $notify ?: '',
                $durHours
            ]);
    }
    if ($downAt > 0) {
        $row = $pdo->prepare("SELECT id FROM spool WHERE host=? AND action='storage_down' AND user=? AND ip=? LIMIT 1");
        $row->execute([
            $host,
            is_string($authenticatedUser) ? $authenticatedUser : '',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        $d = $row->fetch(PDO::FETCH_ASSOC);
        if ($d) {
            $pdo->prepare('UPDATE spool SET run_at=? WHERE id=?')->execute([$downAt, $d['id']]);
        } else {
            $pdo->prepare('INSERT INTO spool (host, action, run_at, user, ip, email) VALUES (?,?,?,?,?,?)')
                ->execute([
                    $host,
                    'storage_down',
                    $downAt,
                    is_string($authenticatedUser) ? $authenticatedUser : '',
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    ''
                ]);
        }
    }
    $to = $global['contact_admin']['email'] ?? '';
    if ($to) {
        $subject = '[WOS] Planification routeur';
        $body = "Utilisateur $authenticatedUser a demande l'allumage du routeur sur $host jusqu'a $end.";
        Mailer::send($global['mail'] ?? [], $global['contact_admin']['name'] ?? 'Admin', $to, $to, $subject, $body);
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
  <?php if ($maintenanceBanner): ?>
  <div class="alert alert-warning text-center mb-3"><?php echo $maintenanceBanner; ?></div>
  <?php endif; ?>

  <div id="notifications" class="position-fixed top-0 end-0 p-3" style="z-index:1051;"></div>
  <?php if ($message): ?>
  <script>var initialMessage = <?= json_encode($message) ?>;</script>
  <?php endif; ?>

  <form id="router-plan" method="post" class="mb-3 d-none">
    <div class="mb-3">
      <p id="router-msg">Le storage ne peut être allumé pour le moment.</p>
      <label class="form-label">Allumage</label>
      <select name="router_start" class="form-select mb-2">
        <option value="asap">Dès que possible</option>
        <?php foreach ($routerUpOptions as $opt): ?>
        <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="form-label">Durée d'allumage</label>
      <select name="router_end" class="form-select">
        <?php foreach ($wakeTimes as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">E-mail de notification (optionnel)</label>
      <input type="email" name="notify_email" class="form-control">
    </div>
    <button type="submit" name="schedule_router" class="btn btn-primary">Planifier l'allumage</button>
    <button type="button" id="cancel-start" class="btn btn-danger ms-2 d-none">Annuler la demande</button>
  </form>
  <div id="router-actions" class="mb-3 d-none">
    <select id="on-duration" class="form-select d-inline-block w-auto me-2">
      <?php foreach ($wakeTimes as $t): ?>
      <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
      <?php endforeach; ?>
    </select>
    <button id="btn-on" class="btn btn-success me-2">Allumer</button>
    <button id="btn-extend" class="btn btn-primary me-2 d-none">Prolonger</button>
    <button id="btn-off" class="btn btn-danger">Eteindre</button>
  </div>
  <div id="down-info" class="alert alert-warning d-none mb-3">
    Le storage va s'arrêter dans <span id="down-time">--</span>.
  </div>
  <div id="energy-info" class="row mb-3">
    <div id="battery-info" class="col-auto mb-2 d-none"></div>
    <div id="solar-production" class="col-auto mb-2 d-none"></div>
    <div id="solar-forecast" class="col mb-2 d-none"></div>
  </div>
  <div id="storage-content" class="mb-3"></div>
  <div id="energy-mode-msg" class="alert alert-info mb-3"></div>
  <div id="loading" style="" class="position-fixed top-0 bottom-0 start-0 end-0 bg-white bg-opacity-50 d-flex flex-column justify-content-center align-items-center" style="z-index:1060;">
    <img src="./img/load.svg" alt="loading" class="mb-3" style="max-width:175px;">
    <p id="loading-text" class="h5 mb-0">Requête sur le serveur en cours...</p>
  </div>
</div>
<?php if (!empty($cfg['interface']['js_include'])): foreach ($cfg['interface']['js_include'] as $js): ?>
<script src="<?= htmlspecialchars($js) ?>"></script>
<?php endforeach; endif; ?>
<?php $refresh = (int)($global['ajax']['refresh'] ?? 10); ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
var currentUser = <?= json_encode($authenticatedUser) ?>;
var currentIp = <?= json_encode($_SERVER['REMOTE_ADDR'] ?? '') ?>;
var refreshInterval = <?= $refresh ?> * 1000;
var routerSince = 0;
var batterySince = 0;
var solarSince = 0;
var forecastSince = 0;
var storageSince = 0;
var lastBattery = null;
var lastSolar = null;
var lastForecast = null;
var storagePostUp = <?php echo json_encode($cfg['storage']['up']['post'] ?? null); ?>;
var storagePostDown = <?php echo json_encode($cfg['storage']['down']['post'] ?? null); ?>;
var lastStorageStatus = null;
var storagePostUpShown = false;
var storagePostDownShown = false;
var firstUpdate = true;
var energyPrint = <?php echo json_encode($cfg['energy']['interface_print'] ?? []); ?>;
var storageConso = <?php echo (int)($cfg['storage']['conso'] ?? 0); ?>;
var energyMode = <?php echo json_encode($cfg['energy']['mode'] ?? 'all'); ?>;
var batteryCfg = <?php echo json_encode($cfg['energy']['batterie'] ?? []); ?>;
var wakeTimesJs = <?php echo json_encode($wakeTimes); ?>;
var storageUpTime = <?php echo (int)($cfg['storage']['up']['time'] ?? 0); ?>;
var storageUpTimeout = <?php echo (int)($cfg['storage']['up']['timeout'] ?? 0); ?>;
var storageDownTime = <?php echo (int)($cfg['storage']['down']['time'] ?? 0); ?>;
var storageDownTimeout = <?php echo (int)($cfg['storage']['down']['timeout'] ?? 0); ?>;
var waitStatus = null;

function showPostUp() {
  if (!storagePostUp || storagePostUpShown) return;
  if (storagePostUp.methode === 'redirect') {
    window.location.href = storagePostUp.page;
    storagePostUpShown = true;
    return;
  }
  var cont = $('#storage-content');
  cont.empty();
  if (storagePostUp.methode === 'redirect-iframe' && storagePostUp.page) {
    var ifr = $('<iframe>').attr('src', storagePostUp.page)
      .addClass('w-100').css('height', '600px').attr('frameborder', '0');
    cont.append(ifr);
  } else if (storagePostUp.methode === 'text' && storagePostUp.content) {
    cont.html(storagePostUp.content);
  }
  storagePostUpShown = true;
}

function showPostDown() {
  if (!storagePostDown || storagePostDownShown) return;
  if (storagePostDown.methode === 'redirect') {
    window.location.href = storagePostDown.page;
    storagePostDownShown = true;
    return;
  }
  var cont = $('#storage-content');
  cont.empty();
  if (storagePostDown.methode === 'redirect-iframe' && storagePostDown.page) {
    var ifr = $('<iframe>').attr('src', storagePostDown.page)
      .addClass('w-100').css('height', '600px').attr('frameborder', '0');
    cont.append(ifr);
  } else if (storagePostDown.methode === 'text' && storagePostDown.content) {
    cont.html(storagePostDown.content);
  }
  storagePostDownShown = true;
}

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
var scheduledDownDate = null;
var scheduledDownUser = null;
var scheduledDownIp = null;

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

function updateDownCountdown() {
  if (!scheduledDownDate) return;
  var now = new Date();
  var diff = scheduledDownDate - now;
  if (diff < 0) diff = 0;
  var minutes = Math.floor(diff / 60000);
  var hours = Math.floor(minutes / 60);
  minutes = minutes % 60;
  $('#down-time').text(hours + ' heure(s) et ' + minutes + ' minute(s)');
  $('#down-info').removeClass('d-none');
}

setInterval(updateCountdown, 60000);
setInterval(updateDownCountdown, 60000);

function displayEnergy(data) {
  if (data.batterie) lastBattery = data.batterie;
  if (data.production_solaire) lastSolar = data.production_solaire;
  if (data.production_solaire_estimation) lastForecast = data.production_solaire_estimation;

  if (energyPrint.batterie && lastBattery) {
    $('#battery-info').removeClass('d-none')
      .text('Batterie: ' + lastBattery[0].value + '%');
  }
  if (energyPrint.production_solaire && lastSolar) {
    var prod = Math.round(lastSolar.value);
    var prodElem = $('#solar-production').removeClass('d-none');
    prodElem.text('Production: ' + prod + ' W');
    if (prod > storageConso) {
      prodElem.removeClass('text-danger').addClass('text-success');
    } else {
      prodElem.removeClass('text-success').addClass('text-danger');
    }
  }
  if (energyPrint.production_solaire_estimation && lastForecast) {
    var cont = $('#solar-forecast').empty();
    var arr = lastForecast.values || [];
    if (arr.length) {
      var limit = 8;
      var collapsed = $('<span class="forecast-collapsed">').appendTo(cont);
      var more = $('<span class="forecast-more d-none">').appendTo(cont);
      arr.forEach(function(f, i){
        var t = new Date(f.period_end);
        var time = t.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        var icon = f.pv_estimate > 0 ? '☀️' : '☁️';
        var item = $('<div class="d-inline-block text-center me-2 forecast-item">')
          .append($('<div>').text(time))
          .append($('<div>').text(icon + ' ' + Math.round(f.pv_estimate) + ' W'));
        if (f.pv_estimate > storageConso) {
          item.addClass('text-success');
        } else {
          item.addClass('text-danger');
        }
        if (i < limit) item.appendTo(collapsed); else item.appendTo(more);
      });
      if (more.children().length) {
        var toggle = $('<span class="ms-2 forecast-toggle">➕</span>').appendTo(cont);
        toggle.on('click', function(){
          more.toggleClass('d-none');
          $(this).text(more.hasClass('d-none') ? '➕' : '➖');
        });
      }
      cont.removeClass('d-none');
    } else {
      cont.addClass('d-none');
    }
  }
}

function computeSolarHours(forecast) {
  if (!Array.isArray(forecast)) return 0;
  var now = new Date();
  var h = 0;
  for (var i = 0; i < forecast.length; i++) {
    var f = forecast[i];
    var t = new Date(f.period_end);
    if (t <= now) continue;
    if (f.pv_estimate >= storageConso) h++; else break;
  }
  return h;
}

function updateEnergyModeMsg() {
  var txt = '';
  switch (energyMode) {
    case 'solar-strict':
      txt = "Mode solaire strict : ce stockage n'utilise que l'énergie solaire. L'allumage n'est possible que pendant les heures de production suffisantes.";
      break;
    case 'solar':
      txt = "Mode solaire pr\xE9f\xE9rentiel : les plages en vert fonctionnent avec l'\xE9nergie solaire.";
      break;
    case 'solar-batterie':
      txt = "Mode solaire + batterie : les plages en vert utilisent l'\xE9nergie solaire, les autres la batterie.";
      if (batteryCfg && batteryCfg.soc_mini) {
        txt += ' Allumage impossible si la batterie est sous ' + batteryCfg.soc_mini + '%.';
      }
      break;
    default:
      txt = "Aucune contrainte \xE9nerg\xE9tique : le stockage peut \xEAtre allum\xE9 \xE0 tout moment.";
  }
  $('#energy-mode-msg').text(txt);
}

function applyEnergyRules(data) {
  var forecast = lastForecast ? lastForecast.values || [] : [];
  var solarHours = computeSolarHours(forecast);

  var opts = $('#on-duration option, #router-plan select[name="router_end"] option');
  opts.each(function(){
    var dur = parseFloat($(this).val());
    if (isNaN(dur)) return;
    var solar = dur <= solarHours;
    var label = dur + 'h';
    if (energyMode === 'solar') {
      if (solar) label += ' - Avec l\'énergie solaire';
      $(this).toggleClass('text-success', solar)
             .toggleClass('text-warning', !solar);
    } else if (energyMode === 'solar-batterie') {
      label += solar ? ' - Avec l\'énergie solaire' : ' - Batterie';
      $(this).toggleClass('text-success', solar)
             .toggleClass('text-warning', !solar);
    } else {
      $(this).removeClass('text-success text-warning');
    }
    $(this).text(label);
    if (energyMode === 'solar-strict') {
      $(this).prop('disabled', !solar);
    } else {
      $(this).prop('disabled', false);
    }
  });

  var selected = $('#on-duration option:selected');
  var disable = false;
  if (energyMode === 'solar-strict') {
    if (solarHours <= 0 || selected.prop('disabled')) disable = true;
  }
  if (energyMode === 'solar-batterie') {
    var idx = parseInt(batteryCfg.id || 0);
    var socMin = parseFloat(batteryCfg.soc_mini || batteryCfg.soc_min || 0);
    if (lastBattery && lastBattery[idx]) {
      var val = parseFloat(lastBattery[idx].value);
      if (!isNaN(val) && socMin > 0 && val < socMin) disable = true;
    }
  }
  if (disable) {
    $('#btn-on').prop('disabled', true);
  }
}

function updateAll() {
  if (firstUpdate) {
    $('#loading-text').text('Requête sur le serveur en cours...');
    $('#loading').removeClass('d-none');
  }
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
        }
        if (!routerNote) routerNote = notify('warn', msg, 0); else routerNote.text(msg);
        if (data.router.pending_start) {
          plan.find('button[name="schedule_router"]').prop('disabled', true);
          $('#cancel-start').removeClass('d-none');
          $('#router-msg').text('Une demande d\'allumage est en attente.');
        } else {
          plan.find('button[name="schedule_router"]').prop('disabled', false);
          $('#cancel-start').addClass('d-none');
          $('#router-msg').text('Le storage ne peut être allumé pour le moment.');
        }
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
        if (data.storage.scheduled_down) {
            scheduledDownDate = new Date(data.storage.scheduled_down * 1000);
            scheduledDownUser = data.storage.scheduled_down_user || null;
            scheduledDownIp = data.storage.scheduled_down_ip || null;
            updateDownCountdown();
        } else {
            scheduledDownDate = null;
            scheduledDownUser = null;
            scheduledDownIp = null;
            $('#down-info').addClass('d-none');
        }
        var otherOwner = false;
        if (scheduledDownDate && scheduledDownUser !== null) {
            if ((scheduledDownUser && scheduledDownUser !== currentUser) ||
                (scheduledDownIp && scheduledDownIp !== currentIp)) {
                otherOwner = true;
            }
        }
        if (data.storage.status === 'up') {
            $('#btn-on').addClass('d-none');
            $('#btn-extend').removeClass('d-none');
            $('#btn-off').prop('disabled', otherOwner);
        } else if (data.storage.status === 'down') {
            $('#btn-on').removeClass('d-none').prop('disabled', false);
            $('#btn-extend').addClass('d-none');
            $('#btn-off').prop('disabled', true);
        } else {
            $('#btn-on').removeClass('d-none').prop('disabled', true);
            $('#btn-extend').addClass('d-none');
            $('#btn-off').prop('disabled', true);
        }
        if (data.storage.status !== lastStorageStatus) {
            if (data.storage.status === 'up') {
                showPostUp();
                storagePostDownShown = false;
            } else if (data.storage.status === 'down') {
                showPostDown();
                storagePostUpShown = false;
            } else {
                $('#storage-content').empty();
                storagePostUpShown = false;
                storagePostDownShown = false;
            }
            lastStorageStatus = data.storage.status;
        }
        if (waitStatus) {
            var desired = waitStatus.action;
            if (data.storage.status === desired) {
                $('#loading').addClass('d-none');
                waitStatus = null;
            } else {
                var elapsed = Date.now() - waitStatus.start;
                if (waitStatus.timeout > 0 && elapsed > waitStatus.timeout) {
                    $('#loading-text').text("Le délai est dépassé, désolé veuillez contacter l'administrateur, un problème est certainement survenu");
                    setTimeout(function(){ $('#loading').addClass('d-none'); }, 5000);
                    waitStatus = null;
                } else if (waitStatus.time > 0 && elapsed > waitStatus.time) {
                    $('#loading-text').text("C'est un peu long mais ça peut encore venir");
                }
            }
        }
    }
    displayEnergy(data);
    applyEnergyRules(data);
    if (data.debug) console.debug('api debug', data.debug);
  }).always(function() {
    if (firstUpdate) {
      $('#loading').addClass('d-none');
      firstUpdate = false;
    }
    setTimeout(updateAll, refreshInterval);
  });
}
$(updateAll);
updateEnergyModeMsg();

function doStorageAction(act, extra) {
  $('#loading-text').text('Action demandée, merci de patienter...');
  $('#loading').removeClass('d-none');
  var data = {action: act};
  if (extra) {
    for (var k in extra) data[k] = extra[k];
  }
  $.post('api.php', data, function(res) {
    if (res && res.success) {
      var msg = 'Action effectu\xE9e';
      if (act === 'storage_up') msg = 'Allumage demand\xE9';
      else if (act === 'storage_down') msg = 'Extinction demand\xE9e';
      else if (act === 'extend_up') msg = 'Prolongation demand\xE9e';
      notify('info', msg);
      storageSince = 0; // force refresh
      if (act === 'storage_up' || act === 'storage_down') {
        var t = act === 'storage_up' ? storageUpTime : storageDownTime;
        var to = act === 'storage_up' ? storageUpTimeout : storageDownTimeout;
        waitStatus = {
          action: act === 'storage_up' ? 'up' : 'down',
          start: Date.now(),
          time: t * 1000,
          timeout: to * 1000
        };
        var txt = act === 'storage_up' ? 'Allumage demand\xE9... patience' : 'Extinction demand\xE9e... patience';
        $('#loading-text').text(txt);
        $('#loading').removeClass('d-none');
        updateAll();
        return;
      }
    } else {
      if (act === 'storage_down' && res.reason === 'not_owner') {
        var when = res.scheduled_down ? new Date(res.scheduled_down * 1000) : null;
        var txt = "Impossible d'\xE9teindre : arrêt programm\xE9 pour ";
        if (when) {
          txt += when.toLocaleString();
        } else {
          txt += 'une date inconnue';
        }
        notify('warn', txt, 5000);
      } else if (act === 'storage_down' && res.reason === 'connections_active') {
        var cnt = res.count || 0;
        var msg = cnt + ' connexion';
        if (cnt > 1) msg += 's';
        msg += " en cours sur ce storage, il ne peut \xEAtre \xE9teint, veuillez r\xE9essayer ult\xE9rieurement";
        notify('warn', msg, 5000);
      } else {
        notify('warn', 'Erreur lors de l\'action');
      }
    }
  }, 'json').always(function(){
    if (!waitStatus) $('#loading').addClass('d-none');
  });
}

$('#btn-on').on('click', function(e){
  e.preventDefault();
  var dur = $('#on-duration').val();
  doStorageAction('storage_up', {duration: dur});
});
$('#btn-off').on('click', function(e){
  e.preventDefault();
  if (scheduledDownUser && ((scheduledDownUser && scheduledDownUser !== currentUser) || (scheduledDownIp && scheduledDownIp !== currentIp))) {
    notify('warn', "Impossible d'éteindre : arrêt déjà programmé par un autre utilisateur.", 5000);
    return;
  }
  doStorageAction('storage_down');
});
$('#btn-extend').on('click', function(e){
  e.preventDefault();
  var dur = $('#on-duration').val();
  doStorageAction('extend_up', {duration: dur});
});

$('#cancel-start').on('click', function(e){
  e.preventDefault();
  $.post('api.php', {action: 'cancel_up'}, function(){
    notify('info', 'Demande annulée');
    routerSince = 0;
    updateAll();
  }, 'json');
});
</script>
</body>
</html>
