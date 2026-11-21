<?php
require __DIR__ . '/lib/bootstrap.php';

if (!installed()) { header('Location: install.php'); exit; }
require_once __DIR__ . '/lib/schema.php';
migrate();
start_session();

if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }

require_once __DIR__ . '/lib/auth.php';

is_logged_in();   // případné odhlášení pro nečinnost nastaví hlášku v relaci
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
$notice = null;
$view = 'login';                       // login | forgot | reset
$resetToken = (string)($_GET['reset'] ?? $_POST['reset'] ?? '');
if (isset($_GET['forgot'])) $view = 'forgot';
if ($resetToken !== '') $view = 'reset';

if (!is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? 'login');
    if ($do === 'forgot') {
        $view = 'forgot';
        auth_reset_request((string)($_POST['email'] ?? ''));
        $notice = 'Pokud adresa souhlasí s adresou pro upozornění, poslali jsme na ni odkaz pro nastavení nového hesla. Platí 30 minut.';
        $view = 'login';
    } elseif ($do === 'reset') {
        if (auth_lock_remaining() > 0) { $error = auth_lock_message(); }
        else {
            try {
                if ((string)($_POST['new'] ?? '') !== (string)($_POST['new2'] ?? '')) throw new RuntimeException('Hesla se neshodují.');
                auth_reset_apply($resetToken, (string)($_POST['new'] ?? ''));
                $notice = 'Heslo je změněné. Přihlaste se novým heslem.';
                $view = 'login';
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                if (!auth_token_valid('reset', $resetToken)) { auth_register_fail('odkaz pro obnovu hesla'); $view = 'login'; }
            }
        }
    } else {
        // jednoduchá ochrana proti hádání hesla
        if (auth_lock_remaining() > 0) {
            $error = auth_lock_message();
        } elseif (password_verify($_POST['password'] ?? '', (string)setting('password_hash'))) {
            auth_login('heslo');
            header('Location: ./');
            exit;
        } else {
            auth_register_fail('heslo');
            $error = 'Nesprávné heslo.';
        }
    }
}
if ($view === 'reset' && !$error && !auth_token_valid('reset', $resetToken)) {
    $error = 'Odkaz pro obnovu hesla už neplatí. Požádejte o nový.';
    $view = 'forgot';
}
$googleLogin = (string)setting('login_google_sub', '') !== '' && (auth_google_configured() || cfg('drive_mock'));
$hasPasskey = wa_available() && (int)q('SELECT COUNT(*) FROM passkeys')->fetchColumn() > 0;

