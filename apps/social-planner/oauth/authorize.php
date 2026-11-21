<?php
declare(strict_types=1);
// OAuth: souhlas s připojením MCP klienta (Claude, ChatGPT). Vyžaduje přihlášení do administrace.
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/auth.php';
require __DIR__ . '/../app/lib/oauth.php';
oauth_log_request('authorize');
session_start_safe();
auth_check_idle();
$pdo = db();
oauth_tables($pdo);

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$p = fn(string $k): string => trim((string)($src[$k] ?? ''));
$clientId = $p('client_id'); $redirect = $p('redirect_uri'); $state = $p('state');
$client = $clientId !== '' ? oauth_client($pdo, $clientId) : null;

/** Chybová stránka bez přesměrování (neověřený klient nebo návratová adresa). */
function oauth_fail_page(string $msg): never {
    oauth_log_detail('CHYBA: ' . $msg);
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Chyba připojení</title><link rel="stylesheet" href="../assets/css/landing.css">'
       . '<body class="auth"><main class="wrap auth-main" style="grid-template-columns:1fr;max-width:560px"><section class="auth-card"><h2>Připojení nelze dokončit</h2><p class="auth-sub">' . h($msg) . '</p>'
       . '<p class="hint">Zkuste konektor v aplikaci (Claude, ChatGPT) odebrat a přidat znovu.</p></section></main></body>';
    exit;
}
function oauth_back(string $redirect, array $params): never {
    $params['iss'] = oauth_issuer();
    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($params));
    exit;
}

if (!$client) oauth_fail_page('Neznámý klient. Registrace mohla vypršet.');
if ($redirect === '' && count($client['redirect_uris']) === 1) $redirect = $client['redirect_uris'][0];
if (!in_array($redirect, $client['redirect_uris'], true)) oauth_fail_page('Návratová adresa neodpovídá registraci klienta.');
$err = fn(string $e, string $d) => oauth_back($redirect, array_filter(['error' => $e, 'error_description' => $d, 'state' => $state], fn($v) => $v !== ''));
if ($p('response_type') !== 'code') $err('unsupported_response_type', 'Podporováno je jen response_type=code.');
$challenge = $p('code_challenge');
if ($challenge === '' || $p('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) $err('invalid_request', 'Vyžaduje se PKCE (code_challenge_method=S256).');
$scope = oauth_scope($p('scope'));

if (empty($_SESSION['auth'])) {
    oauth_log_detail('nepřihlášen → přihlášení');
    header('Location: ../login.php?next=' . rawurlencode('oauth/authorize.php?' . http_build_query($_GET)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($src['decision'] ?? '') !== 'allow') { oauth_log_detail('zamítnuto uživatelem'); log_line("OAUTH souhlas zamítnut pro „{$client['name']}“"); $err('access_denied', 'Uživatel přístup nepovolil.'); }
    if (empty($src['allow_drafts'])) $scope = array_values(array_diff($scope, ['drafts']));
    $code = oauth_issue_code($pdo, $client, $redirect, $challenge, $scope);
    oauth_log_detail('POVOLENO → ' . parse_url($redirect, PHP_URL_HOST) . ' (' . implode(' ', $scope) . ')');
    oauth_back($redirect, array_filter(['code' => $code, 'state' => $state], fn($v) => $v !== ''));
}

$host = (string)parse_url($redirect, PHP_URL_HOST);
oauth_log_detail("souhlas zobrazen: „{$client['name']}“ → $host");
$known = ['claude.ai' => 'Claude', 'claude.com' => 'Claude', 'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'localhost' => 'aplikace v tomto počítači', '127.0.0.1' => 'aplikace v tomto počítači'];
$who = $known[$host] ?? null;
$v = fn(string $f): int => (int)@filemtime(__DIR__ . '/../' . $f);
?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Povolit přístup | Social Planner</title>
  <link rel="stylesheet" href="../assets/css/landing.css?v=<?= $v('assets/css/landing.css') ?>">
  <link rel="icon" href="../assets/img/logo.svg" type="image/svg+xml">
</head>
<body class="auth">
<header class="topbar">
  <div class="wrap topbar-in">
    <span class="brand"><img src="../assets/img/logo.svg" alt="" width="44" height="44"><span>Social Planner</span></span>
  </div>
</header>
<main class="wrap auth-main consent-main">
  <section class="auth-card consent">
    <span class="auth-icon"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>
    <h2>Povolit přístup?</h2>
    <p class="auth-sub"><strong><?= h($client['name']) ?></strong><?= $who ? ' (' . h($who) . ')' : '' ?> chce přistupovat k vašemu Social Planneru.</p>
    <div class="consent-host">Po povolení se vrátíte na <b><?= h($host) ?></b><?= $who ? '' : '. <span class="warn">Tuto adresu neznáme – povolte jen, pokud jste připojení právě sami spustili.</span>' ?></div>

    <form method="post" class="auth-form">
      <?php foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $k): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= h($p($k) !== '' ? $p($k) : ($k === 'redirect_uri' ? $redirect : '')) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <ul class="consent-list">
        <li><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i><span><?= h(OAUTH_SCOPES['read']) ?></span></li>
        <?php if (in_array('drafts', $scope, true)): ?>
        <li><label class="consent-check"><input type="checkbox" name="allow_drafts" value="1" checked><span><?= h(OAUTH_SCOPES['drafts']) ?></span></label></li>
        <?php endif; ?>
        <li class="no"><i class="x">✕</i><span>Zveřejňovat, plánovat, upravovat ani mazat příspěvky nemůže.</span></li>
      </ul>
      <button type="submit" name="decision" value="allow" class="btn btn-primary btn-lg btn-block">Povolit přístup</button>
      <button type="submit" name="decision" value="deny" class="btn btn-ghost btn-lg btn-block" formnovalidate>Zamítnout</button>
    </form>
    <p class="hint consent-foot">Přístup můžete kdykoli odebrat v administraci: Nastavení → Integrace (API).</p>
  </section>
</main>
</body>
</html>
