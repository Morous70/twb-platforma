<?php
declare(strict_types=1);
// TWB Reklamy 0.4: cílení kampaní, frekvence, trackery a přiřazení konverzí po prokliku a zobrazení.
require __DIR__ . '/pomocne.php';

use Reklamy\{App, Kontext, Cileni, Konverze, Schema};

echo "Reklamy: konverze, cílení a frekvence\n";

const UA_PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const UA_MOBIL = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_TABLET = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

/** Kontext návštěvníka; 'v' = identifikátor z cookie (zapne cookies). */
function navsteva(array $v = []): Kontext
{
    $k = new Kontext(stranka: $v['loc'] ?? '', odkud: $v['ref'] ?? '', prohlizec: $v['ua'] ?? UA_PC, jazyk: $v['jazyk'] ?? '', cas: $v['cas'] ?? time());
    if (array_key_exists('v', $v) || isset($v['f'])) $k->nactiCookies(['twbr_v' => $v['v'] ?? '', 'twbr_f' => $v['f'] ?? '']);
    return $k;
}

/** Zóna 300×250 s jedním HTML bannerem kampaně; vrací [zona(array), kampan, banner]. */
function sestava(App $app, array $kampan = []): array
{
    $web = $app->weby()->uloz(['name' => 'Web']);
    $z = $app->zony()->uloz(['site_id' => $web, 'name' => 'Zóna', 'width' => 300, 'height' => 250]);
    $k = kampan($app, $kampan);
    $b = htmlBanner($app, $k, 300, 250);
    $app->zony()->prirad($z, [$k], []);
    return [$app->zony()->radek($z), $k, $b];
}

$V1 = str_repeat('a1', 16);
$V2 = str_repeat('b2', 16);

test('cílení z formuláře: dny, hodiny, zařízení, jazyky, stránky; prázdné = bez cílení', function () {
    eq(null, Cileni::zFormulare([]));
    eq(null, Cileni::zFormulare(['cil_dny' => ['1', '2', '3', '4', '5', '6', '7'], 'cil_od' => '0', 'cil_do' => '24', 'cil_zarizeni' => array_keys(Cileni::ZARIZENI)]), 'všechno zaškrtnuté = bez omezení');
    $p = Cileni::zFormulare(['cil_dny' => ['5', '1', '9', '1'], 'cil_od' => '22', 'cil_do' => '6', 'cil_zarizeni' => ['mobil', 'hack'],
        'cil_jazyky' => 'CS, sk;en', 'cil_stranky' => " Kazbo.eu/Clanky \n\n", 'cil_bez_stranek' => 'kazbo.eu/kontakt', 'cil_odkud' => 'google.']);
    eq(['dny' => [1, 5], 'od' => 22, 'do' => 6, 'zarizeni' => ['mobil'], 'jazyky' => ['cs', 'sk', 'en'],
        'stranky' => ['kazbo.eu/clanky'], 'bez_stranek' => ['kazbo.eu/kontakt'], 'odkud' => ['google.']], $p);
    eq(['od' => 0, 'do' => 12], Cileni::zFormulare(['cil_do' => '12']), 'chybějící „od“ = od půlnoci');
    throws(fn() => Cileni::zFormulare(['cil_od' => '8', 'cil_do' => '8']), 'nesmí rovnat');
    throws(fn() => Cileni::zFormulare(['cil_jazyky' => 'čeština']), 'dvoupísmenným');
    throws(fn() => Cileni::zFormulare(['cil_stranky' => implode("\n", range(1, 51))]), 'nejvýš 50');
    $f = Cileni::doFormulare(json_encode($p));
    eq([[1, 5], 22, 'cs, sk, en', 'kazbo.eu/clanky'], [$f['cil_dny'], $f['cil_od'], $f['cil_jazyky'], $f['cil_stranky']]);
    eq($p, Cileni::zFormulare($f), 'formulář a pravidla jdou tam a zpět');
    eq('Po, Pá · 22–6 h · mobil · jazyk cs, sk, en · stránky 1 · mimo 1 stránek · odkud 1 · nejvýš 3× za 24 h', Cileni::popis(json_encode($p), 3, null));
    eq('bez omezení', Cileni::popis(null));
});

