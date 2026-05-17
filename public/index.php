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

$tokenSessionKey = 'wos_token_' . $host;
if (!isset($_SESSION[$tokenSessionKey]) || !is_string($_SESSION[$tokenSessionKey]) || $_SESSION[$tokenSessionKey] === '') {
    $_SESSION[$tokenSessionKey] = bin2hex(random_bytes(32));
}
$apiToken = $_SESSION[$tokenSessionKey];
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($cfg['interface']['title'] ?? $cfg['interface']['name'] ?? 'WakeOnStorage') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="app.css">
<?php if (!empty($cfg['interface']['css'])): foreach ($cfg['interface']['css'] as $css): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
<?php endforeach; endif; ?>
</head>

<?php

$interfaceCfg = $cfg['interface'] ?? [];
$interfaceMaintenanceActive = !empty($interfaceCfg['maintenance']);
$interfaceMaintenanceMessage = $interfaceCfg['maintenance_message'] ?? '';
if (!is_string($interfaceMaintenanceMessage)) {
    $interfaceMaintenanceMessage = '';
}
$interfaceHtmlHeader = $interfaceCfg['include_html_header'] ?? '';
if (!is_string($interfaceHtmlHeader)) {
    $interfaceHtmlHeader = '';
}
$interfaceHtmlFooter = $interfaceCfg['include_html_footer'] ?? '';
if (!is_string($interfaceHtmlFooter)) {
    $interfaceHtmlFooter = '';
}

$maintenanceActive = !empty($global['maintenance']);
$maintenanceMessage = $global['maintenance_message'] ?? '';

if (!is_string($maintenanceMessage)) {
    $maintenanceMessage = '';
}

if ($maintenanceActive) {
    ?>
<body class="aux-page">
  <main class="aux-shell">
    <section class="panel aux-card maintenance-card">
      <?php if (!empty($cfg['interface']['logo'])): ?>
      <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" class="aux-logo">
      <?php endif; ?>
      <div class="aux-copy"><?= $maintenanceMessage ?: '<p>En maintenance…</p>' ?></div>
    </section>
  </main>
<footer class="site-footer">
<?php if (!empty($global['global_footer'])): ?>
  <div>
    <?php echo $global['global_footer']; ?>
  </div>
<?php endif; ?>
</footer>
</body>
</html>
<?php
    exit;
}


if ($interfaceMaintenanceActive) {
    ?>
<body class="aux-page">
  <main class="aux-shell">
    <section class="panel aux-card maintenance-card">
      <?php if (!empty($cfg['interface']['logo'])): ?>
      <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" class="aux-logo">
      <?php endif; ?>
      <div class="aux-copy"><?= $interfaceMaintenanceMessage ?: ($maintenanceMessage ?: '<p>En maintenance…</p>') ?></div>
    </section>
  </main>
<footer class="site-footer">
<?php if (!empty($global['global_footer'])): ?>
  <div>
    <?php echo $global['global_footer']; ?>
  </div>
<?php endif; ?>
</footer>
</body>
</html>
<?php
    exit;
}

