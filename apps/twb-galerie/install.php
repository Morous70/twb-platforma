<?php
declare(strict_types=1);
// První spuštění: vytvoří config.php (když chybí), databázi a heslo správce.
use MediaGalerie\{App, Ui, Chyba};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
$ui = new Ui($app);
if ($app->nainstalovano()) { header('Location: index.php'); exit; }

$problemy = [];
if (version_compare(PHP_VERSION, '8.1.0', '<')) $problemy[] = 'Aplikace potřebuje PHP 8.1 nebo novější (server má ' . PHP_VERSION . ').';
foreach (['pdo_sqlite' => 'SQLite (pdo_sqlite)', 'gd' => 'GD (obrázky)', 'mbstring' => 'mbstring', 'zip' => 'zip (zálohy)', 'openssl' => 'openssl (Passkey)'] as $ext => $nazev) {
    if (!extension_loaded($ext)) $problemy[] = "Chybí rozšíření PHP: $nazev.";
}
if (!is_writable($app->data)) $problemy[] = 'Do složky ' . $app->data . ' nejde zapisovat.';

$relace = $app->relace();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$problemy) {
    $ui->zpracuj(function () use ($app, $relace) {
        if (!$relace->csrfOver($_POST['csrf'] ?? null)) throw new Chyba('Formulář vypršel, zkuste to prosím znovu.');
        $heslo = (string)($_POST['heslo'] ?? '');
        if ($heslo !== (string)($_POST['heslo2'] ?? '')) throw new Chyba('Hesla se neshodují.');
        $soubor = (string)$app->cfg('__soubor');
        if (!is_file($soubor)) {
            $url = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
            if (!preg_match('~^https?://[^\s]+$~', $url)) throw new Chyba('Zadejte adresu aplikace začínající https://.');
            $vzor = (string)file_get_contents(__DIR__ . '/config.sample.php');
            $vzor = str_replace(["'https://galerie.kazbo.eu'", "'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC'"], [var_export($url, true), var_export(bin2hex(random_bytes(20)), true)], $vzor);
            if (@file_put_contents($soubor, $vzor) === false) throw new Chyba('Nepodařilo se vytvořit config.php. Zkopírujte config.sample.php jako config.php přes FTP a vyplňte base_url.');
            @chmod($soubor, 0640);
        }
        $app->migruj();
        $email = trim((string)($_POST['reset_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new Chyba('Zadejte platný e-mail pro obnovu hesla, nebo pole nechte prázdné.');
        $app->heslo()->nastav($heslo);
        if ($email !== '') $app->nastaveni()->uloz('reset_email', $email);
        $app->log('info', 'Aplikace nainstalována (verze ' . $app->verze . ').');
        $relace->prihlas('instalace');
        header('Location: index.php', true, 303);
        exit;
    });
}
ob_start();
if ($problemy): ?>
  <div class="hlaska hlaska-chyba"><?= implode('<br>', array_map([Ui::class, 'h'], $problemy)) ?></div>
<?php else: ?>
<p>Nastavte heslo správce. Další způsoby přihlášení (Google, Passkey) přidáte potom v Nastavení.</p>
<form method="post" class="formular">
  <?= $ui->csrf() ?>
  <?php if (!is_file((string)$app->cfg('__soubor'))): ?>
  <?= Ui::pole('Adresa aplikace', 'base_url', $app->url(), ['required' => true], 'Veřejná adresa bez lomítka na konci, např. https://galerie.kazbo.eu. Uloží se do nového config.php.') ?>
  <?php else: ?>
  <p class="slabe mala">Adresa aplikace (z config.php): <?= Ui::h($app->url()) ?></p>
  <?php endif; ?>
  <?= Ui::pole('Heslo', 'heslo', '', ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password'], 'Aspoň 8 znaků.') ?>
  <?= Ui::pole('Heslo znovu', 'heslo2', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
  <?= Ui::pole('E-mail pro obnovu hesla', 'reset_email', '', ['type' => 'email', 'autocomplete' => 'email'], 'Na tuto adresu přijde odkaz, když heslo zapomenete. Jde doplnit i později v Nastavení.') ?>
  <button type="submit" class="hlavni">Nainstalovat</button>
</form>
<?php endif;
$ui->samostatna('Instalace', (string)ob_get_clean());