test('cílení: splnění pravidel podle času, zařízení, jazyka, stránky a odkud', function () {
    $po10 = (new DateTimeImmutable('2026-11-23 10:30'))->getTimestamp();   // pondělí
    $po23 = (new DateTimeImmutable('2026-11-23 23:15'))->getTimestamp();
    $so10 = (new DateTimeImmutable('2026-11-21 10:30'))->getTimestamp();   // sobota
    eq(true, Cileni::splnuje(null, navsteva()));
    eq([true, false], [Cileni::splnuje(['dny' => [1, 2]], navsteva(['cas' => $po10])), Cileni::splnuje(['dny' => [1, 2]], navsteva(['cas' => $so10]))]);
    eq([true, false], [Cileni::splnuje(['od' => 8, 'do' => 17], navsteva(['cas' => $po10])), Cileni::splnuje(['od' => 8, 'do' => 17], navsteva(['cas' => $po23]))]);
    eq([false, true], [Cileni::splnuje(['od' => 22, 'do' => 6], navsteva(['cas' => $po10])), Cileni::splnuje(['od' => 22, 'do' => 6], navsteva(['cas' => $po23]))], 'přes půlnoc');
    eq(['pocitac', 'mobil', 'tablet'], [navsteva()->zarizeni(), navsteva(['ua' => UA_MOBIL])->zarizeni(), navsteva(['ua' => UA_TABLET])->zarizeni()]);
    eq([false, true], [Cileni::splnuje(['zarizeni' => ['mobil', 'tablet']], navsteva()), Cileni::splnuje(['zarizeni' => ['mobil']], navsteva(['ua' => UA_MOBIL]))]);
    eq('cs', navsteva(['jazyk' => 'cs-CZ,cs;q=0.9,en;q=0.8'])->jazyk());
    eq([true, false, false], [Cileni::splnuje(['jazyky' => ['cs', 'sk']], navsteva(['jazyk' => 'sk'])),
        Cileni::splnuje(['jazyky' => ['cs']], navsteva(['jazyk' => 'en-US'])), Cileni::splnuje(['jazyky' => ['cs']], navsteva())], 'neznámý jazyk nesplní');
    $str = ['stranky' => ['kazbo.eu/clanky']];
    eq([true, false, false], [Cileni::splnuje($str, navsteva(['loc' => 'https://KAZBO.eu/clanky/1'])), Cileni::splnuje($str, navsteva(['loc' => 'https://kazbo.eu/'])),
        Cileni::splnuje($str, navsteva())], 'stránka chybí → kampaň se nezobrazí');
    $bez = ['bez_stranek' => ['/kontakt']];
    eq([false, true, true], [Cileni::splnuje($bez, navsteva(['loc' => 'https://kazbo.eu/kontakt'])), Cileni::splnuje($bez, navsteva(['loc' => 'https://kazbo.eu/'])), Cileni::splnuje($bez, navsteva())]);
    eq([true, false], [Cileni::splnuje(['odkud' => ['google.']], navsteva(['ref' => 'https://www.google.cz/'])), Cileni::splnuje(['odkud' => ['google.']], navsteva(['ref' => 'https://seznam.cz/']))]);
});

test('doručování respektuje cílení kampaně a frekvenci návštěvníka', function () {
    $app = novaApp();
    [$z, $kid, $b] = sestava($app, ['cil_zarizeni' => ['mobil'], 'cap_impressions' => '2', 'cap_hours' => '']);
    $kampan = $app->kampane()->radek($kid);
    eq([2, 24], [(int)$kampan['cap_impressions'], (int)$kampan['cap_hours']], 'výchozí okno frekvence 24 h');
    $d = $app->dorucovani();
    eq(null, $d->vyber($z, navsteva()), 'počítač necílené kampaně nedostane');
    $k = navsteva(['ua' => UA_MOBIL, 'v' => '']);
    eq($b, (int)$d->vyber($z, $k)['id']);
    $d->zobrazeni($b, (int)$z['id'], $k);
    $k->vynechat = [];
    eq($b, (int)$d->vyber($z, $k)['id']);
    $d->zobrazeni($b, (int)$z['id'], $k);
    $k->vynechat = [];
    eq(null, $d->vyber($z, $k), 'dvě zobrazení → frekvence vyčerpána');
    ok($k->zmenaFrekvence);
    // cookie twbr_f přenese počty do dalšího požadavku; po uplynutí okna se počítá znovu
    $f = $k->cookieFrekvence();
    ok(preg_match('/^' . $kid . '-2-\d+$/', $f) === 1, $f);
    eq(null, $d->vyber($z, navsteva(['ua' => UA_MOBIL, 'f' => $f])));
    eq($b, (int)$d->vyber($z, navsteva(['ua' => UA_MOBIL, 'f' => $f, 'cas' => time() + 25 * 3600]))['id']);
    eq($b, (int)$d->vyber($z, navsteva(['ua' => UA_MOBIL]))['id'], 'bez cookies (vložení ze serveru) se frekvence neuplatní');
    // poškozená cookie se ignoruje
    eq($b, (int)$d->vyber($z, navsteva(['ua' => UA_MOBIL, 'f' => 'x-1-2.' . $kid . '-a-1']))['id']);
    $app->kampane()->uloz(['name' => 'Bez frekvence'], $kid);
    eq(null, $app->kampane()->radek($kid)['cap_hours'], 'bez frekvence se okno neukládá');
    throws(fn() => $app->kampane()->uloz(['name' => 'X', 'cap_impressions' => '5000']), 'Frekvence');
});

