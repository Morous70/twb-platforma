<?php
declare(strict_types=1);
// Passkey (WebAuthn): JSON rozhraní pro assets/app.js. Akce: moznosti-prihlaseni, prihlasit, moznosti-registrace, registrovat.
use MediaGalerie\App;

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$odpoved = function (array $data, int $kod = 200): never {
    http_response_code($kod);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if (!$app->nainstalovano()) $odpoved(['ok' => false, 'error' => 'Aplikace není nainstalovaná.'], 400);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $odpoved(['ok' => false, 'error' => 'Jen POST.'], 405);
$app->migruj();
$relace = $app->relace();
$pk = $app->passkey();
$vstup = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!$relace->csrfOver($vstup['csrf'] ?? null)) $odpoved(['ok' => false, 'error' => 'Stránka vypršela, načtěte ji znovu.'], 403);
if (!$pk->dostupne()) $odpoved(['ok' => false, 'error' => 'Passkey funguje jen přes HTTPS.'], 400);
$akce = (string)($_GET['akce'] ?? '');

try {
    switch ($akce) {
        case 'moznosti-prihlaseni':
            if ($app->zamek()->zbyva() > 0) $odpoved(['ok' => false, 'error' => $app->zamek()->zprava()], 429);
            $odpoved(['ok' => true] + $pk->moznostiPrihlaseni());
        case 'prihlasit':
            if ($app->zamek()->zbyva() > 0) $odpoved(['ok' => false, 'error' => $app->zamek()->zprava()], 429);
            try {
                $row = $pk->overPrihlaseni((array)($vstup['credential'] ?? []));
            } catch (RuntimeException $e) {
                $app->zamek()->neuspech('Passkey');
                $app->log('warn', 'Přihlášení Passkey se nezdařilo: ' . $e->getMessage());
                $odpoved(['ok' => false, 'error' => $e->getMessage()], 401);
            }
            $app->zamek()->vynuluj();
            $relace->prihlas('Passkey (' . $row['name'] . ')');
            $odpoved(['ok' => true]);
        case 'moznosti-registrace':
            if (!$relace->jePrihlasen($app->limitNecinnosti())) $odpoved(['ok' => false, 'error' => 'Nejste přihlášeni.'], 401);
            $odpoved(['ok' => true] + $pk->moznostiRegistrace());
        case 'registrovat':
            if (!$relace->jePrihlasen($app->limitNecinnosti())) $odpoved(['ok' => false, 'error' => 'Nejste přihlášeni.'], 401);
            $id = $pk->registruj((array)($vstup['credential'] ?? []), (string)($vstup['nazev'] ?? ''));
            $app->log('info', 'Přidáno zařízení Passkey: ' . trim((string)($vstup['nazev'] ?? '')));
            $odpoved(['ok' => true, 'id' => $id]);
        default:
            $odpoved(['ok' => false, 'error' => 'Neznámá akce.'], 400);
    }
} catch (RuntimeException $e) {
    $odpoved(['ok' => false, 'error' => $e->getMessage()], 400);
}
