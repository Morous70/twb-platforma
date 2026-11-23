<?php
declare(strict_types=1);
// Nastavení v kartách (0.8.1): Zabezpečení (heslo, e-mail pro obnovu, odhlášení po nečinnosti, Google, Passkey), Komprese obrázků, Zálohy, Aktualizace, Systém.
// Aktivní karta: ?karta=… (bez JS, po odeslání formuláře) nebo #kotva (přepnutí v assets/app.js). Proměnné: $app, $ui, $post, $relace.
use MediaGalerie\{Ui, Chyba, Vstup};
use TWB\Obrazky\Obrazky;
use TWB\Zalohy\Zalohy;

$n = $app->nastaveni();
$zalohy = $app->zalohy();

// Karty stránky (kotva => název). Staré odkazy #zabezpeceni, #zalohy… vedou na stejnojmennou kartu.
$karty = ['zabezpeceni' => 'Zabezpečení', 'komprese' => 'Komprese obrázků', 'zalohy' => 'Zálohy', 'aktualizace' => 'Aktualizace', 'system' => 'Systém'];
$kartaAkce = fn(string $akce): string => match (true) {
    str_starts_with($akce, 'zalo'), str_starts_with($akce, 'obnova') => 'zalohy',
    str_starts_with($akce, 'aktualizace') => 'aktualizace',
    $akce === 'komprese' => 'komprese',
    default => 'zabezpeceni',
};
$karta = (string)($_GET['karta'] ?? '');
if (!isset($karty[$karta])) $karta = 'zabezpeceni';
// po neúspěšném odeslání se stránka vykreslí bez přesměrování: ukázat kartu formuláře, u kterého je chyba
if ($post) $karta = $kartaAkce((string)($_POST['akce'] ?? ''));

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

// Obnova ze zálohy ve dvou krocích: náhled obsahu (heslo zůstane jen v relaci, 10 minut), pak potvrzení.
$nahled = null;
$obnovaVRelaci = function () use ($zalohy): ?array {
    $o = $_SESSION['twbg_obnova'] ?? null;
    if (!is_array($o) || ($o['do'] ?? 0) < time()) return null;
    try { $zalohy->cesta((string)$o['zaloha']); } catch (RuntimeException) { return null; }
    return $o;
};
if ($post && ($_POST['akce'] ?? '') === 'obnova-nahled') {
    $ui->zpracuj(function () use ($zalohy, $n, &$nahled) {
        $f = $_FILES['soubor'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new Chyba('Soubor se nepodařilo nahrát (příliš velký nebo přerušený přenos).');
            $nazev = 'twb-galerie-' . date('Y-m-d-His') . '-nahrana.zip';
            if (!move_uploaded_file((string)$f['tmp_name'], $zalohy->slozka() . '/' . $nazev)) throw new Chyba('Nahranou zálohu nelze uložit.');
        } else {
            $nazev = (string)($_POST['zaloha'] ?? '');
            if ($nazev === '') throw new Chyba('Vyberte zálohu nebo nahrajte soubor.');
        }
        $cesta = $zalohy->cesta($nazev);
        $heslo = (string)($_POST['heslo'] ?? '') !== '' ? (string)$_POST['heslo'] : (string)$n->dej('backup_password', '');
        $info = $zalohy->info($cesta, $heslo);
        $_SESSION['twbg_obnova'] = ['zaloha' => $nazev, 'heslo' => $heslo, 'do' => time() + 600];
        $nahled = ['zaloha' => $nazev] + $info;
    });
}

