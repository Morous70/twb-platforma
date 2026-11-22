<?php
declare(strict_types=1);
// Nastavení: zabezpečení (heslo, e-mail pro obnovu, odhlášení po nečinnosti, Google, Passkey) a systém. Proměnné: $app, $ui, $post, $relace.
use MediaGalerie\{Ui, Chyba};

$n = $app->nastaveni();

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $app, $n, &$zprava) {
        switch ($akce) {
            case 'heslo':
                if (!$app->heslo()->over((string)($_POST['stare'] ?? ''))) throw new Chyba('Současné heslo nesouhlasí.');
                if ((string)($_POST['nove'] ?? '') !== (string)($_POST['nove2'] ?? '')) throw new Chyba('Nová hesla se neshodují.');
                $app->heslo()->nastav((string)$_POST['nove']);
                $app->log('info', 'Heslo změněno.');
                $zprava = 'Heslo změněno.';
                break;
            case 'obnova-email':
                $e = trim((string)($_POST['reset_email'] ?? ''));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) === false) throw new Chyba('Zadejte platný e-mail.');
                $n->uloz('reset_email', $e !== '' ? $e : null);
                $app->log('info', $e !== '' ? 'E-mail pro obnovu hesla změněn.' : 'E-mail pro obnovu hesla odebrán.');
                break;
            case 'necinnost':
                $min = (int)($_POST['idle_minutes'] ?? 0);
                if ($min !== 0 && ($min < 5 || $min > 1440)) throw new Chyba('Odhlášení po nečinnosti musí být 0 (nikdy) nebo 5 až 1440 minut.');
                $n->uloz('idle_minutes', (string)$min);
                $app->log('info', 'Odhlášení po nečinnosti: ' . ($min ? "{$min} min" : 'vypnuto') . '.');
                break;
            case 'google-odpojit':
                $n->uloz('login_google_sub', null);
                $n->uloz('login_google_email', null);
                $app->log('info', 'Google účet odpojen od přihlášení.');
                $zprava = 'Google účet odpojen.';
                break;
            case 'passkey-smazat':
                if (!$app->passkey()->smaz((int)($_POST['id'] ?? 0))) throw new Chyba('Zařízení nenalezeno.');
                $app->log('info', 'Zařízení Passkey odebráno.');
                $zprava = 'Zařízení odebráno.';
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('nastaveni') . '#zabezpeceni');
    }
}

$google = $app->google();
$gEmail = (string)$n->dej('login_google_email', '');
$pk = $app->passkey();
$resetEmail = $app->emailObnovy();
?>
<div class="dva-sloupce">
<section>
  <h2 id="zabezpeceni" style="margin-top:0">Zabezpečení</h2>
  <form method="post" class="formular karta">
    <h3 style="margin:0">Změna hesla</h3>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="heslo">
    <?= Ui::pole('Současné heslo', 'stare', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
    <div class="radek">
      <?= Ui::pole('Nové heslo', 'nove', '', ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password']) ?>
      <?= Ui::pole('Nové heslo znovu', 'nove2', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    </div>
    <div><button type="submit">Změnit heslo</button></div>
  </form>

  <form method="post" class="formular karta">
    <h3 style="margin:0">Obnova zapomenutého hesla</h3>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="obnova-email">
    <?php if ($resetEmail === ''): ?><p class="hlaska hlaska-varovani" style="margin:0">Není nastavený e-mail pro obnovu – zapomenuté heslo by nešlo obnovit.</p><?php endif; ?>
    <?= Ui::pole('E-mail pro obnovu hesla', 'reset_email', $resetEmail, ['type' => 'email', 'autocomplete' => 'email'], 'Jen na tuto adresu přijde odkaz pro nastavení nového hesla (platí 30 minut).') ?>
    <div><button type="submit">Uložit</button></div>
  </form>

  <form method="post" class="formular karta">
    <h3 style="margin:0">Automatické odhlášení</h3>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="necinnost">
    <?= Ui::pole('Odhlásit po nečinnosti (minuty)', 'idle_minutes', (string)$app->necinnostMinut(), ['type' => 'number', 'min' => 0, 'max' => 1440, 'required' => true], '0 = nikdy, jinak 5 až 1440 minut.') ?>
    <div><button type="submit">Uložit</button></div>
  </form>
  <div class="karta">
    <h3 style="margin-top:0">Přihlášení přes Google</h3>
    <?php if (!$google->nastaveno()): ?>
      <p class="slabe">V config.php chybí <code>google_client_id</code> a <code>google_client_secret</code>. Adresa pro přesměrování (Authorized redirect URI): <code><?= Ui::h($app->url('google.php')) ?></code></p>
    <?php elseif ($gEmail !== ''): ?>
      <p>Propojený účet: <strong><?= Ui::h($gEmail) ?></strong></p>
      <?= $ui->tlacitko('Odpojit', 'google-odpojit', [], 'maly', 'Odpojit Google účet od přihlášení?') ?>
    <?php else: ?>
      <p class="slabe">Propojte Google účet, kterým se budete smět přihlásit.</p>
    <?php endif; ?>
    <?php if ($google->nastaveno()): ?>
      <form method="post" action="google.php?propojit=1" class="inline"><?= $ui->csrf() ?><button type="submit" class="maly"><?= $gEmail !== '' ? 'Propojit jiný účet' : 'Propojit Google účet' ?></button></form>
    <?php endif; ?>
  </div>

  <div class="karta">
    <h3 style="margin-top:0">Passkey</h3>
    <?php if (!$pk->dostupne()): ?>
      <p class="slabe">Passkey funguje jen přes HTTPS (adresa aplikace: <?= Ui::h($app->url()) ?>).</p>
    <?php else: ?>
      <?php $zarizeni = $pk->seznam(); if ($zarizeni): ?>
      <div class="tabulka-obal"><table>
        <tr><th>Zařízení</th><th>Přidáno</th><th>Naposledy</th><th></th></tr>
        <?php foreach ($zarizeni as $z): ?>
        <tr><td><?= Ui::h($z['name']) ?></td><td class="mala"><?= Ui::h($z['created_at']) ?></td><td class="mala"><?= Ui::h($z['last_used'] ?? '–') ?></td>
          <td><?= $ui->tlacitko('Odebrat', 'passkey-smazat', ['id' => $z['id']], 'maly nebezpecne', 'Odebrat zařízení „' . $z['name'] . '“?') ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php else: ?><p class="slabe">Zatím žádné zařízení. Passkey umožní přihlášení otiskem prstu, obličejem nebo PINem zařízení.</p><?php endif; ?>
      <div class="tlacitka">
        <input id="passkey-nazev" placeholder="Název zařízení, např. MacBook" maxlength="100" style="max-width:260px">
        <button type="button" id="passkey-pridat" data-csrf="<?= Ui::h($relace->csrfToken()) ?>">Přidat toto zařízení</button>
      </div>
    <?php endif; ?>
  </div>
</section>

<section>
  <h2 id="system" style="margin-top:0">Systém</h2>
  <div class="karta">
    <dl class="udaje">
      <dt>Verze</dt><dd><?= Ui::h($app->verze) ?></dd>
      <dt>PHP</dt><dd><?= Ui::h(PHP_VERSION) ?></dd>
      <dt>Adresa</dt><dd><?= Ui::h($app->url()) ?></dd>
      <dt>Protokol</dt><dd><a href="<?= Ui::h(Ui::odkaz('protokol')) ?>">Události a chyby aplikace</a></dd>
    </dl>
  </div>
</section>
</div>