test('tracker: uložení, částky, sledované kampaně, smazání', function () {
    $app = novaApp();
    $konv = $app->konverze();
    eq([123450, 123450, 123400, 10, null, 50000], [Konverze::castka('1234,50'), Konverze::castka('1 234.50 Kč'), Konverze::castka('1.234'),
        Konverze::castka('0,1'), Konverze::castka(' '), Konverze::castka('500')]);
    eq(123450, Konverze::castka('1.234,50'));
    throws(fn() => Konverze::castka('-5'), 'částka');
    throws(fn() => Konverze::castka('abc', 'Hodnota'), '„Hodnota“');
    eq(['1 234,50 Kč', '500 Kč', '', '0 Kč'], [Konverze::kc(123450), Konverze::kc(50000), Konverze::kc(null), Konverze::kc(0)]);
    $k1 = kampan($app); $k2 = kampan($app);
    $id = $konv->uloz(['name' => 'Objednávka', 'value_default' => '199', 'kampane' => [$k2, $k1, $k1, 0]]);
    $t = $konv->radek($id);
    eq(['active', 'sale', 19900, 30, 0, 0], [$t['status'], $t['kind'], (int)$t['value_default'], (int)$t['click_days'], (int)$t['view_days'], (int)$t['once_per_click']]);
    eq([$k1, $k2], $konv->kampane($id));
    throws(fn() => $konv->uloz(['name' => 'X', 'kampane' => [999]]), 'nenalezena');
    throws(fn() => $konv->uloz(['name' => 'X', 'click_days' => '0']), 'Okno po prokliku');
    throws(fn() => $konv->uloz(['name' => '']), 'Název');
    $konv->uloz(['name' => 'Objednávka', 'status' => 'paused', 'click_days' => '7', 'view_days' => '1', 'once_per_click' => '1'], $id);
    $t = $konv->radek($id);
    eq(['paused', 7, 1, 1, null], [$t['status'], (int)$t['click_days'], (int)$t['view_days'], (int)$t['once_per_click'], $t['value_default']]);
    eq([], $konv->kampane($id), 'nic nezaškrtnuté = všechny kampaně');
    eq(1, count($konv->seznam()));
    $kody = $konv->kody($t);
    eq(4, count($kody));
    ok(str_contains($kody[0][2], '/d/t.js'));
    ok(str_contains($kody[1][2], "twbr.push({ tracker: $id"));
    ok(str_contains($kody[2][2], "/d/konverze.php?t=$id&amp;hodnota="));
    ok(str_contains($kody[3][2], "t=$id&twbr={twbr}") && str_contains($kody[3][2], 'format=json'));
    $konv->smaz($id);
    eq([], $konv->seznam());
    throws(fn() => $konv->radek($id), 'nenalezen');
});

test('token prokliku: podpis, přidání do cílové adresy', function () {
    $app = novaApp();
    $konv = $app->konverze();
    $t = $konv->token(42);
    ok(preg_match('/^42\.[a-f0-9]{10}$/', $t) === 1, $t);
    eq(42, $konv->overToken($t));
    eq([null, null, null, null], [$konv->overToken('43' . substr($t, 2)), $konv->overToken('42.0000000000'), $konv->overToken(['42']), $konv->overToken(null)]);
    eq(null, novaApp()->konverze()->overToken($t), 'jiná instalace má jiný klíč');
    eq('https://e.cz/?twbr=1.ab', Konverze::pridejParametr('https://e.cz/', '1.ab'));
    eq('https://e.cz/p?a=1&twbr=1.ab#kosik', Konverze::pridejParametr('https://e.cz/p?a=1#kosik', '1.ab'));
    eq('https://e.cz/p?a=1&b=2&twbr=2.cd', Konverze::pridejParametr('https://e.cz/p?a=1&twbr=1.ab&b=2', '2.cd'), 'starý token se nahradí');
    eq('https://e.cz/p?twbr=2.cd', Konverze::pridejParametr('https://e.cz/p?twbr=1.ab', '2.cd'));
});