if ($post && ($_POST['akce'] ?? '') !== 'obnova-nahled') {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $app, $n, $zalohy, $obnovaVRelaci, &$zprava) {
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
            case 'obnova':
                $o = $obnovaVRelaci();
                if (!$o || $o['zaloha'] !== (string)($_POST['zaloha'] ?? '')) throw new Chyba('Potvrzení obnovy vypršelo. Vyberte zálohu znovu.');
                unset($_SESSION['twbg_obnova']);
                $r = $zalohy->obnov($zalohy->cesta($o['zaloha']), $o['heslo']);
                $app->log('info', "Obnovena záloha {$o['zaloha']} (pojistná záloha {$r['safety']}).");
                $zprava = "Záloha obnovena. Předchozí stav je v pojistné záloze {$r['safety']}."
                    . ($r['missing'] ? ' Chybí soubory: ' . implode(', ', array_slice($r['missing'], 0, 5)) . (count($r['missing']) > 5 ? ' …' : '') . '.' : '');
                break;
            case 'obnova-zrusit':
                unset($_SESSION['twbg_obnova']);
                $zprava = 'Obnova zrušena.';
                break;
            case 'aktualizace-zjistit':
                $st = $app->aktualizace()->zkontroluj();
                if (($st['result']['result'] ?? '') !== 'ok' && ($st['result']['result'] ?? '') !== 'no_release') throw new Chyba((string)($st['result']['message'] ?? 'Kontrola se nezdařila.'));
                $zprava = (string)($st['result']['message'] ?? 'Hotovo.');
                break;
            case 'aktualizace-instalovat':
                $r = $app->aktualizace()->instaluj((string)($_POST['verze'] ?? ''));
                if (!$r['ok']) throw new Chyba($r['message']);
                $zprava = $r['message'];
                break;
            case 'aktualizace-vratit':
                $r = $app->aktualizace()->vratPredchozi();
                $zprava = "Vrácena verze {$r['version']}" . ($r['db_restored'] ? ' i s databází ze zálohy před aktualizací.' : '.');
                break;
            case 'komprese':
                $n->uloz('img_auto', !empty($_POST['img_auto']) ? '1' : '0');
                $n->uloz('img_max_w', (string)Vstup::cislo($_POST, 'img_max_w', 'Největší šířka', 320, 10000, true));
                $n->uloz('img_max_h', (string)Vstup::cislo($_POST, 'img_max_h', 'Největší výška', 240, 10000, true));
                $n->uloz('img_quality', (string)Vstup::cislo($_POST, 'img_quality', 'Kvalita JPEG', 30, 100, true));
                $n->uloz('img_min_kb', (string)Vstup::cislo($_POST, 'img_min_kb', 'Komprimovat od', 0, 1048576, true));
                $app->log('info', 'Nastavení komprese obrázků změněno (automaticky ' . (!empty($_POST['img_auto']) ? 'ano' : 'ne') . ').');
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
        $kotva = $kartaAkce($akce);
        $ui->presmeruj(Ui::odkaz('nastaveni', ['karta' => $kotva]) . '#' . $kotva);
    }
}

$google = $app->google();
$gEmail = (string)$n->dej('login_google_email', '');
$pk = $app->passkey();
$resetEmail = $app->emailObnovy();
$maHesloZaloh = (string)$n->dej('backup_password', '') !== '';
$auto = $n->dej('backup_auto', '1') === '1';
$cronKlic = (string)$app->cfg('cron_key', '');
$cron = (int)$n->dej('cron_last_run', 0);
$akt = $app->aktualizace()->stav();
// tečka u karty, která chce pozornost (stejné podmínky jako upozornění v přehledu)
$pozor = [
    'zabezpeceni' => $resetEmail === '',
    'zalohy' => !$maHesloZaloh || $zalohy->podporovano() !== null,
    'aktualizace' => !empty($akt['available']),
    'system' => $cron < time() - 2 * 86400,
];
$panel = fn(string $id): string => '<div class="panel-karty" id="' . $id . '" role="tabpanel" aria-labelledby="karta-' . $id . '" tabindex="-1" data-karta'
    . ($id === $karta ? '' : ' hidden') . '>';
?>
<nav class="karty-nav" role="tablist" aria-label="Oddíly nastavení">
<?php foreach ($karty as $id => $nazev): ?>
  <a href="<?= Ui::h(Ui::odkaz('nastaveni', ['karta' => $id]) . '#' . $id) ?>" id="karta-<?= $id ?>" role="tab" aria-controls="<?= $id ?>" aria-selected="<?= $id === $karta ? 'true' : 'false' ?>"<?= $id === $karta ? ' class="aktivni"' : ' tabindex="-1"' ?>><?= Ui::h($nazev) ?><?php if (!empty($pozor[$id])): ?><span class="tecka" title="Vyžaduje pozornost"></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>

<?= $panel('zabezpeceni') ?>
<div class="dva-sloupce">
<section>
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
</section>
<section>
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
</div>
</div>

<?= $panel('komprese') ?>
<div class="uzky-sloupec">
  <?php $o = Obrazky::nastaveni($n); ?>
  <form method="post" class="formular karta">
    <h3 style="margin:0">Komprese obrázků</h3>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="komprese">
    <label class="zaskrtavatko"><input type="checkbox" name="img_auto" value="1"<?= $o['auto'] ? ' checked' : '' ?>>
      <span>Zmenšit velké obrázky hned po nahrání <small class="slabe">Originál se vždy uchová a jde k němu vrátit. Bez zaškrtnutí jde obrázek zkomprimovat ručně v jeho detailu.</small></span></label>
    <div class="radek">
      <?= Ui::pole('Největší šířka (px)', 'img_max_w', (string)$o['max_w'], ['type' => 'number', 'min' => 320, 'max' => 10000, 'required' => true]) ?>
      <?= Ui::pole('Největší výška (px)', 'img_max_h', (string)$o['max_h'], ['type' => 'number', 'min' => 240, 'max' => 10000, 'required' => true]) ?>
    </div>
    <div class="radek">
      <?= Ui::pole('Kvalita JPEG (30–100)', 'img_quality', (string)$o['quality'], ['type' => 'number', 'min' => 30, 'max' => 100, 'required' => true]) ?>
      <?= Ui::pole('Komprimovat od (kB)', 'img_min_kb', (string)$o['min_kb'], ['type' => 'number', 'min' => 0, 'required' => true], 'Menší soubory s rozměrem v mezích se nechají.') ?>
    </div>
    <div><button type="submit">Uložit</button></div>
  </form>
