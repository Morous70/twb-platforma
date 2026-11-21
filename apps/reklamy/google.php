<?php
declare(strict_types=1);
// Přihlášení přes Google (OpenID Connect). Bez parametrů přesměruje na Google, s ?code= dokončí přihlášení.
// S ?propojit=1 (jen přihlášený, POST s CSRF) propojí Google účet, kterým se bude smět přihlašovat.
use Reklamy\App;

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { header('Location: install.php'); exit; }
$app->migruj();
$relace = $app->relace();
$n = $app->nastaveni();
$google = $app->google();

$navrat = isset($_GET['code']) || isset($_GET['error']);
$propojit = $navrat ? !empty($_SESSION['gl_link']) : isset($_GET['propojit']);
$konec = function (string $zprava, bool $chyba = true) use ($propojit, $relace): never {
    unset($_SESSION['gl_state'], $_SESSION['gl_nonce'], $_SESSION['gl_link']);
    if ($propojit) {
        $_SESSION['flash'][] = [$chyba ? 'chyba' : 'ok', $zprava];
        header('Location: index.php?p=nastaveni#zabezpeceni', true, 303);
    } else {
        if ($chyba) $_SESSION['login_error'] = $zprava;
        header('Location: index.php', true, 303);
    }
    exit;
};

if ($propojit && !$relace->jePrihlasen($app->limitNecinnosti())) { header('Location: index.php'); exit; }
if (!$google->nastaveno()) $konec('V config.php chybí google_client_id a google_client_secret.');

if ($navrat) {
    $state = (string)($_SESSION['gl_state'] ?? '');
    $nonce = (string)($_SESSION['gl_nonce'] ?? '');
    if (!$propojit && $app->zamek()->zbyva() > 0) $konec($app->zamek()->zprava());
    if (isset($_GET['error']) || $state === '' || !hash_equals($state, (string)($_GET['state'] ?? ''))) {
        if (!$propojit) $app->zamek()->neuspech('Google');
        $konec('Přihlášení přes Google se nezdařilo.');
    }
    try {
        $c = $google->vymenKod((string)$_GET['code'], $nonce);
    } catch (Throwable $e) {
        $app->log('error', 'Přihlášení přes Google selhalo: ' . $e->getMessage());
        if (!$propojit) $app->zamek()->neuspech('Google');
        $konec('Přihlášení přes Google se nezdařilo.');
    }
    if ($propojit) {
        $n->uloz('login_google_sub', $c['sub']);
        $n->uloz('login_google_email', $c['email']);
        $app->log('info', "Google účet pro přihlášení propojen ({$c['email']}).");
        $konec("Google účet {$c['email']} je propojený.", false);
    }
    $sub = (string)$n->dej('login_google_sub', '');
    if ($sub !== '' && hash_equals($sub, $c['sub'])) {
        unset($_SESSION['gl_state'], $_SESSION['gl_nonce'], $_SESSION['gl_link']);
        $app->zamek()->vynuluj();
        $relace->prihlas('Google (' . $c['email'] . ')');
        header('Location: index.php', true, 303);
        exit;
    }
    $app->zamek()->neuspech('Google');
    $app->log('warn', "Pokus o přihlášení přes Google účtem, který není povolený ({$c['email']}).");
    $konec('Tento Google účet se nesmí přihlásit.');
}

if ($propojit && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$relace->csrfOver($_POST['csrf'] ?? null))) $konec('Formulář vypršel, zkuste to prosím znovu.');
if (!$propojit && (string)$n->dej('login_google_sub', '') === '') $konec('Přihlášení přes Google není nastavené.');
$_SESSION['gl_state'] = bin2hex(random_bytes(16));
$_SESSION['gl_nonce'] = bin2hex(random_bytes(16));
$_SESSION['gl_link'] = $propojit ? 1 : 0;
header('Location: ' . $google->adresaPrihlaseni($_SESSION['gl_state'], $_SESSION['gl_nonce']), true, 303);