if (!is_logged_in()): ?><!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php page_head('Přihlášení – ' . APP_NAME); ?>
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>"></head>
<body class="center-page">
<div class="panel login">
  <div class="brand"><img class="logo-img" src="assets/icons/icon.svg" alt=""> <?= h(APP_NAME) ?></div>
  <?php if ($error): ?><div class="errbox"><?= h($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="okbox"><?= h($notice) ?></div><?php endif; ?>
<?php if ($view === 'forgot'): ?>
  <form method="post" class="stack">
    <input type="hidden" name="do" value="forgot">
    <h2 style="margin:0;font-size:17px">Zapomenuté heslo</h2>
    <p class="muted small" style="margin:0">Zadejte e-mail pro upozornění z Nastavení. Pošleme na něj odkaz pro nastavení nového hesla.</p>
    <label class="field">E-mail<input type="email" name="email" required autofocus autocomplete="email"></label>
    <button class="btn primary">Poslat odkaz</button>
  </form>
  <a href="./" class="small" style="text-align:center">← Zpět na přihlášení</a>
<?php elseif ($view === 'reset'): ?>
  <form method="post" class="stack">
    <input type="hidden" name="do" value="reset">
    <input type="hidden" name="reset" value="<?= h($resetToken) ?>">
    <h2 style="margin:0;font-size:17px">Nové heslo</h2>
    <label class="field">Nové heslo <span class="hint">aspoň 8 znaků</span><input type="password" name="new" required autofocus minlength="8" autocomplete="new-password"></label>
    <label class="field">Nové heslo znovu<input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn primary">Nastavit heslo</button>
  </form>
<?php else: ?>
  <form method="post" class="stack">
    <input type="hidden" name="do" value="login">
    <label class="field">Heslo<input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button class="btn primary">Přihlásit</button>
  </form>
  <?php if ($googleLogin || $hasPasskey): ?>
  <div class="login-or"><span>nebo</span></div>
  <?php if ($hasPasskey): ?><button type="button" class="btn" id="pkLogin" hidden>🔑 Přihlásit pomocí Passkey</button><?php endif; ?>
  <?php if ($googleLogin): ?><a class="btn" id="gLogin" href="login-google.php">Přihlásit přes Google</a><?php endif; ?>
  <?php endif; ?>
  <a href="./?forgot" class="small" style="text-align:center">Zapomenuté heslo</a>
<?php endif; ?>
  <div class="muted small" style="text-align:center">verze <?= h(APP_VERSION) ?></div>
</div>
<?php if ($hasPasskey && $view === 'login'): ?><script src="assets/webauthn.js?v=<?= filemtime(__DIR__ . '/assets/webauthn.js') ?>"></script>
<script src="assets/login.js?v=<?= filemtime(__DIR__ . '/assets/login.js') ?>"></script><?php endif; ?>
</body></html>
<?php exit; endif; ?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h(csrf_token()) ?>">
  <meta name="idle" content="<?= (int)idle_limit_seconds() ?>">
<?php page_head(APP_NAME); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
  <header class="topbar" id="topbar">
    <button class="tb-menu" id="menuBtn" aria-label="Otevřít menu" aria-expanded="false" aria-controls="sidebar">☰</button>
    <a class="tb-brand" href="#prehled" title="Přehled"><img class="logo-img" src="assets/icons/icon.svg" alt=""></a>
    <span class="tb-title" id="tbTitle"></span>
  </header>
  <div class="side-backdrop" id="sideBackdrop"></div>
  <aside class="sidebar" id="sidebar">
    <button class="side-close" id="menuClose" aria-label="Zavřít menu">✕</button>
    <a class="brand" href="#prehled" title="Přehled"><img class="logo-img" src="assets/icons/icon.svg" alt="">
      <span class="brand-txt"><?= h(APP_NAME) ?><small class="ver">verze <?= h(APP_VERSION) ?></small></span></a>
    <nav>
      <a href="#prehled" data-view="prehled">Přehled</a>
      <a href="#scenare" data-view="scenare">Scénáře</a>
      <a href="#galerie" data-view="galerie">Galerie</a>
      <a href="#data" data-view="data">Data</a>
      <a href="#protokol" data-view="protokol">Protokol</a>
      <a href="#nastaveni" data-view="nastaveni">Nastavení</a>
      <a href="#napoveda" data-view="napoveda">Nápověda</a>
    </nav>
    <div class="side-foot">
      <div class="drive-pill" id="drivePill">…</div>
      <div class="seg theme-sw" id="themeSw" role="group" aria-label="Vzhled">
        <button data-theme-v="auto" title="Vzhled podle nastavení systému">Auto</button><button data-theme-v="light" title="Vždy světlý vzhled">Světlý</button><button data-theme-v="dark" title="Vždy tmavý vzhled">Tmavý</button>
      </div>
      <button class="btn sm" id="installBtn" hidden title="Nainstaluje TWB Plánovač médií jako aplikaci na plochu">⤓ Instalovat aplikaci</button>
      <a class="btn ghost sm" href="?logout=1">Odhlásit</a>
    </div>
  </aside>
  <div class="main-col">
    <div class="dev-alert" id="devAlert" hidden></div>
    <main id="main"></main>
  </div>
  <div class="modal" id="modal" hidden><div class="modal-box" id="modalBox"></div></div>
  <div class="toasts" id="toasts"></div>
  <script src="assets/idle.js?v=<?= filemtime(__DIR__ . '/assets/idle.js') ?>"></script>
  <script src="assets/webauthn.js?v=<?= filemtime(__DIR__ . '/assets/webauthn.js') ?>"></script>
  <script src="assets/help.js?v=<?= filemtime(__DIR__ . '/assets/help.js') ?>"></script>
  <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
  <script src="assets/slide-editor.js?v=<?= filemtime(__DIR__ . '/assets/slide-editor.js') ?>"></script>
  <script src="assets/html-editor.js?v=<?= filemtime(__DIR__ . '/assets/html-editor.js') ?>"></script>
</body>
</html>
