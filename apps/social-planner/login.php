<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/auth.php';
session_start_safe();

$pdo = db();
auth_check_idle();
$err = (string)($_SESSION['login_error'] ?? ''); unset($_SESSION['login_error']);
$info = '';
$mode = isset($_GET['forgot']) ? 'forgot' : (isset($_GET['reset']) ? 'reset' : 'login');
$resetTok = (string)($_GET['reset'] ?? '');

// Návrat po přihlášení (jen na souhlas s připojením MCP klienta), jinak Přehled
$next = (string)($_GET['next'] ?? '');
$dest = preg_match('#^oauth/authorize\.php\?[A-Za-z0-9._~%&=+\-]*$#', $next) ? $next : 'prehled.php';
if (!empty($_SESSION['auth'])) { header('Location: ' . $dest); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lock = auth_lock_remaining();
    if ($lock) {
        $err = 'Příliš mnoho pokusů, zkuste to za ' . (int)ceil($lock / 60) . ' min.';
    } elseif ($mode === 'forgot') {
        auth_send_reset($pdo, (string)($_POST['email'] ?? ''));
        $info = 'Pokud adresa souhlasí s nastaveným e-mailem pro oznámení, poslali jsme na ni odkaz. Platí 30 minut.';
    } elseif ($mode === 'reset') {
        $p1 = (string)($_POST['password'] ?? ''); $p2 = (string)($_POST['password2'] ?? '');
        if (mb_strlen($p1) < 8) $err = 'Heslo musí mít alespoň 8 znaků.';
        elseif ($p1 !== $p2) $err = 'Hesla se neshodují.';
        elseif (!auth_token_use($pdo, 'reset', $resetTok)) { auth_fail('neplatný odkaz pro obnovu'); $err = 'Odkaz je neplatný nebo vypršel.'; }
        else {
            meta_set('password_hash', password_hash($p1, PASSWORD_DEFAULT));
            log_line('HESLO obnoveno odkazem z e-mailu');
            auth_login('heslo (po obnově)');
            header('Location: ' . $dest); exit;
        }
    } elseif (auth_password_hash() && password_verify((string)($_POST['password'] ?? ''), auth_password_hash())) {
        auth_login('heslo');
        header('Location: ' . $dest);
        exit;
    } else {
        auth_fail('heslo');
        $err = 'Špatné heslo.';
    }
}
$hasPasskey = (int)$pdo->query("SELECT COUNT(*) FROM passkeys")->fetchColumn() > 0;
require_once __DIR__ . '/app/lib/landing.php';
$landingOn = landing_settings($pdo)['enabled'];
$v = fn(string $f): int => (int)@filemtime(__DIR__ . '/' . $f);
$titles = ['login' => ['Přihlášení', $dest !== 'prehled.php' ? 'Přihlaste se, abyste mohli povolit připojení aplikace.' : 'Zadejte heslo do administrace.'], 'forgot' => ['Zapomenuté heslo', 'Pošleme vám odkaz pro nastavení nového hesla.'], 'reset' => ['Nové heslo', 'Zvolte nové heslo, alespoň 8 znaků.']];
[$title, $sub] = $titles[$mode];
?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= h($title) ?> | Social Planner</title>
  <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/css/landing.css?v=<?= $v('assets/css/landing.css') ?>">
  <link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
</head>
<body class="auth">
<header class="topbar">
  <div class="wrap topbar-in">
    <a class="brand" href="<?= $landingOn ? 'index.php' : 'login.php' ?>" aria-label="Social Planner">
      <img src="assets/img/logo.svg" alt="" width="44" height="44">
      <span>Social Planner</span>
    </a>
    <?php if ($landingOn): ?>
    <a class="back-link" href="index.php"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6"/></svg> <span>Zpět<span class="hide-xs"> na úvodní stránku</span></span></a>
    <?php endif; ?>
  </div>
</header>