</div>
</div>

<?= $panel('zalohy') ?>
<div class="dva-sloupce">
<section>
  <?php if ($proc = $zalohy->podporovano()): ?><div class="hlaska hlaska-chyba"><?= Ui::h($proc) ?></div><?php endif; ?>
  <form method="post" class="formular karta">
    <h3 style="margin:0">Nastavení záloh</h3>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="zalohy-heslo">
    <?= Ui::pole($maHesloZaloh ? 'Nové heslo záloh' : 'Heslo záloh', 'backup_password', '', ['type' => 'password', 'minlength' => 10, 'required' => true, 'autocomplete' => 'new-password'],
        'Zálohy jsou šifrované ZIPy (AES-256). Bez hesla je neotevřete – uložte si ho mimo aplikaci.' . ($maHesloZaloh ? ' Heslo je nastavené.' : '')) ?>
    <label class="zaskrtavatko"><input type="checkbox" name="backup_auto" value="1"<?= $auto ? ' checked' : '' ?>> Automatická denní záloha (spouští cron)</label>
    <div><button type="submit"><?= $maHesloZaloh ? 'Změnit heslo' : 'Nastavit zálohy' ?></button></div>
  </form>
  <?php if ($nahled): ?>
    <div class="karta hlaska-varovani" id="obnova">
      <p style="margin-top:0"><b>Obnovit zálohu <?= Ui::h($nahled['zaloha']) ?>?</b></p>
      <dl class="udaje">
        <dt>Vytvořena</dt><dd><?= Ui::h($nahled['created_at'] ?? '?') ?> (verze <?= Ui::h($nahled['app_version']) ?>)</dd>
        <dt>Obsah</dt><dd>média <?= Ui::cislo($nahled['tabulky']['media'] ?? 0) ?>, kategorie <?= Ui::cislo($nahled['tabulky']['categories'] ?? 0) ?>, soubory <?= Ui::cislo($nahled['files']) ?></dd>
      </dl>
      <p class="mala">Současná data se nahradí obsahem zálohy. Před obnovou se vytvoří pojistná záloha současného stavu. Přihlašovací údaje zůstanou.</p>
      <div class="tlacitka">
        <form method="post" class="inline"><?= $ui->csrf() ?><input type="hidden" name="akce" value="obnova"><input type="hidden" name="zaloha" value="<?= Ui::h($nahled['zaloha']) ?>">
          <button type="submit" class="nebezpecne">Obnovit zálohu</button></form>
        <form method="post" class="inline"><?= $ui->csrf() ?><input type="hidden" name="akce" value="obnova-zrusit"><button type="submit">Zrušit</button></form>
      </div>
    </div>
  <?php elseif (!$zalohy->podporovano()): ?>
    <form method="post" enctype="multipart/form-data" class="formular karta">
      <h3 id="obnova" style="margin:0">Obnova ze zálohy</h3>
      <?= $ui->csrf() ?><input type="hidden" name="akce" value="obnova-nahled">
      <?php $volby = ['' => '— vyberte —']; foreach ($zalohy->seznam() as $z) $volby[$z['name']] = Ui::datum($z['time']) . ($z['files'] ? ' (se soubory)' : '') . ($z['before_restore'] ? ' – před obnovou' : '') . ($z['uploaded'] ? ' – nahraná' : ''); ?>
      <?= Ui::vyber('Záloha z úložiště', 'zaloha', $volby, '') ?>
      <label class="pole"><span>…nebo nahrajte soubor zálohy (.zip)</span><input type="file" name="soubor" accept=".zip,application/zip"></label>
      <?= Ui::pole('Heslo zálohy', 'heslo', '', ['type' => 'password', 'autocomplete' => 'off'], 'Prázdné = současné heslo záloh.') ?>
      <div class="tlacitka"><button type="submit">Pokračovat</button></div>
    </form>
  <?php endif; ?>
