<?php
declare(strict_types=1);
// Přihlašovací stránka (heslo, Google, Passkey). Proměnné: $app, $ui, $relace, $post.
use Reklamy\Ui;

$zamek = $app->zamek();
if ($post && ($_POST['akce'] ?? '') === 'prihlasit') {
    if (!$relace->csrfOver($_POST['csrf'] ?? null)) {
        $ui->chyby[] = 'Formulář vypršel, zkuste to prosím znovu.';
    } elseif ($zamek->zbyva() > 0) {
        $ui->chyby[] = $zamek->zprava();
    } elseif ($app->heslo()->over((string)($_POST['heslo'] ?? ''))) {
        $zamek->vynuluj();
        $relace->prihlas('heslo');
        $ui->presmeruj('index.php' . (isset($_GET['p']) ? '?p=' . rawurlencode((string)$_GET['p']) : ''));
    } else {
        $zamek->neuspech('heslo');
        $ui->chyby[] = $zamek->zbyva() > 0 ? $zamek->zprava() : 'Nesprávné heslo.';
    }
}
if ($e = $relace->vezmiChybu()) $ui->chyby[] = $e;
$google = $app->google()->nastaveno() && (string)$app->nastaveni()->dej('login_google_sub', '') !== '';
$passkey = $app->passkey()->dostupne() && $app->passkey()->seznam() !== [];
http_response_code($post ? 401 : 200);
ob_start(); ?>
<form method="post" class="formular">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="prihlasit">
  <label class="pole"><span>Heslo</span><input type="password" name="heslo" autocomplete="current-password" required autofocus></label>
  <button type="submit" class="hlavni">Přihlásit</button>
</form>
<?php if ($google || $passkey): ?>
<div class="nebo"><span>nebo</span></div>
<div class="tlacitka-sloupec">
  <?php if ($passkey): ?><button type="button" id="passkey-prihlasit" data-csrf="<?= Ui::h($relace->csrfToken()) ?>">Přihlásit pomocí Passkey</button><?php endif; ?>
  <?php if ($google): ?><a class="tlacitko" href="google.php">Přihlásit přes Google</a><?php endif; ?>
</div>
<?php endif;
$ui->samostatna('Přihlášení', (string)ob_get_clean());
