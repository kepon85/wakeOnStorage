<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

use WakeOnStorage\Auth;
use WakeOnStorage\Logger;
use WakeOnStorage\Mailer;
use WakeOnStorage\Init;

$global = Init::globalConfig();
$host = Init::detectHost();
$cfg = Init::hostConfig($host, $global);
$pdo = Init::initDb($global);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($cfg['interface']['title'] ?? 'WakeOnStorage') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="app.css">
<?php if (!empty($cfg['interface']['css'])): foreach ($cfg['interface']['css'] as $css): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
<?php endforeach; endif; ?>
</head>

<?php

$maintenanceActive = !empty($global['maintenance']);
$maintenanceMessage = $global['maintenance_message'] ?? '';
if ($maintenanceActive) {
    ?>
<body class="container mt-5 text-center">
<?php if (!empty($cfg['interface']['logo'])): ?>
<div><img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" class="mb-4" style="max-height:150px;"></div>
<?php endif; ?>
<div><?= $maintenanceMessage ?: '<p>En maintenance…</p>' ?></div>
<footer class="text-center m-4">
<?php if (!empty($global['global_footer'])): ?>
  <div class="text-center mt-2">
    <?php echo $global['global_footer']; ?>
  </div>
<?php endif; ?>
</footer>
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
<body class="container mt-5">
  <header class="mb-3">
    <div class="row">
      <div class="col-3"></div>
      <div class="col-6 col-6 d-flex align-items-center mb-3 mb-lg-0 flex-column flex-lg-row text-center text-lg-start">
        <?php if (!empty($cfg['interface']['logo'])): ?>
          <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" height="94" class="me-lg-3 mb-2 mb-lg-0 mx-auto mx-lg-0">
        <?php endif; ?>
        <div class="flex-grow-1">
          <h1 class="h4 mb-2 mb-lg-1"><?= htmlspecialchars($cfg['interface']['title'] ?? '') ?></h1>
          <?php if (!empty($cfg['interface']['subTitle'])): ?>
            <p class="mb-2 mb-lg-1"><?= htmlspecialchars($cfg['interface']['subTitle'] ?? '') ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-3"></div>
    </div>
  </header>
  <div class="row">
    <div class="col-3" ></div>
    <div class="col-6">
      <h1 class="h4 mb-3 text-center">Authentification requise</h1>
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
        <button type="submit" name="login" class="btn btn-primary w-100">Se connecter</button>
      </form>
    </div>
    <div class="col-3"></div>
   </div>
  <footer class="text-center m-4">
  <?php if (!empty($global['global_footer'])): ?>
    <div class="text-center mt-2">
      <?php echo $global['global_footer']; ?>
    </div>
  <?php endif; ?>
  </footer>
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
    $message = 'Planification envoyée';
}
?>
<body>
<div class="container mt-4">
  <header class="mb-3">
    <div class="row align-items-center">
      <div class="col-lg-6 col-xl-6 d-flex align-items-center mb-3 mb-lg-0 flex-column flex-lg-row text-center text-lg-start">
        <?php if (!empty($cfg['interface']['logo'])): ?>
          <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" height="94" class="me-lg-3 mb-2 mb-lg-0 mx-auto mx-lg-0">
        <?php endif; ?>
        <div class="flex-grow-1">
          <h1 class="h4 mb-2 mb-lg-1"><?= htmlspecialchars($cfg['interface']['title'] ?? '') ?></h1>
          <?php if (!empty($cfg['interface']['subTitle'])): ?>
            <p class="mb-2 mb-lg-1"><?= htmlspecialchars($cfg['interface']['subTitle'] ?? '') ?></p>
          <?php endif; ?>
          <div id="energy-info" class="row justify-content-center justify-content-lg-start mb-0">
            <div id="battery-info" class="col-auto mb-2 d-none"></div>
            <div id="solar-production" class="col-auto mb-2 d-none"></div>
          </div>
        </div>
      </div>
      <div class="col-lg-6 col-xl-6 mt-3 mt-lg-0 d-flex flex-column align-items-center align-items-lg-end justify-content-center" id="action">
        <div id="eteindre-msg"  class="p-1 text-end"></div>
        <div id="prolong-msg" class="p-1 text-end"></div>
        <div id="router-actions" class="mb-3 d-flex flex-column flex-lg-row align-items-center align-items-lg-end justify-content-center justify-content-lg-end gap-2 w-100" style="max-width:400px;">
          <div class="btn btn-tertiary border border-secondary rounded d-flex align-items-center justify-content-center flex-shrink-0 align-self-stretch" >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" width="24" height="24" style="display:block;">
              <path id="storage-status-path" fill="none" stroke="#6c757d" stroke-width="32" d="M352 64C352 46.3 337.7 32 320 32C302.3 32 288 46.3 288 64L288 320C288 337.7 302.3 352 320 352C337.7 352 352 337.7 352 320L352 64zM210.3 162.4C224.8 152.3 228.3 132.3 218.2 117.8C208.1 103.3 188.1 99.8 173.6 109.9C107.4 156.1 64 233 64 320C64 461.4 178.6 576 320 576C461.4 576 576 461.4 576 320C576 233 532.6 156.1 466.3 109.9C451.8 99.8 431.9 103.3 421.7 117.8C411.5 132.3 415.1 152.2 429.6 162.4C479.4 197.2 511.9 254.8 511.9 320C511.9 426 425.9 512 319.9 512C213.9 512 128 426 128 320C128 254.8 160.5 197.1 210.3 162.4z"/>
            </svg>
          </div>
          <div id="on-extend-and-on-with-durantion" class="input-group mb-2 mb-lg-0 w-100" style="min-width:200px;">
            <button id="btn-extend" class="btn btn-primary d-none" type="button">Prolonger</button>
            <button id="btn-on" class="btn btn-success d-none">Allumer</button>
            <select id="on-duration" class="border border-3 form-select" style="min-width:90px;">
              <?php foreach ($wakeTimes as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button id="btn-off" class="btn btn-danger border border-danger border-3 mb-2 mb-lg-0 w-100">Éteindre</button>
        </div>
      </div>
    </div>
  </header>
  <?php if ($maintenanceBanner): ?>
  <div class="alert alert-warning text-center"><?php echo $maintenanceBanner; ?></div>
  <?php endif; ?>
  
  <div id="notifications" class="position-fixed top-0 end-0 p-3" style="z-index:1051;"></div>
  <?php if ($message): ?>
  <script>var initialMessage = <?= json_encode($message) ?>;</script>
  <?php endif; ?>




  <div class="d-flex justify-content-center">
    <form id="router-plan" method="post" class="d-none mb-3 w-100 d-flex flex-column align-items-center" style="max-width:400px;">
      <div class="mb-3 w-100">
        <h4 class="text-center">Le storage ne peut être allumé pour le moment.</h4>
        <p id="router-msg" class="text-center"></p>
        <div id="schedule-form">
          <label class="form-label">Allumage</label>
          <select name="router_start" class="form-select mb-2">
            <option value="asap">Dès que possible</option>
            <?php foreach ($routerUpOptions as $opt): ?>
            <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label">Durée d’allumage</label>
          <select name="router_end" class="form-select">
            <?php foreach ($wakeTimes as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
            <?php endforeach; ?>
          </select>
          <div class="mb-3 w-100">
            <label class="form-label">E-mail de notification à l’allumage (optionnel)</label>
            <input type="email" name="notify_email" class="form-control">
          </div>
        </div>
      </div>
      <div class="d-flex flex-column flex-lg-row gap-2 w-100">
        <button type="submit" id="schedule_router" name="schedule_router" class="btn btn-primary w-100">Planifier l’allumage</button>
        <button type="button" id="cancel-start" class="btn btn-danger w-100 d-none">Annuler la demande</button>
      </div>
    </form>
  </div>

  <div id="storage-content" class="mb-3"></div>
  <div class="d-flex align-items-center mb-3">
    <div class="flex-grow-1 d-flex flex-column">
      <div id="solar-forecast" class="mb-2"></div>
    </div>
  </div>
  <div id="energy-mode-msg" class="alert alert-info mb-3"></div>
  <div id="loading" style="" class="position-fixed top-0 bottom-0 start-0 end-0 bg-white bg-opacity-75 d-flex flex-column justify-content-center align-items-center" style="z-index:1060;">
    <img src="./img/load.svg" alt="loading" class="mb-3" style="max-width:175px;">
    <p id="loading-text" class="h5 mb-0">Requête sur le serveur en cours…</p>
  </div>

</div>
<footer class="text-center m-4">
<?php if (!empty($cfg['interface']['footer'])): ?>
  <?php echo $cfg['interface']['footer']; ?>  
<?php endif; ?>
<?php if (!empty($global['global_footer'])): ?>
  <div class="text-center mt-2">
    <?php echo $global['global_footer']; ?>
  </div>
<?php endif; ?>
</footer>
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
      .addClass('border border-secondary border-opacity-50 rounded-4 border-4 w-100').css('height', '600px').attr('frameborder', '0');
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
    // Mode iframe : pas de fond dark, pas de centrage, pas de taille fixe
    var ifr = $('<iframe>').attr('src', storagePostDown.page)
      .addClass('w-100').css('height', '600px').attr('frameborder', '0');
    cont.append(ifr);
  } else if (storagePostDown.methode === 'text' && storagePostDown.content) {
    // Mode texte : fond dark, centrage, taille 500x500 sur grand écran
    var wrapper = $('<div>')
      .addClass('d-flex justify-content-center align-items-center bg-dark text-white')
      .css({
        'min-height': '100px',
        'width': '100%',
        'border-radius': '12px'
      });
    // Responsive : sur grand écran, auto sur petit
    wrapper.css({
      'max-width': '70%',
      'max-height': '200px',
      'margin': '0 auto'
    });
    // Utiliser une div interne pour le contenu
    var inner = $('<div>').addClass('w-100 text-center').css({
      'padding': '2rem'
    }).html(storagePostDown.content);
    wrapper.append(inner);
    // Adapter la hauteur sur petits écrans
    if (window.innerWidth >= 768) {
      wrapper.css('height', '500px');
    } else {
      wrapper.css('height', '');
    }
    cont.append(wrapper);
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
    'Il le sera d\'ici ' +
    hours + ' heure(s) et ' + minutes +
    ' minute(s), vous pouvez planifier un allumage : '
  );
}

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
    prodElem.text('Production ☀️ : ' + prod + ' W');
    if (prod > storageConso) {
      prodElem.removeClass('text-danger').addClass('text-success');
    } else {
      prodElem.removeClass('text-success').addClass('text-danger');
    }
  }
  if (energyPrint.production_solaire_estimation && lastForecast) {
    var cont = $('#solar-forecast').removeClass('d-none').empty();
    var arr = lastForecast.values || [];
    if (arr.length) {
      // Afficher tous les items si largeur 0 (premier chargement)
      var maxWidth = cont.width();
      if (maxWidth === 0) maxWidth = cont.parent().width() || 9999;
      var totalWidth = 0;
      cont.append('<div class="d-inline-block text-center me-2 forecast-item"><div>Prévision</div><div>solaire</div></div>');
      for (var i = 0; i < arr.length; i++) {
        var f = arr[i];
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
        cont.append(item);
        var itemWidth = item.outerWidth(true);
        totalWidth += itemWidth;
        if (totalWidth > maxWidth) {
          item.remove();
          break;
        }
      }
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
      txt = "Mode solaire préférentiel : les plages en vert fonctionnent avec l'énergie solaire.";
      break;
    case 'solar-batterie':
      txt = "Mode solaire + batterie : les plages en vert utilisent l'énergie solaire, les autres la batterie.";
      if (batteryCfg && batteryCfg.soc_mini) {
        txt += ' Allumage impossible si la batterie est sous ' + batteryCfg.soc_mini + '%.';
      }
      break;
    default:
      txt = "Aucune contrainte énergétique : le stockage peut être allumé à tout moment.";
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
  console.debug('Updating data...');
  if (firstUpdate) {
    $('#loading-text').text('Requête sur le serveur en cours…');
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
          $('#schedule_router').prop('disabled', true);
          $('#cancel-start').removeClass('d-none');
          $('#schedule_router').addClass('d-none');
          $('#schedule-form').addClass('d-none');
          $('#router-msg').html('<b>Une demande d\'allumage est déjà en attente</b>.');
        } else {
          $('#schedule_router').prop('disabled', false);
          $('#cancel-start').addClass('d-none');
          $('#schedule_router').removeClass('d-none');
          $('#schedule-form').removeClass('d-none');
          $('#router-msg').text('Le stockage ne peut être allumé pour le moment.');
          updateCountdown();
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
        // Récupération de la date d'arrêt programmée
        if (data.storage.scheduled_down) {
            scheduledDownDate = new Date(data.storage.scheduled_down * 1000);
            scheduledDownUser = data.storage.scheduled_down_user || null;
            scheduledDownIp = data.storage.scheduled_down_ip || null;
        } else {
            scheduledDownDate = null;
            scheduledDownUser = null;
            scheduledDownIp = null;
        }
        // Détermination du propriétaire de l'arrêt programmé
        var otherOwner = false;
        if (scheduledDownUser && ((scheduledDownUser && scheduledDownUser !== currentUser) || (scheduledDownIp && scheduledDownIp !== currentIp))) {
            otherOwner = true;
        }
        // Gestion des messages d'état
        var statusMsg = '';
        if (data.storage.status === 'up') {
            if (scheduledDownDate) {
                var now = new Date();
                var diff = scheduledDownDate - now;
                if (diff < 0) diff = 0;
                var minutes = Math.floor(diff / 60000);
                var hours = Math.floor(minutes / 60);
                minutes = minutes % 60;
                statusMsg = "Le stockage est allumé, il s’arrêtera dans " + hours + " heure(s) et " + minutes + " minute(s).";
            } else {
                statusMsg = "Le stockage est allumé.";
            }
            $('#prolong-msg').text(statusMsg).removeClass('d-none');
            $('#eteindre-msg').addClass('d-none');
        } else if (data.storage.status === 'down') {
            statusMsg = "Le stockage est actuellement éteint. Vous pouvez l’allumer.";
            $('#eteindre-msg').text(statusMsg).removeClass('d-none');
            $('#prolong-msg').addClass('d-none');
        } else {
            $('#prolong-msg').addClass('d-none');
            $('#eteindre-msg').addClass('d-none');
        }
        // Affichage des boutons
        if (data.storage.status === 'up') {
            $('#btn-on').addClass('d-none');
            $('#on-duration').removeClass('border-success');
            $('#on-duration').addClass('border-primary');
            $('#btn-extend').removeClass('d-none');
            $('#btn-off').removeClass('d-none').prop('disabled', otherOwner);
        } else if (data.storage.status === 'down') {
            $('#btn-on').removeClass('d-none').prop('disabled', false);
            $('#on-duration').removeClass('border-primary');
            $('#on-duration').addClass('border-success');
            $('#btn-extend').addClass('d-none');
            $('#btn-off').addClass('d-none');
        } else {
            $('#btn-on').removeClass('d-none').prop('disabled', true);
            $('#on-duration').removeClass('border-primary');
            $('#on-duration').removeClass('border-success');
            $('#btn-extend').addClass('d-none');
            $('#btn-off').addClass('d-none');
        }
        // Suppression de #down-info
        $('#down-info').addClass('d-none');
        if (data.storage.status !== lastStorageStatus) {
            if (data.storage.status === 'up') {
                showPostUp();
                storagePostDownShown = false;
            } else if (data.storage.status === 'down') {
                if (!(data.router && data.router.available === false)) {
                    showPostDown();
                }
                storagePostUpShown = false;
            } else {
                $('#storage-content').empty();
                storagePostUpShown = false;
                storagePostDownShown = false;
            }
            lastStorageStatus = data.storage.status;
        }
        var svgColor = '#6c757d';
        if (data.storage && data.storage.status === 'up') svgColor = '#198754';
        else if (data.storage && data.storage.status === 'down') svgColor = '#dc3545';
        $('#storage-status-path').attr('stroke', svgColor);
        if (waitStatus) {
            var desired = waitStatus.action;
            if (data.storage.status === desired) {
                $('#loading').addClass('d-none');
                waitStatus = null;
            } else {
                var elapsed = Date.now() - waitStatus.start;
                if (waitStatus.timeout > 0 && elapsed > waitStatus.timeout) {
                    $('#loading-text').text("Le délai est dépassé. Désolé, veuillez contacter l’administrateur, un problème est certainement survenu.");
                    setTimeout(function(){ $('#loading').addClass('d-none'); }, 5000);
                    waitStatus = null;
                } else if (waitStatus.time > 0 && elapsed > waitStatus.time) {
                    $('#loading-text').text("C’est un peu long, mais on croise les doigts...");
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
      var msg = 'Action effectuée.';
      if (act === 'storage_up') msg = 'Allumage demandé.';
      else if (act === 'storage_down') msg = 'Extinction demandée.';
      else if (act === 'extend_up') msg = 'Prolongation demandée.';
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
        var txt = act === 'storage_up' ? 'Allumage demandé... patience' : 'Extinction demandée... patience';
        $('#loading-text').text(txt);
        $('#loading').removeClass('d-none');
        updateAll();
        return;
      }
    } else {
      if (act === 'storage_down' && res.reason === 'not_owner') {
        var when = res.scheduled_down ? new Date(res.scheduled_down * 1000) : null;
        var txt = "Impossible d’éteindre : arrêt déjà programmé par un autre utilisateur.";
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
        msg += " en cours sur ce stockage, il ne peut pas être éteint, veuillez réessayer ultérieurement.";
        notify('warn', msg, 5000);
      } else {
        notify('warn', 'Erreur lors de l’action.');
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