test('přiřazení konverze: token, cookie po prokliku, okno, kampaně trackeru, nepřiřazená', function () use ($V1, $V2) {
    $app = novaApp();
    [$z, $kid, $b] = sestava($app);
    $zid = (int)$z['id'];
    $d = $app->dorucovani();
    $konv = $app->konverze();
    $tr = $konv->uloz(['name' => 'Nákup', 'value_default' => '100', 'click_days' => '7']);
    $ted = time();
    // proklik s cookie návštěvníka V1 před 2 dny
    $klik = $d->proklik($b, $zid, navsteva(['v' => $V1, 'cas' => $ted - 2 * 86400]));
    ok($klik > 0);
    eq($V1, $app->db->q('SELECT visitor FROM clicks WHERE id = ?', [$klik])->fetchColumn());
    eq(null, $d->proklik($b, $zid, navsteva(['ua' => 'Googlebot/2.1'])), 'robot se nepočítá');
    // 1) token z adresy (jiný prohlížeč bez cookie)
    $r = $konv->zaznamenej($tr, $konv->token($klik), navsteva(), 25000, 'OBJ-1');
    eq(['ok', 'click', $b], [$r['stav'], $r['via'], $r['banner']]);
    $c = $app->db->q('SELECT * FROM conversions WHERE id = ?', [$r['id']])->fetch();
    eq([$kid, $b, $zid, $klik, 25000, 'OBJ-1'], [(int)$c['campaign_id'], (int)$c['banner_id'], (int)$c['zone_id'], (int)$c['click_id'], (int)$c['value'], $c['ref']]);
    // 2) stejné číslo objednávky podruhé
    eq('duplicita', $konv->zaznamenej($tr, $konv->token($klik), navsteva(), 25000, 'OBJ-1')['stav']);
    // 3) bez tokenu podle cookie, výchozí hodnota trackeru
    $r = $konv->zaznamenej($tr, null, navsteva(['v' => $V1]), null, 'OBJ-2');
    eq(['ok', 'click', $b], [$r['stav'], $r['via'], $r['banner']]);
    eq(10000, (int)$app->db->q('SELECT value FROM conversions WHERE id = ?', [$r['id']])->fetchColumn());
    // 4) neplatný token a neznámý návštěvník → nepřiřazená (uloží se bez banneru)
    $r = $konv->zaznamenej($tr, '1.0000000000', navsteva(['v' => $V2]));
    eq(['ok', '', null], [$r['stav'], $r['via'], $r['banner']]);
    // statistiky: 2 přiřazené konverze za 350 Kč u banneru
    eq([2, 35000], array_map('intval', $app->db->q('SELECT SUM(conversions), SUM(revenue) FROM stats WHERE banner_id = ?', [$b])->fetch(PDO::FETCH_NUM)));
    eq(2, $d->pocty($kid, null)['conversions'], 'limit konverzí kampaně je vidí');
    // 5) mimo okno 7 dní
    $stary = $d->proklik($b, $zid, navsteva(['v' => $V2, 'cas' => $ted - 8 * 86400]));
    eq('', $konv->zaznamenej($tr, $konv->token($stary), navsteva(['v' => $V2]))['via'], 'proklik starší než okno se nepřiřadí');
    // 6) tracker sleduje jinou kampaň
    $jina = kampan($app);
    $konv->uloz(['name' => 'Nákup', 'value_default' => '100', 'click_days' => '7', 'kampane' => [$jina]], $tr);
    eq('', $konv->zaznamenej($tr, $konv->token($klik), navsteva())['via'], 'proklik jiné kampaně se nepřiřadí');
    // 7) pozastavený tracker
    $konv->uloz(['name' => 'Nákup', 'status' => 'paused'], $tr);
    eq('neaktivni', $konv->zaznamenej($tr, $konv->token($klik), navsteva())['stav']);
    eq('neaktivni', $konv->zaznamenej(999, null, navsteva())['stav']);
    eq(5, count($konv->posledni($tr)), "dvě přiřazené, tři nepřiřazené");
    eq('Nákup', $konv->posledni(null, 1)[0]['tracker_name']);
    eq(5, (int)$konv->seznam()[0]["pocet"]);
});

