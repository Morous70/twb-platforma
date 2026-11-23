<?php
declare(strict_types=1);
// Administrace TWB Media galerie: přihlášení a stránky (?p=…).
use MediaGalerie\{App, Ui};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { header('Location: install.php'); exit; }
$app->migruj();
// veřejný web (0.8.0): ?s=… bez ?p= vede na verejne.php (záloha, kdyby hosting nepoužil DirectoryIndex z .htaccess)
if (isset($_GET['s']) && !isset($_GET['p']) && $_SERVER['REQUEST_METHOD'] !== 'POST') { require __DIR__ . '/verejne.php'; exit; }
$relace = $app->relace();
$ui = new Ui($app);
$p = (string)($_GET['p'] ?? 'prehled');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$relace->jePrihlasen($app->limitNecinnosti())) {
    require __DIR__ . '/stranky/prihlaseni.php';
    exit;
}

// po přihlášení zpět na souhlas s připojením AI (oauth.php?akce=authorize)
if (!$post && is_string($_SESSION['po_prihlaseni'] ?? null)) {
    $kam = $_SESSION['po_prihlaseni'];
    unset($_SESSION['po_prihlaseni']);
    if (str_starts_with($kam, 'oauth.php?')) $ui->presmeruj($kam);
}

if ($post && !$relace->csrfOver($_POST['csrf'] ?? null)) {
    $ui->zprava('chyba', 'Formulář vypršel, zkuste to prosím znovu.');
    $ui->presmeruj($_SERVER['REQUEST_URI'] ?? 'index.php');
}

if ($p === 'odhlasit') {
    if ($post) { $relace->odhlas(); $app->log('info', 'Odhlášení.'); }
    $ui->presmeruj('index.php');
}

$stranky = ['prehled', 'galerie', 'medium', 'komprese', 'kategorie', 'web', 'api', 'napoveda', 'nastaveni', 'protokol'];
if (!in_array($p, $stranky, true)) { http_response_code(404); $p = 'prehled'; }
$menu = ['protokol' => 'nastaveni', 'medium' => 'galerie', 'komprese' => 'galerie'][$p] ?? $p;
$titulek = Ui::MENU[$p] ?? ['protokol' => 'Protokol', 'komprese' => 'Komprimovat obrázky'][$p] ?? '';
$kontext = $p;   // stránky smějí $p přepsat
ob_start();
try {
    require __DIR__ . "/stranky/$p.php";
} catch (MediaGalerie\Chyba $e) {
    // nenalezený záznam apod.
    ob_clean();
    http_response_code(404);
    $titulek = 'Nenalezeno';
    echo '<p>' . Ui::h($e->getMessage()) . '</p><p><a href="' . Ui::h(Ui::odkaz($menu)) . '">Zpět</a></p>';
}
$obsah = (string)ob_get_clean();
$ui->stranka($titulek, $menu, $obsah, $kontext);