</section>
<section>
  <?php if ($maHesloZaloh): ?>
    <div class="karta">
      <div class="tlacitka hlavicka-karty"><h3>Uložené zálohy</h3><?= $ui->tlacitko('Zálohovat teď', 'zaloha', [], 'hlavni') ?></div>
    <?php $seznam = $zalohy->seznam(); if ($seznam): ?>
    <div class="tabulka-obal" style="margin-bottom:0"><table>
      <tr><th>Záloha</th><th class="cisla">Velikost</th></tr>
      <?php foreach (array_slice($seznam, 0, 15) as $z): ?>
      <tr><td><a href="<?= Ui::h(Ui::odkaz('nastaveni', ['stahnout' => $z['name']])) ?>"><?= Ui::h(Ui::datum($z['time'])) ?></a>
        <span class="slabe mala"><?= $z['files'] ? 'se soubory' : 'jen databáze' ?><?= $z['before_restore'] ? ', před obnovou' : '' ?></span></td>
        <td class="cisla"><?= Ui::h(Zalohy::velikost((int)$z['size'])) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php else: ?><p class="slabe" style="margin-bottom:0">Zatím žádná záloha.</p><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="karta"><h3 style="margin-top:0">Uložené zálohy</h3><p class="slabe" style="margin-bottom:0">Nejdřív nastavte heslo záloh.</p></div>
  <?php endif; ?>
</section>
</div>
</div>

<?= $panel('aktualizace') ?>
<div class="uzky-sloupec">
  <div class="karta">
    <h3 style="margin-top:0">Aktualizace</h3>
    <dl class="udaje">
      <dt>Nainstalovaná verze</dt><dd><?= Ui::h($akt['version']) ?></dd>
      <dt>Token</dt><dd><?= $akt['token'] !== '' ? Ui::h($akt['token']) : 'v config.php chybí <code>update_token</code>' ?></dd>
      <dt>Naposledy zjišťováno</dt><dd><?= $akt['checked_at'] ? Ui::h(Ui::datum($akt['checked_at'])) : 'zatím ne' ?></dd>
      <?php if ($akt['result']): ?><dt>Výsledek</dt><dd><?= Ui::h($akt['result']['message'] ?? '') ?></dd><?php endif; ?>
    </dl>
    <?php if ($akt['unsupported']): ?><p class="hlaska hlaska-varovani"><?= Ui::h($akt['unsupported']) ?></p><?php endif; ?>
    <div class="tlacitka">
      <?php if (!$akt['unsupported']): ?><?= $ui->tlacitko('Zjistit novou verzi', 'aktualizace-zjistit', [], '') ?><?php endif; ?>
      <?php if ($akt['available']): ?>
        <?= $ui->tlacitko('Nainstalovat verzi ' . $akt['available']['version'], 'aktualizace-instalovat', ['verze' => $akt['available']['version']], 'hlavni',
            "Nainstalovat verzi {$akt['available']['version']}? Před instalací se zálohuje databáze; když nová verze nepoběží, vrátí se předchozí.") ?>
      <?php endif; ?>
      <?php if ($akt['previous']): ?>
        <?= $ui->tlacitko('Vrátit verzi ' . $akt['previous'], 'aktualizace-vratit', [], 'maly', "Vrátit předchozí verzi {$akt['previous']}?") ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<?= $panel('system') ?>
<div class="uzky-sloupec">
  <div class="karta">
    <h3 style="margin-top:0">Systém</h3>
    <dl class="udaje">
      <dt>Verze</dt><dd><?= Ui::h($app->verze) ?></dd>
      <dt>PHP</dt><dd><?= Ui::h(PHP_VERSION) ?></dd>
      <dt>Adresa</dt><dd><?= Ui::h($app->url()) ?></dd>
      <dt>Cron</dt><dd><?= $cron ? 'naposledy ' . Ui::h(Ui::datum($cron)) : 'zatím neběžel' ?></dd>
      <dt>Protokol</dt><dd><a href="<?= Ui::h(Ui::odkaz('protokol')) ?>">Události a chyby aplikace</a></dd>
    </dl>
    <?php if ($cronKlic === '' || $cronKlic === 'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC'): ?>
      <p class="hlaska hlaska-varovani" style="margin:1rem 0 0">V config.php nastavte <code>cron_key</code> (dlouhý náhodný řetězec).</p>
    <?php else: ?>
      <p class="mala" style="margin-bottom:.3rem">Adresa pro cron (Wedos → Cron úlohy, každých 5–15 minut):</p>
      <div class="tlacitka"><input id="cron-url" readonly value="<?= Ui::h($app->url('cron.php?key=' . rawurlencode($cronKlic))) ?>" style="flex:1"><button type="button" class="maly" data-kopirovat="cron-url">Kopírovat</button></div>
    <?php endif; ?>
  </div>
</div>
</div>