test('přiřazení: poslední proklik vyhrává, jedna konverze na proklik, po zobrazení', function () use ($V1, $V2) {
    $app = novaApp();
    [$z, $kid, $b1] = sestava($app);
    $zid = (int)$z['id'];
    $b2 = htmlBanner($app, $kid, 300, 250);
    $d = $app->dorucovani();
    $konv = $app->konverze();
    $tr = $konv->uloz(['name' => 'Lead', 'kind' => 'lead', 'once_per_click' => '1']);
    $d->proklik($b1, $zid, navsteva(['v' => $V1, 'cas' => time() - 3600]));
    $d->proklik($b2, $zid, navsteva(['v' => $V1, 'cas' => time() - 60]));
    eq($b2, $konv->zaznamenej($tr, null, navsteva(['v' => $V1]))['banner'], 'poslední proklik');
    $r = $konv->zaznamenej($tr, null, navsteva(['v' => $V1]), null, 'jina-objednavka');
    eq(['duplicita', 'click'], [$r['stav'], $r['via']], 'druhá konverze na stejný proklik se nezapočítá');
    // zobrazení se zapisují, jen když nějaký aktivní tracker má okno po zobrazení
    $d->zobrazeni($b1, $zid, navsteva(['v' => $V2]));
    eq(0, (int)$app->db->q('SELECT COUNT(*) FROM views')->fetchColumn());
    $konv->uloz(['name' => 'Lead', 'kind' => 'lead', 'view_days' => '1'], $tr);
    $d2 = (new App(KOREN . '/apps/reklamy', ['data_dir' => $app->data]))->dorucovani();
    $k = navsteva(['v' => '']);
    $d2->zobrazeni($b1, $zid, $k);
    ok($k->novyNavstevnik && strlen($k->navstevnik) === 32, 'nový návštěvník dostane identifikátor');
    $d2->zobrazeni($b1, $zid, navsteva());
    eq(1, (int)$app->db->q('SELECT COUNT(*) FROM views')->fetchColumn(), 'bez cookies se zobrazení návštěvníka nezapisuje');
    $r = $konv->zaznamenej($tr, null, navsteva(['v' => $k->navstevnik]));
    eq(['ok', 'view', $b1], [$r['stav'], $r['via'], $r['banner']]);
    eq('', $konv->zaznamenej($tr, null, navsteva(['v' => $k->navstevnik, 'cas' => time() + 2 * 86400]))['via'], 'po okně zobrazení nic');
    // úklid smaže prokliky a zobrazení starší než nejdelší okno
    $app->db->q('UPDATE clicks SET at = ?', [time() - 40 * 86400]);
    $app->db->q('UPDATE views SET at = ?', [time() - 2 * 86400]);
    ok($d->uklid() >= 3);
    eq([0, 0], [(int)$app->db->q('SELECT COUNT(*) FROM clicks')->fetchColumn(), (int)$app->db->q('SELECT COUNT(*) FROM views')->fetchColumn()]);
});

test('převod databáze z 0.3: kampaně dostanou cílení, přibudou tabulky konverzí; opakování nic nezdvojí', function () {
    $app = novaApp();
    $kid = kampan($app, ['name' => 'Stará']);
    foreach (['conversions', 'views', 'clicks', 'tracker_campaigns', 'trackers'] as $t) $app->db->q("DROP TABLE $t");
    foreach (['targeting', 'cap_impressions', 'cap_hours'] as $s) $app->db->q("ALTER TABLE campaigns DROP COLUMN $s");
    $app->db->q("UPDATE module_versions SET version = 3 WHERE module = 'reklamy'");
    $app->nastaveni()->uloz('schema_version', '3');
    $app2 = new App(KOREN . '/apps/reklamy', ['data_dir' => $app->data]);
    eq(true, $app2->pripravDoruceni());
    eq((string)Schema::verze(), $app2->nastaveni()->dej('schema_version'));
    eq(['Stará', null], [$app2->kampane()->radek($kid)['name'], $app2->kampane()->radek($kid)['targeting']]);
    eq(0, (int)$app2->db->q('SELECT COUNT(*) FROM conversions')->fetchColumn());
    Schema::proved($app2->db, 3);
    ok(true, 'opakovaný převod projde');
});
