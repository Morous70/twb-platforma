<?php
declare(strict_types=1);
// Přihlašovací stránka: heslo, Google, Passkey, zapomenuté heslo (?zapomenute) a nové heslo z odkazu v e-mailu (?reset=token).
// 0.8.9: ve vzhledu veřejného webu (Ui::prihlasovaci). Proměnné: $app, $ui, $relace, $post.
use MediaGalerie\Ui;

$zamek = $app->zamek();
$akce = $post ? (string)($_POST['akce'] ?? '') : '';
$reset = is_string($_GET['reset'] ?? null) ? (string)$_GET['reset'] : '';
$zapomenute = isset($_GET['zapomenute']);
$odeslano = false;

if ($post && !$relace->csrfOver($_POST['csrf'] ?? null)) {
    $ui->chyby[] = 'Formulář vypršel, zkuste to prosím znovu.';
} elseif ($akce === 'prihlasit') {
    if ($zamek->zbyva() > 0) {
        $ui->chyby[] = $zamek->zprava();
    } elseif ($app->heslo()->over((string)($_POST['heslo'] ?? ''))) {
        $zamek->vynuluj();
        $relace->prihlas('heslo');
        $ui->presmeruj('index.php' . (isset($_GET['p']) ? '?p=' . rawurlencode((string)$_GET['p']) : ''));
    } else {
        $zamek->neuspech('heslo');
        $ui->chyby[] = $zamek->zbyva() > 0 ? $zamek->zprava() : 'Nesprávné heslo.';
    }
} elseif ($akce === 'zapomenute') {
    // odpověď je vždy stejná, aby nešlo zjistit, jaká adresa je nastavená
    if ($zamek->zbyva() > 0) $ui->chyby[] = $zamek->zprava();
    else { $app->obnova()->zadost(mb_substr((string)($_POST['email'] ?? ''), 0, 200)); $odeslano = true; }
} elseif ($akce === 'reset' && $reset !== '') {
    if ($zamek->zbyva() > 0) {
        $ui->chyby[] = $zamek->zprava();
    } elseif ((string)($_POST['heslo'] ?? '') !== (string)($_POST['heslo2'] ?? '')) {
        $ui->chyby[] = 'Hesla se neshodují.';
    } else {
        try {
            $app->obnova()->pouzij($reset, (string)$_POST['heslo']);
            $_SESSION['flash'][] = ['ok', 'Heslo je změněné, přihlaste se jím.'];
            $ui->presmeruj('index.php');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'aspoň')) $zamek->neuspech('obnova hesla');
            $ui->chyby[] = $e->getMessage();
        }
    }
}
if ($e = $relace->vezmiChybu()) $ui->chyby[] = $e;
http_response_code($post && $ui->chyby ? 401 : 200);
ob_start();

if ($reset !== ''): ?>
<?php if (!$app->tokeny()->platny('reset', $reset)): ?>
  <p>Odkaz pro obnovu hesla už neplatí (platí 30 minut a jen jednou).</p>
  <p><a href="index.php?zapomenute">Požádat o nový odkaz</a> · <a href="index.php">Přihlášení</a></p>
<?php else: ?>
<form method="post" class="formular">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="reset">
  <?= Ui::pole('Nové heslo', 'heslo', '', ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password', 'autofocus' => true], 'Aspoň 8 znaků.') ?>
  <?= Ui::pole('Nové heslo znovu', 'heslo2', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
  <button type="submit" class="tlacitko hlavni">Nastavit heslo</button>
</form>
<?php endif;
$ui->prihlasovaci('Nové heslo', (string)ob_get_clean(), 'Zvolte nové heslo do administrace.');
return;
endif;

if ($zapomenute): ?>
<?php if ($odeslano): ?>
  <div class="hlaska hlaska-ok">Pokud adresa souhlasí s nastaveným e-mailem pro obnovu, poslali jsme na ni odkaz. Platí 30 minut.</div>
<?php endif; ?>
<form method="post" class="formular">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="zapomenute">
  <?= Ui::pole('E-mail pro obnovu hesla', 'email', '', ['type' => 'email', 'required' => true, 'autocomplete' => 'email', 'autofocus' => true], 'Adresa nastavená v Nastavení → Zabezpečení.') ?>
  <button type="submit" class="tlacitko hlavni">Poslat odkaz</button>
</form>
<p class="prihlaseni-odkazy"><a href="index.php">Zpět na přihlášení</a></p>
<?php
$ui->prihlasovaci('Zapomenuté heslo', (string)ob_get_clean(), 'Pošleme vám odkaz pro nastavení nového hesla.');
return;
endif;

$google = $app->google()->nastaveno() && (string)$app->nastaveni()->dej('login_google_sub', '') !== '';
$passkey = $app->passkey()->dostupne() && $app->passkey()->seznam() !== [];
?>
<form method="post" class="formular">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="prihlasit">
  <label class="pole"><span>Heslo</span><input type="password" name="heslo" autocomplete="current-password" required autofocus></label>
  <button type="submit" class="tlacitko hlavni">Přihlásit</button>
</form>
<?php if ($google || $passkey): ?>
<div class="nebo"><span>nebo</span></div>
<div class="tlacitka-sloupec">
  <?php if ($passkey): ?><button type="button" class="tlacitko vedlejsi" id="passkey-prihlasit" data-csrf="<?= Ui::h($relace->csrfToken()) ?>">Přihlásit pomocí Passkey</button><?php endif; ?>
  <?php if ($google): ?><a class="tlacitko vedlejsi" href="google.php">Přihlásit přes Google</a><?php endif; ?>
</div>
<?php endif; ?>
<p class="prihlaseni-odkazy"><a href="index.php?zapomenute">Zapomenuté heslo</a></p>
<?php
$ui->prihlasovaci('Přihlášení', (string)ob_get_clean(), 'Správa galerie, médií a veřejného webu.');
