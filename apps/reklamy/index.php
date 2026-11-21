<?php
declare(strict_types=1);
// Administrace TWB Reklamy: přihlášení a stránky (?p=…).
use Reklamy\{App, Ui};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { header('Location: install.php'); exit; }
$app->migruj();
$relace = $app->relace();
$ui = new Ui($app);
$p = (string)($_GET['p'] ?? 'prehled');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$relace->jePrihlasen($app->limitNecinnosti())) {
    require __DIR__ . '/stranky/prihlaseni.php';
    exit;
}

if ($post && !$relace->csrfOver($_POST['csrf'] ?? null)) {
    $ui->zprava('chyba', 'Formulář vypršel, zkuste to prosím znovu.');
    $ui->presmeruj($_SERVER['REQUEST_URI'] ?? 'index.php');
}

if ($p === 'odhlasit') {
    if ($post) { $relace->odhlas(); $app->log('info', 'Odhlášení.'); }
    $ui->presmeruj('index.php');
}

$stranky = ['prehled', 'kampane', 'kampan', 'bannery', 'banner', 'weby', 'web', 'zona', 'galerie', 'promenne', 'konverze', 'tracker', 'statistiky', 'nastaveni', 'protokol'];
if (!in_array($p, $stranky, true)) { http_response_code(404); $p = 'prehled'; }
$menu = ['kampan' => 'kampane', 'banner' => 'bannery', 'web' => 'weby', 'zona' => 'weby', 'tracker' => 'konverze'][$p] ?? $p;
$titulek = Ui::MENU[$p] ?? '';
ob_start();
try {
    require __DIR__ . "/stranky/$p.php";
} catch (Reklamy\Chyba $e) {
    // nenalezený záznam apod.
    ob_clean();
    http_response_code(404);
    $titulek = 'Nenalezeno';
    echo '<p>' . Ui::h($e->getMessage()) . '</p><p><a href="' . Ui::h(Ui::odkaz($menu)) . '">Zpět</a></p>';
}
$obsah = (string)ob_get_clean();
$ui->stranka($titulek, $menu, $obsah);