$maintenanceBannerParts = [];
if ($maintenanceMessage) {
    $maintenanceBannerParts[] = $maintenanceMessage;
}
if ($interfaceMaintenanceMessage) {
    $maintenanceBannerParts[] = $interfaceMaintenanceMessage;
}
$maintenanceBannerParts = array_values(array_unique($maintenanceBannerParts));
$maintenanceBanner = implode('', $maintenanceBannerParts);

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
<body class="aux-page login-page">
  <main class="aux-shell">
    <section class="panel aux-card login-card">
      <header class="aux-brand">
        <?php if (!empty($cfg['interface']['logo'])): ?>
          <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo" class="aux-logo">
        <?php endif; ?>
        <div>
          <h1><?= htmlspecialchars($cfg['interface']['title'] ?? $cfg['interface']['name'] ?? '') ?></h1>
          <?php if (!empty($cfg['interface']['subTitle'])): ?>
            <p><?= htmlspecialchars($cfg['interface']['subTitle'] ?? '') ?></p>
          <?php endif; ?>
        </div>
      </header>
      <div class="login-content">
        <p class="eyebrow">Accès</p>
        <h2>Authentification requise</h2>
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
    </section>
  </main>
  <footer class="site-footer">
  <?php if (!empty($global['global_footer'])): ?>
    <div>
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
<body class="wos-page status-unknown">
  <?php if ($maintenanceBanner): ?>
  <div class="alert alert-warning text-center"><?php echo $maintenanceBanner; ?></div>
  <?php endif; ?>
  <div id="notifications" class="position-fixed top-0 end-0 p-3"></div>
  <?php if ($message): ?>
  <script>var initialMessage = <?= json_encode($message) ?>;</script>
  <?php endif; ?>

  <header id="workbar" class="workbar d-none">
    <div class="workbar-main">
      <?php if (!empty($cfg['interface']['logo'])): ?>
        <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo">
      <?php endif; ?>
      <span class="status-pill"><span class="status-dot"></span><span class="status-label">Statut inconnu</span></span>
      <span id="work-countdown" class="status-pill d-none"></span>
    </div>
    <div class="workbar-actions">
      <button id="toggle-work-controls" class="icon-btn" type="button" aria-label="Afficher les commandes">☰</button>
      <button id="toggle-maximize" class="icon-btn" type="button" aria-label="Maximiser la fenêtre">⤢</button>
    </div>
  </header>

  <section id="work-controls" class="work-controls is-collapsed d-none">
    <div class="work-summary">
      <strong><?= htmlspecialchars($cfg['interface']['title'] ?? $cfg['interface']['name'] ?? '') ?></strong>
      <span id="work-energy-summary"></span>
    </div>
    <select class="form-select js-duration work-duration">
      <?php foreach ($wakeTimes as $t): ?>
      <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary js-btn-extend d-none" type="button">Prolonger</button>
    <button class="btn btn-outline-danger js-btn-off d-none" type="button">Éteindre</button>
  </section>

  <div id="floating-work-controls" class="floating-work-controls d-none">
    <span id="floating-countdown" class="status-pill d-none"></span>
    <button id="restore-workspace" class="icon-btn" type="button" aria-label="Réduire la fenêtre">⤡</button>
    <button id="toggle-floating-controls" class="icon-btn" type="button" aria-label="Afficher les commandes">☰</button>
  </div>

  <main id="decision-shell" class="page-shell">
    <header class="brandbar">
      <div class="brand">
        <?php if (!empty($cfg['interface']['logo'])): ?>
          <img src="<?= htmlspecialchars($cfg['interface']['logo']) ?>" alt="logo">
        <?php endif; ?>
        <div>
          <h1><?= htmlspecialchars($cfg['interface']['title'] ?? $cfg['interface']['name'] ?? '') ?></h1>
          <?php if (!empty($cfg['interface']['subTitle'])): ?>
            <p><?= htmlspecialchars($cfg['interface']['subTitle'] ?? '') ?></p>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <?php if (!empty($interfaceHtmlHeader)): ?>
    <div class="interface-html-header mb-3"><?php echo $interfaceHtmlHeader; ?></div>
    <?php endif; ?>

    <section class="decision-grid">
      <article class="panel decision-panel">
        <p class="eyebrow">État du stockage</p>
        <h2 class="state-title"><span class="status-dot"></span><span class="status-label">Statut inconnu</span></h2>
        <p id="decision-status-copy" class="decision-copy">Chargement de l'état du stockage...</p>

        <div id="router-actions" class="action-row d-none">
          <div class="duration-field">
            <label for="decision-duration">Durée d'accès</label>
            <select id="decision-duration" class="form-select js-duration">
              <?php foreach ($wakeTimes as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-success js-btn-on d-none" type="button">Allumer maintenant</button>
        </div>

        <form id="router-plan" method="post" class="router-plan d-none">
          <h3>Le stockage ne peut pas être allumé pour le moment</h3>
          <p id="router-msg"></p>
          <div id="schedule-form" class="schedule-grid">
            <div>
              <label class="form-label">Allumage</label>
              <select name="router_start" class="form-select">
                <option value="asap">Dès que possible</option>
                <?php foreach ($routerUpOptions as $opt): ?>
                <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Durée d’allumage</label>
              <select name="router_end" class="form-select">
                <?php foreach ($wakeTimes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?>h</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="schedule-email">
              <label class="form-label">E-mail de notification à l’allumage (optionnel)</label>
              <input type="email" name="notify_email" class="form-control">
            </div>
          </div>
          <div class="schedule-actions">
            <button type="submit" id="schedule_router" name="schedule_router" class="btn btn-primary">Planifier l’allumage</button>
            <button type="button" id="cancel-start" class="btn btn-outline-danger d-none">Annuler la demande</button>
          </div>
        </form>
        <div id="decision-post-content" class="decision-post-content"></div>
      </article>

      <aside class="side-stack">
        <section class="panel energy-panel">
          <p class="eyebrow">Énergie disponible</p>
          <div id="energy-info" class="metric-grid">
            <div id="battery-info" class="metric d-none"></div>
            <div id="solar-production" class="metric d-none"></div>
          </div>
          <div id="solar-forecast" class="solar-forecast d-none"></div>
        </section>
        <section id="energy-mode-msg" class="panel energy-mode-panel"></section>
      </aside>
    </section>

    <?php if (!empty($interfaceHtmlFooter)): ?>
    <div class="interface-html-footer mt-3"><?php echo $interfaceHtmlFooter; ?></div>
    <?php endif; ?>
  </main>

  <main id="workspace-shell" class="workspace-shell d-none">
    <section id="storage-content" class="storage-content"></section>
  </main>
  <div id="loading" class="position-fixed top-0 bottom-0 start-0 end-0 bg-white bg-opacity-75 d-flex flex-column justify-content-center align-items-center">
    <img src="./img/load.svg" alt="loading" class="mb-3 loading-img">
    <p id="loading-text" class="h5 mb-0">Requête sur le serveur en cours…</p>
  </div>
<footer id="page-footer" class="site-footer">
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
<?php
  $refresh = (int)($global['ajax']['refresh'] ?? 10);
  $refreshLoading = (int)($global['ajax']['refresh_loading'] ?? 5);
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
<script>
var wosApiToken = <?= json_encode($apiToken) ?>;
var currentUser = <?= json_encode($authenticatedUser) ?>;
var currentIp = <?= json_encode($_SERVER['REMOTE_ADDR'] ?? '') ?>;
var refreshInterval = <?= $refresh ?> * 1000;
var refreshLoadingInterval = <?= $refreshLoading ?> * 1000;
var defaultRefreshInterval = refreshInterval;
var updateInProgress = false;
var pendingUpdate = false;
var updateTimer = null;
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
var maxWakeTime = Math.max.apply(null, wakeTimesJs.map(function(t){
  var v = parseFloat(t);
  return isNaN(v) ? 0 : v;
}));
var storageUpTime = <?php echo (int)($cfg['storage']['up']['time'] ?? 0); ?>;
var storageUpTimeout = <?php echo (int)($cfg['storage']['up']['timeout'] ?? 0); ?>;
var storageDownTime = <?php echo (int)($cfg['storage']['down']['time'] ?? 0); ?>;
var storageDownTimeout = <?php echo (int)($cfg['storage']['down']['timeout'] ?? 0); ?>;
var waitStatus = null;

function showPostUp() {
  if (!storagePostUp || storagePostUpShown) return;
  $('#decision-post-content').empty();
  if (storagePostUp.methode === 'redirect') {
    window.location.href = storagePostUp.page;
    storagePostUpShown = true;
    return;
  }
  var cont = $('#storage-content');
  cont.empty();
  if (storagePostUp.methode === 'redirect-iframe' && storagePostUp.page) {
    var ifr = $('<iframe>').attr('src', storagePostUp.page)
      .attr('title', 'Accès au stockage')
      .attr('frameborder', '0');
    cont.append(ifr);
  } else if (storagePostUp.methode === 'text' && storagePostUp.content) {
    cont.html($('<div>').addClass('panel decision-panel workspace-message').html(storagePostUp.content));
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
  var cont = $('#decision-post-content');
  cont.empty();
  if (storagePostDown.methode === 'redirect-iframe' && storagePostDown.page) {
    var ifr = $('<iframe>').attr('src', storagePostDown.page)
      .attr('title', 'Contenu après extinction')
      .attr('frameborder', '0');
    cont.append(ifr);
  } else if (storagePostDown.methode === 'text' && storagePostDown.content) {
    var wrapper = $('<div>')
      .addClass('panel decision-panel workspace-message');
    var inner = $('<div>').html(storagePostDown.content);
    wrapper.append(inner);
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
      .html('<span class="metric-label">Batterie</span><strong class="metric-value">' + lastBattery[0].value + '%</strong>');
  }
  if (energyPrint.production_solaire && lastSolar) {
    var prod = Math.round(lastSolar.value);
    var prodElem = $('#solar-production').removeClass('d-none');
    prodElem.html('<span class="metric-label">Production actuelle</span><strong class="metric-value">' + prod + ' W</strong>');
    $('#work-energy-summary').text('Production actuelle : ' + prod + ' W');
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
      cont.append('<div class="forecast-item"><div>Prévision</div><div>solaire</div></div>');
      for (var i = 0; i < arr.length; i++) {
        var f = arr[i];
        var t = new Date(f.period_end);
        var time = t.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        var icon = f.pv_estimate > 0 ? '☀️' : '☁️';
        var item = $('<div class="forecast-item">')
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

function formatRemaining(date) {
  if (!date) return '';
  var diff = date - new Date();
  if (diff < 0) diff = 0;
  var minutes = Math.floor(diff / 60000);
  var hours = Math.floor(minutes / 60);
  minutes = minutes % 60;
  return hours + ' h ' + String(minutes).padStart(2, '0');
}

function setUiMode(status) {
  $('body').removeClass('status-up status-down status-unknown')
    .addClass(status === 'up' ? 'status-up' : (status === 'down' ? 'status-down' : 'status-unknown'));
  if (status === 'up') {
    $('#work-controls').removeClass('d-none');
  } else {
    $('#work-controls').addClass('d-none is-collapsed');
    $('body').removeClass('workspace-maximized');
  }
}

function updateStatusLabels(status, text) {
  $('.status-label').text(text);
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
  var remaining = 0;
  if (data && data.storage && data.storage.status === 'up' && data.storage.scheduled_down) {
    var diffMs = data.storage.scheduled_down * 1000 - Date.now();
    if (diffMs < 0) diffMs = 0;
    var remaining = diffMs / 3600000;
  }

  var opts = $('.js-duration option, #router-plan select[name="router_end"] option');
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
    var disabled = false;
    if (energyMode === 'solar-strict') disabled = !solar;
    if ($(this).closest('select').hasClass('js-duration')) {
      if (maxWakeTime > 0 && remaining + dur > maxWakeTime) disabled = true;
    }
    $(this).prop('disabled', disabled);
  });

  var selected = $('.js-duration').first().find('option:selected');
  var disable = false;
  var extendDisable = false;
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
    $('.js-btn-on').prop('disabled', true);
  }
  var anyEnabled = false;
  $('.js-duration').first().find('option').each(function(){
    if (!$(this).prop('disabled')) anyEnabled = true;
  });
  if (!anyEnabled || selected.prop('disabled') || remaining >= maxWakeTime) {
    extendDisable = true;
  }
  if (extendDisable) {
    $('.js-btn-extend').prop('disabled', true);
  } else {
    $('.js-btn-extend').prop('disabled', false);
  }

  if ($('.js-btn-extend').first().prop('disabled')) {
    $('.js-btn-extend').attr('title', "La limite de prolongement est déjà atteinte");
  } else {
    $('.js-btn-extend').attr('title', "");
  }
}

function updateAll() {
  if (updateTimer) { clearTimeout(updateTimer); updateTimer = null; }
  if (updateInProgress) {
    pendingUpdate = true;
    return;
  }
  updateInProgress = true;
  console.debug('Updating data...');
  if (firstUpdate) {
    $('#loading-text').text('Requête sur le serveur en cours…');
    $('#loading').removeClass('d-none');
  }
  $.getJSON('api.php', {
      token: wosApiToken,
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
                var remainingLabel = formatRemaining(scheduledDownDate);
                statusMsg = "Le stockage est allumé et accessible. Il s’arrêtera automatiquement dans " + remainingLabel + ".";
                $('#work-countdown, #floating-countdown').removeClass('d-none').text('Extinction dans ' + remainingLabel);
            } else {
                statusMsg = "Le stockage est allumé.";
                $('#work-countdown, #floating-countdown').addClass('d-none').text('');
            }
            $('#decision-status-copy').text(statusMsg);
            updateStatusLabels('up', 'Disponible');
            setUiMode('up');
        } else if (data.storage.status === 'down') {
            statusMsg = "Le stockage est actuellement éteint. Vous pouvez l’allumer pour une durée limitée.";
            $('#decision-status-copy').text(statusMsg);
            $('#work-countdown, #floating-countdown').addClass('d-none').text('');
            updateStatusLabels('down', 'Éteint');
            setUiMode('down');
        } else {
            $('#decision-status-copy').text("L'état du stockage n'est pas disponible pour le moment.");
            $('#work-countdown, #floating-countdown').addClass('d-none').text('');
            updateStatusLabels('unknown', 'Statut inconnu');
            setUiMode('unknown');
        }
        // Affichage des boutons
        if (data.storage.status === 'up') {
            $('.js-btn-on').addClass('d-none');
            $('.js-btn-extend').removeClass('d-none');
            $('.js-btn-off').removeClass('d-none').prop('disabled', otherOwner);
        } else if (data.storage.status === 'down') {
            $('.js-btn-on').removeClass('d-none').prop('disabled', false);
            $('.js-btn-extend').addClass('d-none');
            $('.js-btn-off').addClass('d-none');
        } else {
            $('.js-btn-on').removeClass('d-none').prop('disabled', true);
            $('.js-btn-extend').addClass('d-none');
            $('.js-btn-off').addClass('d-none');
        }
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
                $('#decision-post-content').empty();
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
                refreshInterval = defaultRefreshInterval;
            } else {
                var elapsed = Date.now() - waitStatus.start;
                if (waitStatus.timeout > 0 && elapsed > waitStatus.timeout) {
                    $('#loading-text').text("Le délai est dépassé. Désolé, veuillez contacter l’administrateur, un problème est certainement survenu.");
                    setTimeout(function(){ $('#loading').addClass('d-none'); }, 5000);
                    waitStatus = null;
                    refreshInterval = defaultRefreshInterval;
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
    updateInProgress = false;
    if (firstUpdate) {
      $('#loading').addClass('d-none');
      firstUpdate = false;
    }
    if (pendingUpdate) {
      pendingUpdate = false;
      updateAll();
    } else {
      updateTimer = setTimeout(updateAll, refreshInterval);
    }
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
  data.token = wosApiToken;
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
        refreshInterval = refreshLoadingInterval;
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

$('.js-duration').on('change', function(){
  var value = $(this).val();
  $('.js-duration').val(value);
});

$('.js-btn-on').on('click', function(e){
  e.preventDefault();
  var dur = $('.js-duration').first().val();
  doStorageAction('storage_up', {duration: dur});
});
$('.js-btn-off').on('click', function(e){
  e.preventDefault();
  if (scheduledDownUser && ((scheduledDownUser && scheduledDownUser !== currentUser) || (scheduledDownIp && scheduledDownIp !== currentIp))) {
    notify('warn', "Impossible d'éteindre : arrêt déjà programmé par un autre utilisateur.", 5000);
    return;
  }
  doStorageAction('storage_down');
});
$('.js-btn-extend').on('click', function(e){
  e.preventDefault();
  var dur = $('.js-duration').first().val();
  doStorageAction('extend_up', {duration: dur});
});

function toggleWorkControls() {
  $('#work-controls').toggleClass('is-collapsed');
}

$('#toggle-work-controls, #toggle-floating-controls').on('click', function(){
  toggleWorkControls();
});

$('#toggle-maximize').on('click', function(){
  $('body').addClass('workspace-maximized');
});

$('#restore-workspace').on('click', function(){
  $('body').removeClass('workspace-maximized');
});

$('#cancel-start').on('click', function(e){
  e.preventDefault();
  $.post('api.php', {action: 'cancel_up', token: wosApiToken}, function(){
    notify('info', 'Demande annulée');
    routerSince = 0;
    updateAll();
  }, 'json');
});

// Initialiser tous les tooltips Bootstrap
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
  new bootstrap.Tooltip(el);
});
</script>
<script src="app.js"></script>
</body>
</html>