<main class="wrap auth-main">
  <section class="auth-side" aria-hidden="true">
    <span class="eyebrow">Administrace</span>
    <h1>Vítejte <span class="underline">zpět</span>.</h1>
    <p class="lead">Vaše příspěvky, kalendář a statistiky na jednom místě.</p>
    <ul class="auth-points">
      <li><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Facebook stránky, Instagram a Threads</li>
      <li><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Kalendář, opakování a hromadné plánování</li>
      <li><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Statistiky a doporučený čas zveřejnění</li>
    </ul>
    <div class="auth-blob"></div>
  </section>

  <section class="auth-card">
    <span class="auth-icon"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14.5v2.5"/></svg></span>
    <h2><?= h($title) ?></h2>
    <p class="auth-sub"><?= h($sub) ?></p>

    <?php if ($err): ?><div class="note note-err" role="alert"><?= h($err) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="note note-ok" role="status"><?= h($info) ?></div><?php endif; ?>

    <?php if ($mode === 'forgot'): ?>
    <form method="post" class="auth-form">
      <label class="field"><span>E-mail pro oznámení</span><input type="email" name="email" autocomplete="email" autofocus required></label>
      <p class="hint">Odkaz pošleme jen na e-mail nastavený v Oznámeních. Platí 30 minut.</p>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Poslat odkaz</button>
      <a class="auth-alt" href="login.php">Zpět na přihlášení</a>
    </form>
    <?php elseif ($mode === 'reset'): ?>
    <form method="post" class="auth-form">
      <label class="field"><span>Nové heslo</span><input type="password" name="password" minlength="8" autocomplete="new-password" autofocus required></label>
      <label class="field"><span>Nové heslo znovu</span><input type="password" name="password2" minlength="8" autocomplete="new-password" required></label>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Nastavit heslo</button>
    </form>
    <?php else: ?>
    <form method="post" class="auth-form">
      <label class="field"><span>Heslo</span>
        <span class="pw"><input id="password" type="password" name="password" placeholder="Zadejte heslo" autocomplete="current-password" autofocus required>
        <button type="button" class="pw-toggle" id="pwToggle" aria-label="Zobrazit heslo" title="Zobrazit heslo"><svg class="ic" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button></span>
      </label>
      <button type="submit" class="btn btn-primary btn-lg btn-block">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg> Přihlásit se
      </button>
    </form>
    <div id="pkBox" hidden>
      <div class="or"><span>nebo</span></div>
      <button type="button" class="btn btn-ghost btn-lg btn-block" id="pkBtn">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3M17 6l3 3M14.5 8.5l2 2"/></svg> Přihlásit pomocí Passkey
      </button>
    </div>
    <a class="auth-alt" href="login.php?forgot">Zapomenuté heslo</a>
    <?php endif; ?>
  </section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <span><b>Social Planner</b> • Soukromá aplikace <span class="ver">· verze <?= h(APP_VERSION) ?></span></span>
  </div>
</footer>
<script>
(function () {
  const t = document.getElementById('pwToggle'), i = document.getElementById('password');
  if (t && i) t.onclick = () => { const show = i.type === 'password'; i.type = show ? 'text' : 'password'; t.setAttribute('aria-label', show ? 'Skrýt heslo' : 'Zobrazit heslo'); t.classList.toggle('on', show); i.focus(); };
})();
</script>
<?php if ($hasPasskey && $mode === 'login'): ?>
<script src="assets/js/webauthn.js?v=<?= $v('assets/js/webauthn.js') ?>"></script>
<script>
if (WA.supported()) {
  const box = document.getElementById('pkBox'); box.hidden = false;
  document.getElementById('pkBtn').onclick = async () => {
    try { await WA.login(); location.href = <?= json_encode($dest, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>; }
    catch (e) { alert(WA.msg(e)); }
  };
}
</script>
<?php endif; ?>
</body>
</html>
