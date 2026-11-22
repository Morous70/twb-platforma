<?php
declare(strict_types=1);
// OAuth: souhlas s připojením MCP klienta (Claude, ChatGPT). Volá oauth.php?akce=authorize; proměnné: $app, $oauth.
// Nepřihlášeného pošle na přihlášení a po něm zpět sem (index.php, $_SESSION['po_prihlaseni']).
use MediaGalerie\{Ui, Mcp};
use TWB\Mcp\ChybaOAuth;

$relace = $app->relace();
$ui = new Ui($app);
$post = $metoda === 'POST';
$zdroj = $post ? $_POST : $_GET;
$stav = is_string($zdroj['state'] ?? null) ? (string)$zdroj['state'] : '';

try {
    [$klient, $navrat, $vyzva, $rozsah] = $oauth->overPozadavek($zdroj);
} catch (ChybaOAuth $e) {
    if ($e->http !== 302) {
        // neověřený klient nebo návratová adresa: nepřesměrovat, jen ukázat chybu
        http_response_code(400);
        $app->log('warn', 'OAuth: připojení nelze dokončit – ' . $e->getMessage());
        $ui->samostatna('Připojení nelze dokončit', '<p>' . Ui::h($e->getMessage()) . '</p><p class="mala">Zkuste konektor v aplikaci (Claude, ChatGPT) odebrat a přidat znovu.</p>');
        return;
    }
    $navrat = (string)($oauth->klient((string)($zdroj['client_id'] ?? ''))['redirect_uris'][0] ?? '');
    $ui->presmeruj($oauth->navrat(is_string($zdroj['redirect_uri'] ?? null) && $zdroj['redirect_uri'] !== '' ? (string)$zdroj['redirect_uri'] : $navrat,
        ['error' => $e->kod, 'error_description' => $e->getMessage(), 'state' => $stav]));
}

if (!$relace->jePrihlasen($app->limitNecinnosti())) {
    if (!$post) $_SESSION['po_prihlaseni'] = 'oauth.php?' . http_build_query($_GET);
    $ui->presmeruj('index.php');
}

if ($post) {
    if (!$relace->csrfOver($_POST['csrf'] ?? null)) {
        $ui->zprava('chyba', 'Formulář vypršel, zkuste to prosím znovu.');
        $ui->presmeruj('oauth.php?' . http_build_query(array_intersect_key($_POST, array_flip(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'])) + ['akce' => 'authorize']));
    }
    if (($_POST['rozhodnuti'] ?? '') !== 'povolit') {
        $app->log('info', "OAuth: připojení „{$klient['name']}“ zamítnuto.");
        $ui->presmeruj($oauth->navrat($navrat, ['error' => 'access_denied', 'error_description' => 'Uživatel přístup nepovolil.', 'state' => $stav]));
    }
    if (empty($_POST['povolit_zapis'])) $rozsah = array_values(array_diff($rozsah, ['write']));
    $kod = $oauth->vydejKod($klient, $navrat, $vyzva, $rozsah);
    $ui->presmeruj($oauth->navrat($navrat, ['code' => $kod, 'state' => $stav]));
}

$host = (string)parse_url($navrat, PHP_URL_HOST);
$zname = ['claude.ai' => 'Claude', 'claude.com' => 'Claude', 'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'localhost' => 'aplikace v tomto počítači', '127.0.0.1' => 'aplikace v tomto počítači'];
$kdo = $zname[$host] ?? null;
$kdoText = $kdo !== null && mb_strtolower($kdo) !== mb_strtolower((string)$klient['name']) ? ' (' . Ui::h($kdo) . ')' : '';
ob_start();
?>
<p><strong><?= Ui::h($klient['name']) ?></strong><?= $kdoText ?> chce přistupovat k vaší galerii.</p>
<p class="mala">Po povolení se vrátíte na <b><?= Ui::h($host) ?></b>.<?php if (!$kdo): ?> <span class="hlaska-chyba">Tuto adresu neznáme – povolte jen, pokud jste připojení právě sami spustili.</span><?php endif; ?></p>
<form method="post" action="oauth.php?akce=authorize" class="formular">
  <?= $ui->csrf() ?>
  <?php foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $k): ?>
  <input type="hidden" name="<?= $k ?>" value="<?= Ui::h($k === 'redirect_uri' ? $navrat : (is_string($zdroj[$k] ?? null) ? (string)$zdroj[$k] : '')) ?>">
  <?php endforeach; ?>
  <ul class="mala" style="padding-left:1.2em">
    <li><?= Ui::h(Mcp::ROZSAHY['read']) ?></li>
    <?php if (in_array('write', $rozsah, true)): ?>
    <li><label><input type="checkbox" name="povolit_zapis" value="1" checked> <?= Ui::h(Mcp::ROZSAHY['write']) ?></label></li>
    <?php endif; ?>
    <li>Mazat média ani měnit nastavení nemůže.</li>
  </ul>
  <button type="submit" name="rozhodnuti" value="povolit" class="hlavni">Povolit přístup</button>
  <button type="submit" name="rozhodnuti" value="zamitnout" formnovalidate>Zamítnout</button>
</form>
<p class="mala">Přístup můžete kdykoli odebrat v administraci na stránce API a MCP.</p>
<?php
$ui->samostatna('Povolit přístup?', (string)ob_get_clean());
