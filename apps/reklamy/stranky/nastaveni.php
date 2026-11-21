<?php
declare(strict_types=1);
// Nastavení: zabezpečení (heslo, Google, Passkey), zálohy, systém. Proměnné: $app, $ui, $post, $relace.
use Reklamy\{Ui, Chyba};
use TWB\Zalohy\Zalohy;

$n = $app->nastaveni();
$zalohy = $app->zalohy();

if (isset($_GET['stahnout'])) {
    try {
        $cesta = $zalohy->cesta((string)$_GET['stahnout']);
    } catch (RuntimeException $e) {
        throw new Chyba($e->getMessage());
    }
    ob_end_clean();
    $app->log('info', 'Stažena záloha ' . basename($cesta));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($cesta) . '"');
    header('Content-Length: ' . filesize($cesta));
    header('Cache-Control: no-store');
    readfile($cesta);
    exit;
}

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $app, $n, $zalohy, &$zprava) {
        switch ($akce) {
            case 'heslo':
                if (!$app->heslo()->over((string)($_POST['stare'] ?? ''))) throw new Chyba('Současné heslo nesouhlasí.');
                if ((string)($_POST['nove'] ?? '') !== (string)($_POST['nove2'] ?? '')) throw new Chyba('Nová hesla se neshodují.');
                $app->heslo()->nastav((string)$_POST['nove']);
                $app->log('info', 'Heslo změněno.');
                $zprava = 'Heslo změněno.';
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
            case 'zalohy-heslo':
                $h = (string)($_POST['backup_password'] ?? '');
                if (mb_strlen($h) < 10) throw new Chyba('Heslo záloh musí mít aspoň 10 znaků. Bez něj zálohu neotevřete – uložte si ho mimo aplikaci.');
                $n->uloz('backup_password', $h);
                $n->uloz('backup_auto', !empty($_POST['backup_auto']) ? '1' : '0');
                $app->log('info', 'Nastavení záloh změněno.');
                break;
            case 'zalohy-auto':
                $n->uloz('backup_auto', !empty($_POST['backup_auto']) ? '1' : '0');
                break;
            case 'zaloha':
                $z = $zalohy->vytvor('rucni', true);
                $zprava = 'Záloha vytvořena: ' . $z['name'];
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('nastaveni') . '#' . (str_starts_with($akce, 'zalo') ? 'zalohy' : 'zabezpeceni'));
    }
}

$google = $app->google();
$gEmail = (string)$n->dej('login_google_email', '');
$pk = $app->passkey();
$maHesloZaloh = (string)$n->dej('backup_password', '') !== '';
$auto = $n->dej('backup_auto', '1') === '1';
$cronKlic = (string)$app->cfg('cron_key', '');
$cron = (int)$n->dej('cron_last_run', 0);
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

  <div class="karta">
    <h3 style="margin-top:0">Přihlášení přes Google</h3>
    <?php if (!$google->nastaveno()): ?>
      <p class="slabe">V config.php chybí <code>google_client_id</code> a <code>google_client_secret</code>. Adresa pro přesměrování: <code><?= Ui::h($app->url('google.php')) ?></code></p>
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
  <h2 id="zalohy" style="margin-top:0">Zálohy</h2>
  <?php if ($proc = $zalohy->podporovano()): ?><div class="hlaska hlaska-chyba"><?= Ui::h($proc) ?></div><?php endif; ?>
  <form method="post" class="formular karta">
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="zalohy-heslo">
    <?= Ui::pole($maHesloZaloh ? 'Nové heslo záloh' : 'Heslo záloh', 'backup_password', '', ['type' => 'password', 'minlength' => 10, 'required' => true, 'autocomplete' => 'new-password'],
        'Zálohy jsou šifrované ZIPy (AES-256). Bez hesla je neotevřete – uložte si ho mimo aplikaci.' . ($maHesloZaloh ? ' Heslo je nastavené.' : '')) ?>
    <label class="zaskrtavatko"><input type="checkbox" name="backup_auto" value="1"<?= $auto ? ' checked' : '' ?>> Automatická denní záloha (spouští cron)</label>
    <div><button type="submit"><?= $maHesloZaloh ? 'Změnit heslo' : 'Nastavit zálohy' ?></button></div>
  </form>
  <?php if ($maHesloZaloh): ?>
    <div class="tlacitka" style="margin-bottom:1rem"><?= $ui->tlacitko('Zálohovat teď', 'zaloha', [], 'hlavni') ?></div>
    <?php $seznam = $zalohy->seznam(); if ($seznam): ?>
    <div class="tabulka-obal"><table>
      <tr><th>Záloha</th><th class="cisla">Velikost</th></tr>
      <?php foreach (array_slice($seznam, 0, 15) as $z): ?>
      <tr><td><a href="<?= Ui::h(Ui::odkaz('nastaveni', ['stahnout' => $z['name']])) ?>"><?= Ui::h(Ui::datum($z['time'])) ?></a>
        <span class="slabe mala"><?= $z['files'] ? 'se soubory' : 'jen databáze' ?><?= $z['before_restore'] ? ', před obnovou' : '' ?></span></td>
        <td class="cisla"><?= Ui::h(Zalohy::velikost((int)$z['size'])) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  <?php endif; ?>

  <h2 id="system">Systém</h2>
  <div class="karta">
    <dl class="udaje">
      <dt>Verze</dt><dd><?= Ui::h($app->verze) ?></dd>
      <dt>PHP</dt><dd><?= Ui::h(PHP_VERSION) ?></dd>
      <dt>Adresa</dt><dd><?= Ui::h($app->url()) ?></dd>
      <dt>Cron</dt><dd><?= $cron ? 'naposledy ' . Ui::h(Ui::datum($cron)) : 'zatím neběžel' ?></dd>
      <dt>Aktualizace</dt><dd><?= (string)$app->cfg('update_token', '') !== '' ? 'token nastaven' : 'v config.php chybí update_token' ?></dd>
    </dl>
    <?php if ($cronKlic === '' || $cronKlic === 'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC'): ?>
      <p class="hlaska hlaska-varovani" style="margin:1rem 0 0">V config.php nastavte <code>cron_key</code> (dlouhý náhodný řetězec).</p>
    <?php else: ?>
      <p class="mala" style="margin-bottom:.3rem">Adresa pro cron (Wedos → Cron úlohy, každých 5–15 minut):</p>
      <div class="tlacitka"><input id="cron-url" readonly value="<?= Ui::h($app->url('cron.php?key=' . rawurlencode($cronKlic))) ?>" style="flex:1"><button type="button" class="maly" data-kopirovat="cron-url">Kopírovat</button></div>
    <?php endif; ?>
  </div>
</section>
</div>
