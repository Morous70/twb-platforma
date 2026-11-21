<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
$base = rtrim((string)cfg('base_url'), '/');
$cron = $base . '/cron.php?key=' . (string)cfg('cron_key');
/** Jednoduchý převod CHANGELOG.md na HTML (nadpisy ##/###, odrážky, **tučně**, `kód`). */
function changelog_html(string $md): string {
    $out = ''; $inList = false;
    foreach (preg_split('/\R/', $md) as $line) {
        $t = h(trim($line));
        $t = preg_replace(['/\*\*(.+?)\*\*/', '/`(.+?)`/'], ['<strong>$1</strong>', '<code>$1</code>'], $t);
        $isItem = str_starts_with(trim($line), '- ');
        if ($inList && !$isItem) { $out .= '</ul>'; $inList = false; }
        if ($t === '' || str_starts_with(trim($line), '# ')) continue;
        if (str_starts_with(trim($line), '### ')) $out .= '<h6 class="mt-3">' . substr($t, 4) . '</h6>';
        elseif (str_starts_with(trim($line), '## ')) $out .= '<h5 class="mt-3">' . substr($t, 3) . '</h5>';
        elseif ($isItem) { if (!$inList) { $out .= '<ul>'; $inList = true; } $out .= '<li>' . substr($t, 2) . '</li>'; }
        else $out .= '<p>' . $t . '</p>';
    }
    return $out . ($inList ? '</ul>' : '');
}
layout_head('Nápověda', 'napoveda.php');
?>
        <h1 class="h3 mb-3">Nápověda</h1>
        <div class="row">
          <div class="col-lg-3 order-lg-2 mb-3">
            <div class="card sticky-top" style="top:1rem">
              <div class="card-header"><h5 class="card-title mb-0">Obsah</h5></div>
              <div class="list-group list-group-flush small">
                <a class="list-group-item list-group-item-action" href="#start">Rychlý start</a>
                <a class="list-group-item list-group-item-action" href="#cron">Cron (spouštění)</a>
                <a class="list-group-item list-group-item-action" href="#ucty">Účty a propojení</a>
                <a class="list-group-item list-group-item-action" href="#psani">Psaní příspěvku</a>
                <a class="list-group-item list-group-item-action" href="#data">Proměnné a data</a>
                <a class="list-group-item list-group-item-action" href="#slidy">Slidy</a>
                <a class="list-group-item list-group-item-action" href="#media">Obrázky, video, knihovna</a>
                <a class="list-group-item list-group-item-action" href="#planovani">Plánování a opakování</a>
                <a class="list-group-item list-group-item-action" href="#spravovani">Kalendář a příspěvky</a>
                <a class="list-group-item list-group-item-action" href="#limity">Limity sítí</a>
                <a class="list-group-item list-group-item-action" href="#mcp">AI asistenti (Claude, ChatGPT)</a>
                <a class="list-group-item list-group-item-action" href="#dalsi">Statistiky, oznámení, zálohy</a>
                <a class="list-group-item list-group-item-action" href="#bezpecnost">Zabezpečení</a>
                <a class="list-group-item list-group-item-action" href="#potize">Řešení potíží</a>
                <a class="list-group-item list-group-item-action" href="#neumi">Co aplikace neumí</a>
                <a class="list-group-item list-group-item-action" href="#verze">Verze a změny</a>
              </div>
            </div>
          </div>

          <div class="col-lg-9 order-lg-1">

            <div class="card" id="start">
              <div class="card-header"><h5 class="card-title mb-0">Rychlý start</h5></div>
              <div class="card-body">
                <ol class="mb-0">
                  <li>Na stránce <strong>Účty a propojení</strong> zvolte <strong>Facebook a Instagram</strong> a přihlaste se k Facebooku. Aplikace načte vaše stránky i k nim připojené Instagram účty. Threads se propojují zvlášť tlačítkem <strong>Threads</strong>.</li>
                  <li>Nastavte <a href="#cron">cron</a>, jinak se naplánované příspěvky nikdy neodešlou.</li>
                  <li>Klikněte na <strong>Nový příspěvek</strong>, napište text, přidejte obrázky, vyberte účty a čas. Potvrďte <strong>Naplánovat</strong>.</li>
                  <li>V <strong>Kalendáři</strong> a v <strong>Příspěvcích</strong> vidíte, co je naplánováno a co už vyšlo.</li>
                </ol>
              </div>
            </div>

            <div class="card" id="cron">
              <div class="card-header"><h5 class="card-title mb-0">Cron (spouštění)</h5></div>
              <div class="card-body">
                <p>Aplikace nic neodesílá sama od sebe. Každou minutu ji musí zavolat cron hostingu. Při každém zavolání odešle příspěvky, kterým nastal čas, a jednou denně ještě zkontroluje účty, obnoví tokeny a profilovky, načte statistiky a jednou týdně uloží zálohu.</p>
                <p class="mb-1"><strong>Adresa pro cron:</strong></p>
                <p><code><?= h($base) ?>/cron.php?key=<em>VÁŠ_KLÍČ</em></code></p>
                <details class="mb-3"><summary class="text-primary" style="cursor:pointer">Zobrazit celou adresu včetně klíče</summary>
                  <div class="mt-2"><input class="form-control" readonly value="<?= h($cron) ?>" onclick="this.select()"></div>
                  <div class="form-text">Klíč je tajný, nikomu ho neposílejte. Je v <code>app/config.php</code> pod názvem <code>cron_key</code>. Zvolte dlouhý náhodný řetězec.</div>
                </details>
                <p class="mb-1"><strong>Nastavení na Wedosu:</strong></p>
                <ol>
                  <li>Administrace Wedos → <em>Webhosting</em> → <em>CRON</em>.</li>
                  <li>Přidejte úlohu typu <em>URL</em> s adresou výše.</li>
                  <li>Interval nastavte na <strong>každou minutu</strong> (nejkratší povolený je u Wedosu obvykle 1 minuta, jinak zvolte co nejkratší).</li>
                </ol>
                <p class="mb-0 text-muted">Příspěvek se odešle se zpožděním nejvýše o jeden interval cronu. Pokud cron neběží, příspěvky zůstanou ve stavu „Naplánováno“ a odešlou se hned po jeho obnovení.</p>
              </div>
            </div>

            <div class="card" id="ucty">
              <div class="card-header"><h5 class="card-title mb-0">Účty a propojení</h5></div>
              <div class="card-body">
                <h6>Facebook a Instagram</h6>
                <p>Tlačítko <strong>Facebook a Instagram</strong> (stránka Účty a propojení) vás pošle k Metě. Po přihlášení se uloží všechny vaše stránky a Instagram účty, které jsou k nim připojené (profesionální účet propojený se stránkou). Stejným tlačítkem účty znovu propojíte, když jim vyprší oprávnění.</p>
                <h6>Threads</h6>
                <p>Threads mají vlastní klíče. V <code>app/config.php</code> nastavte <code>threads_app_id</code> a <code>threads_app_secret</code> (Meta → vaše aplikace → případ použití Threads API → Nastavení). Do polí Redirect Callback URL, Uninstall callback a Delete callback zadejte <code><?= h($base) ?>/threads_connect.php</code> a <code><?= h($base) ?>/threads_hook.php</code>. Svůj Threads účet přidejte v rolích aplikace jako testera a pozvánku potvrďte v Threads. Potom na stránce Účty a propojení zvolte <strong>Threads</strong>. Token Threads platí 60 dní a aplikace ho sama obnovuje z cronu.</p>
                <h6>Správa účtů</h6>
                <ul>
                  <li><strong>Účty a propojení:</strong> každý účet lze zakázat. Zakázané účty se nenabízejí v novém příspěvku a přeskočí se i u už naplánovaných. Sloupec Připojení ukazuje, jestli token stále platí. Tlačítko <em>Zkontrolovat účty</em> ověří všechny účty a obnoví profilovky.</li>
                  <li><strong>Skupiny účtů:</strong> pojmenovaný výběr účtů. V novém příspěvku jedním klikem přidáte celou skupinu.</li>
                  <li>Když účet přestane fungovat, objeví se upozornění v Kalendáři a (pokud je nastaven e-mail) přijde zpráva.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="psani">
              <div class="card-header"><h5 class="card-title mb-0">Psaní příspěvku</h5></div>
              <div class="card-body">
                <ul>
                  <li><strong>Text a formátování:</strong> tlačítka nad polem vkládají odrážky a mění styl písma (tučné, kurzíva, jiná písma jako na yaytext.com). Styly jsou speciální znaky Unicode, takže fungují i tam, kde sítě formátování nepodporují. Čtečky obrazovky je ale čtou špatně a diakritika se u některých stylů ztrácí, proto je používejte střídmě.</li>
                  <li><strong>Proměnné:</strong> do textu (i do prvního komentáře) lze vložit kalendář (<code>{{datum}}</code>, <code>{{den}}</code>, <code>{{svatek}}</code> …), vlastní proměnné a hodnoty z dat, vzdálených zdrojů (Allwyn) a počasí – viz <a href="#data">Proměnné a data</a>. Lišta pod textem nabízí nejčastější; tlačítko <em>＋ Další</em> otevře výběr všech včetně formátu čísla a data. Dosazují se až při odeslání. Neznámou proměnnou aplikace při ukládání odmítne. V odkazu a ve štítcích proměnné nefungují.</li>
                  <li><strong>Odkaz:</strong> po vložení adresy se načte náhled (titulek, popis, obrázek). Na Facebooku se u příspěvku bez obrázku zobrazí jako karta s odkazem. Jakmile přidáte obrázek, odkaz zůstane jen v textu. Na Instagramu odkaz v popisku není klikací.</li>
                  <li><strong>Štítky (hashtagy):</strong> pište je po jednom s Enterem, nebo vložte celý řádek (<code>#auta #veterani</code>), rozdělí se samy. Přidají se na konec příspěvku. Instagram povoluje nejvýše 30, Threads uznávají jen jeden štítek.</li>
                  <li><strong>První komentář:</strong> text, který se přidá jako komentář hned po zveřejnění (typicky hashtagy). Funguje na Facebooku, Instagramu a Threads (tam jako odpověď na vlastní příspěvek, max. 500 znaků), u Stories ne. Vyžaduje oprávnění ke komentářům u propojených účtů (Threads: <code>threads_manage_replies</code>).</li>
                  <li><strong>Typ publikace:</strong> Příspěvek, Reel (jedno video, jen Instagram) nebo Story (jeden obrázek či video, text se nepřenáší, na Facebooku jen obrázek).</li>
                  <li><strong>Náhled</strong> vpravo je přibližný a přepíná se mezi Facebookem, Instagramem a Threads.</li>
                  <li><strong>Koncept:</strong> tlačítko <em>Uložit koncept</em> uloží rozpracovaný příspěvek bez odeslání. Koncepty najdete v Příspěvcích (filtr Koncepty) a nezobrazují se v kalendáři.</li>
                  <li><strong>Šablony:</strong> text, odkaz, štítky a účty lze uložit jako šablonu a později jedním klikem načíst.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="data">
              <div class="card-header"><h5 class="card-title mb-0">Proměnné a data</h5></div>
              <div class="card-body">
                <p>Všechny hodnoty se dosadí <strong>při zveřejnění</strong> – podle jeho data a času a s čerstvými daty ze zdrojů (stahují se podle intervalu a ještě před odesláním). Náhled v Novém příspěvku je ukazuje předem pro zvolený čas.</p>
                <ul>
                  <li><strong>Kalendář:</strong> <code>{{datum}}</code> (19. 11. 2026, <code>{{datum:+1}}</code> následující den), <code>{{den}}</code> / <code>{{Den}}</code>, <code>{{mesic}}</code>, <code>{{rok}}</code>, <code>{{tyden}}</code>, <code>{{cas}}</code>, <code>{{hodina}}</code>, <code>{{svatek}}</code> (český kalendář, <code>{{svatek:+1}}</code> zítra), <code>{{odpocet:24.12.2026}}</code> (za 34 dní / zítra / dnes), <code>{{dnu:24.12.2026}}</code> (číslo).</li>
                  <li><strong>Vlastní proměnné</strong> (karta Proměnné): pevný text, nebo seznam hodnot, které se střídají při opakování; <code>{{cislo}}</code> = pořadí výskytu.</li>
                  <li><strong>Tabulka dat</strong> – řádky <em>Skupina | Parametr | Hodnota</em> (např. <code>Sportka | 26.11.2026 | 125000000</code>, <code>Ceník | Káva | 45</code>). Upravují se přímo v tabulce, nebo se vloží z Excelu (tři sloupce, Ctrl+C / Ctrl+V). Prošlé řádky jsou šedé a smažete je jedním tlačítkem.
                    <code>{{Sportka.nejbližší}}</code> = hodnota s nejbližším datem od dne zveřejnění, <code>{{Sportka.nejbližší.parametr}}</code> její datum, <code>{{Sportka.další}}</code>, <code>{{Sportka.poslední}}</code>, <code>{{Sportka:26.11.2026}}</code>, <code>{{Ceník:Káva}}</code>.</li>
                  <li><strong>Vzdálené zdroje</strong> – JSON nebo CSV z jiné adresy (i tabulka Google sdílená odkazem). Předem jsou nastavené <em>Allwyn</em> (klíč doplňte na konec adresy za <code>key=</code>) a <em>Svátky</em>. Tlačítko <em>Prohlédnout</em> ukáže všechny hodnoty a zkopíruje zápis. Příklady: <code>{{Allwyn:sportka.superjackpot|mil}}</code> (164 mil.), <code>{{Allwyn:sportka.tah1|cisla:, }}</code> (22, 14, 27…), <code>{{Allwyn:sportka.tah1.2}}</code> (2. číslo), CSV <code>{{Ceník:Káva.Cena}}</code>. Hru lze zapsat interním klíčem, kódem i názvem, na velikosti písmen a diakritice nezáleží.
                    Když zdroj nebude dostupný, použijí se poslední stažená data a do logu se zapíše jedno varování (a po obnovení zpráva). Pozor: zdroj <em>Svátky</em> vrací jméno jen pro dnešek – pro naplánované příspěvky je spolehlivější <code>{{svatek}}</code>.</li>
                  <li><strong>Počasí</strong> (OpenWeather, klíč zdarma): <code>{{Počasí:teplota}}</code>, <code>pocitová</code>, <code>popis</code>, <code>vlhkost</code>, <code>vítr</code>, <code>místo</code>; předpověď <code>{{Počasí:zítra.max}}</code> (<code>dnes</code>/<code>zítra</code>/<code>pozítří</code> + <code>.max</code>/<code>.min</code>/<code>.popis</code>/<code>.den</code>); jiné místo <code>{{Počasí:Brno.teplota}}</code>. Výchozí místo může být i souřadnicemi.</li>
                  <li><strong>Formátování</strong> za svislou čarou, lze řetězit: <code>|mil</code> 125 mil., <code>|mld</code> 1,26 mld., <code>|tis</code>, <code>|milionu</code> 125 milionů, <code>|miliard</code>, <code>|cislo</code> 125 000 000 (<code>:0</code> celé, <code>:0,00</code> vždy 2 místa), <code>|kc</code> Kč, <code>|bezmezer</code>, <code>|pripona: Kč</code>, <code>|datum</code> (<code>:kratke</code>, <code>:den</code>, <code>:dlouhe</code>, <code>:den-slovem</code> … nebo vlastní <code>:DN D. MĚSÍCE</code>), <code>|odpocet</code>, <code>|velka</code> / <code>|mala</code> / <code>|prvni</code>, <code>|cisla:, </code>, <code>|vychozi:—</code> (co ukázat, když hodnota chybí). Formát čísla a data vyberete s ukázkou ve výběru <em>＋ Další</em>.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="slidy">
              <div class="card-header"><h5 class="card-title mb-0">Slidy (obrázky s textem a daty)</h5></div>
              <div class="card-body">
                <p>Slide je obrázek složený v editoru: pozadí (barva, přechod, fotka z knihovny), texty, obrázky, obdélníky, <em>kuličky</em> s čísly tahu a <em>počasí</em>. Ukládá se do Knihovny médií (štítek <em>Slide</em>) a do příspěvku se vkládá jako každý jiný obrázek. Editor je převzatý z TWB Plánovače médií.</p>
                <ul>
                  <li><strong>Založení:</strong> Knihovna médií → <em>Nový slide</em>, nebo v Novém příspěvku <em>Vytvořit slide</em> (otevře se v nové kartě a po uložení se sám vloží do příspěvku). Úprava: tlačítko <em>✎ Upravit</em> na kartě nebo ve zvětšeném náhledu, tam je i <em>Duplikovat</em>.</li>
                  <li><strong>Formát plátna:</strong> čtverec 1 : 1 (1080 × 1080), na výšku 4 : 5 (1080 × 1350, Instagram), Story / Reels 9 : 16 (1080 × 1920), na šířku 16 : 9 (1920 × 1080) a odkaz 1,91 : 1 (1200 × 630). Při změně formátu se prvky poměrně přesunou a zmenší.</li>
                  <li><strong>Ovládání:</strong> klik = vybrat, tažení = posun, rohy = velikost; šipky posouvají (Shift o 10 px), Delete maže, Ctrl+D duplikuje, Ctrl+Z / Ctrl+Y zpět a znovu. Prvky se přichytávají ke středu a okrajům. Na mobilu lze upravit texty, rozložení se mění na počítači.</li>
                  <li><strong>Proměnné v textu</strong> se ve slidu píší s jednoduchými závorkami jako v Plánovači – <code>{svatek}</code>, <code>{Sportka.nejbližší|mil}</code>, <code>{Allwyn:sportka.superjackpot|mil}</code>, <code>{Počasí:teplota}</code> (fungují i dvojité <code>{{…}}</code>). Hodnoty, zdroje a formáty jsou stejné jako v textu příspěvku – viz <a href="#data">Proměnné a data</a>. U textu jde nastavit <em>varianty</em> (jiný text, když platí podmínka) a u každého prvku <em>Zobrazit jen když</em>.</li>
                  <li><strong>Grafický text</strong> (zlato, stříbro, barevné s obrysem a hloubkou), stín, obrys, podklad a vlastní písma (tlačítko <em>Písma…</em>, soubory TTF/OTF; ukládají se do app/data/fonts a jsou v zálohách).</li>
                  <li><strong>Aktualizace před zveřejněním:</strong> slide s proměnnými se těsně před odesláním znovu vykreslí pro čas zveřejnění – s aktuálním datem, svátkem, jackpotem i počasím. Políčko s datem a časem vedle <em>Skutečný náhled</em> ukáže, jak bude slide vypadat v jiný den. V aplikaci (knihovna, zveřejněné příspěvky) je pak vidět poslední vykreslená podoba.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="media">
              <div class="card-header"><h5 class="card-title mb-0">Obrázky, video a knihovna médií</h5></div>
              <div class="card-body">
                <ul>
                  <li>Soubory přetáhněte do pole, vyberte klikem, nebo vložte ze schránky (Ctrl+V). Podporované jsou JPEG, PNG, MP4 a MOV. Obrázky se vždy převádějí na JPEG (Instagram jiný nepřijímá).</li>
                  <li><strong>Více obrázků</strong> (až 10) se na Facebooku zveřejní jako galerie, na Instagramu a Threads jako carousel. Pořadí měníte šipkami. Video nelze kombinovat s dalšími soubory.</li>
                  <li><strong>Ořez</strong> (nůžky): poměry 1:1, 4:5 a 1,91:1. Instagram přijímá jen poměry od 4:5 do 1,91:1. Ořez platí pro všechny vybrané účty.</li>
                  <li><strong>Knihovna médií:</strong> všechny nahrané soubory se ukládají sem. Můžete je hledat (podle názvu i tagu), přejmenovat, třídit <em>tagy</em>, dávat do <em>archivu</em> a mazat (smazat lze jen soubor, který žádný příspěvek nepoužívá). V novém příspěvku je použijete tlačítkem <em>Vybrat z knihovny</em>.</li>
                  <li><strong>Tagy</strong> (např. Loterie, Tabák, Akce): soubor jich může mít až 10. Řada tagů nad mřížkou filtruje – klikem vyberete jeden nebo víc tagů a přepínačem <em>všechny / kterýkoli</em> určíte, jak se kombinují; <em>Bez tagu</em> ukáže soubory bez tagů. Tag přidáte tlačítkem <em>🏷 Tagy</em> na kartě, hromadně u vybraných souborů, nebo na počítači přetažením karty (i více vybraných) na tag. Nově nahrané soubory dostanou tag, který je právě vybraný. Klik na tag na kartě = rychlý filtr. Přejmenování (i sloučení) a mazání tagů je pod <em>Správa tagů</em>. Stejná řada tagů je ve výběru z knihovny v Novém příspěvku.</li>
                  <li><strong>Archiv:</strong> soubor, který už nechcete nabízet (např. loňská akce), pošlete tlačítkem <em>🗄 Archiv</em> do archivu. Naplánované příspěvky ho dál normálně zveřejní, jen se nenabízí ve výběru z knihovny. Archiv otevřete vpravo v řadě tagů (počet a velikost souborů); u souborů je vidět, v kolika příspěvcích jsou a kolik jich ještě čeká. Zpět ho vrátíte tlačítkem <em>Vrátit</em>.</li>
                  <li><strong>Obrázky z internetu</strong> (tlačítko <em>Z internetu</em> v Knihovně i v Novém příspěvku, převzato z aplikace Kvízy): hledání na <em>Wikimedia Commons</em> a <em>Openverse</em> (Flickr, muzea…) bez registrace, na <em>Pixabay</em> a <em>Pexels</em> po vložení vlastního klíče (odkaz <em>Klíče Pixabay / Pexels</em> v okně). Místo hledání lze vložit odkaz na stránku obrázku, nebo víc odkazů najednou. Obrázek se stáhne k vám (převede na JPEG do 2 048 px) i s <strong>autorem a licencí</strong>, jak je uvádí zdroj; nesvobodné obrázky a licence „nekomerční“ či „bez úprav“ se nenabízejí. Stejný obrázek se podruhé nestahuje.</li>
                  <li><strong>Autor a licence:</strong> jsou na kartě (©) a ve zvětšeném náhledu, kde je jde upravit (<em>Autor a licence…</em>) a zkopírovat. U licencí CC BY a CC BY-SA je potřeba autora uvést – Nový příspěvek to u takového obrázku připomene a nabídne vložení „Foto: autor, zdroj, licence“ do textu nebo prvního komentáře. U Pixabay, Pexels a CC0 to není povinné.</li>
                  <li><strong>Zvětšení:</strong> klik na kartu (nebo ⤢) otevře velký náhled; mezi zobrazenými soubory listujete šipkami, klávesami ←/→ nebo tažením prstem.</li>
                  <li>Soubor v knihovně zůstává na disku i po smazání příspěvku. Místo na hostingu hlídejte v počtu souborů a velikosti nahoře na stránce Knihovna.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="planovani">
              <div class="card-header"><h5 class="card-title mb-0">Plánování a opakování</h5></div>
              <div class="card-body">
                <ul>
                  <li><strong>Naplánovat</strong> odešle příspěvek v zadaný čas, <strong>Publikovat hned</strong> ho odešle při nejbližším spuštění cronu.</li>
                  <li><strong>Opakování</strong> (denně, týdně, měsíčně, volitelně do data): další výskyt vznikne až po odeslání předchozího. Zrušíte ho smazáním nejbližšího naplánovaného příspěvku série. Při měsíčním opakování se zachovává původní den v měsíci.</li>
                  <li>Čas zadáváte v časovém pásmu nastaveném v <code>app/config.php</code> (<code>timezone</code>).</li>
                </ul>
              </div>
            </div>

            <div class="card" id="spravovani">
              <div class="card-header"><h5 class="card-title mb-0">Kalendář a příspěvky</h5></div>
              <div class="card-body">
                <ul>
                  <li><strong>Kalendář</strong> ukazuje měsíc. Kliknutím na den založíte nový příspěvek na ten den, kliknutím na příspěvek otevřete jeho detail. Opakované příspěvky se zobrazují i dopředu.</li>
                  <li><strong>Stavy:</strong> Koncept, Naplánováno, Odesílá se, Odesláno, Částečně (na některé účty se nepovedlo), Selhalo.</li>
                  <li><strong>Upravit:</strong> u konceptu, naplánovaného, selhaného a částečně odeslaného příspěvku. Účty, kam už příspěvek vyšel, se nezopakují.</li>
                  <li><strong>Duplikovat:</strong> vytvoří kopii včetně médií. Použijte ji i na úpravu příspěvku, který už je zveřejněný (ten na sítích změnit nejde).</li>
                  <li><strong>Zkusit znovu:</strong> u selhaného cíle odešle příspěvek znovu. Důvod chyby je v detailu příspěvku.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="limity">
              <div class="card-header"><h5 class="card-title mb-0">Limity sítí</h5></div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>Síť</th><th>Text</th><th>Média</th><th>Poznámka</th></tr></thead>
                  <tbody>
                    <tr><td>Facebook</td><td>bez praktického limitu</td><td>obrázky (galerie), video</td><td>text bez médií může mít kartu s odkazem</td></tr>
                    <tr><td>Instagram</td><td>2 200 znaků, nejvýše 30 štítků</td><td>povinné: obrázek JPEG (poměr 4:5 až 1,91:1) nebo video, carousel až 10</td><td>video se zveřejní jako Reel; odkaz není klikací</td></tr>
                    <tr><td>Threads</td><td>500 znaků</td><td>obrázek, carousel 2 až 10, video</td><td>jen jeden štítek; bez Reels a Stories</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card" id="dalsi">
              <div class="card-header"><h5 class="card-title mb-0">Statistiky, oznámení, zálohy</h5></div>
              <div class="card-body">
                <ul>
                  <li><strong>Statistiky:</strong> reakce, komentáře, sdílení, dosah a uložení publikovaných příspěvků za zvolené období. Obnovují se tlačítkem a jednou denně z cronu. Dosah a uložení vyžadují oprávnění k přehledům, bez nich zůstane „–“. Reakce a komentáře na Facebooku vyžadují oprávnění <code>pages_read_user_content</code> (v konfiguraci přihlášení v Metě); bez něj se zobrazí „–“ a upozornění. U Threads se jako dosah počítá počet zobrazení a sdílení je součet repostů a citací; vyžaduje oprávnění <code>threads_manage_insights</code> (po jeho přidání v Metě Threads propojte znovu).</li>
                  <li><strong>Nejlepší čas k publikování</strong> (Statistiky, a také odkazy pod polem <em>Kdy publikovat</em>): aplikace porovná každý příspěvek s průměrem jeho účtu a zjistí, ve které dny a hodiny vaše příspěvky dopadají nadprůměrně. Počítá se jen s vlastními daty za poslední 4 měsíce, takže doporučení se ukáže až od 8 změřených příspěvků a zpřesňuje se s každým dalším týdnem. Kliknutím na doporučený čas se nastaví nejbližší takový termín. Výsledek lze zobrazit pro všechny účty, jednu síť nebo jeden účet.</li>
                  <li><strong>Oznámení:</strong> e-mail při selhání příspěvku a při neplatném tokenu účtu. E-maily odesílá funkce <code>mail()</code> hostingu. Stejný e-mail slouží k obnově zapomenutého hesla.</li>
                  <li><strong>Hromadné plánování:</strong> v menu <em>Hromadné plánování</em> vložte tabulku zkopírovanou z Excelu nebo Google Tabulek (nebo nahrajte CSV). První řádek je hlavička (Text, Datum a čas, Účty, Odkaz, Štítky, Sada, První komentář, Obrázky), každý další řádek je jeden příspěvek. Obrázky můžete zadat jako adresy URL (stáhnou se do knihovny médií) nebo jako názvy souborů z knihovny. Po kliknutí na <em>Zkontrolovat</em> uvidíte u každého řádku, jestli je v pořádku, a teprve pak se vytvoří naplánované příspěvky, nebo jen koncepty ke kontrole. Řádky s chybou se přeskočí. Vzorový CSV si můžete stáhnout přímo na stránce.</li>
                  <li><strong>Integrace (API):</strong> vaše další aplikace (např. podklady pro e-shopy) mohou do plánovače posílat koncepty. V menu <em>Integrace (API)</em> vytvořte pro každou aplikaci klíč (zobrazí se jen jednou) a podle příkladu na té stránce pošlete JSON na adresu <code>inbox.php</code>. Text, odkaz, hashtagy, první komentář a obrázky (z URL, uloží se do knihovny médií) tak vzniknou jako <strong>koncepty</strong>. Na Přehledu uvidíte upozornění, že nějaké čekají. Zkontrolujete je, vyberete účty a naplánujete. Odeslání bez vašeho schválení API neumí.</li>
                  <li><strong>Zálohy:</strong> stažení zálohy (databáze + obrázky), nebo jen databáze. Jednou týdně se na hostingu uloží snímek databáze (4 nejnovější). Zip občas stáhněte i k sobě, záloha na stejném hostingu nechrání před jeho ztrátou. Databáze obsahuje přístupové tokeny účtů, ukládejte ji bezpečně. Obnova: přepište <code>app/data/planner.sqlite</code> a složku <code>uploads/</code>.<br><strong>Záloha na Google Drive</strong> (tlačítko <em>Propojit Google Drive</em> na stránce Zálohy): jednou týdně se z cronu nahraje zip do složky „Social planner – zálohy“ ve vašem Disku, ponechá se zadaný počet nejnovějších. Volitelně i s obrázky. Aplikace vidí jen soubory, které sama vytvořila (oprávnění <code>drive.file</code>).
                    <details class="mt-1"><summary>🔧 Nastavení v Google Cloud (správce)</summary><ol class="mb-0">
                      <li>console.cloud.google.com → vyberte nebo vytvořte projekt → <strong>APIs &amp; Services → Library</strong> → povolte <strong>Google Drive API</strong>.</li>
                      <li><strong>Google Auth Platform</strong> (OAuth consent screen): typ <em>External</em>, přidejte e-mail jako testovacího uživatele. Rozsah <code>.../auth/drive.file</code>.</li>
                      <li><strong>Clients → Create client</strong> → typ <strong>Web application</strong> → <em>Authorized redirect URIs</em>: <code><?= h(rtrim((string)cfg('base_url'), '/')) ?>/gdrive_connect.php</code> (přesně, včetně https).</li>
                      <li>Zkopírujte Client ID a Client secret do <code>app/config.php</code> jako <code>google_client_id</code> a <code>google_client_secret</code>.</li>
                      <li><strong>Důležité:</strong> pokud zůstane aplikace ve stavu <em>Testing</em>, Google zruší trvalý přístup už po 7 dnech a zálohy přestanou fungovat. V sekci <em>Audience</em> klikněte na <strong>Publish app</strong> (stav <em>In production</em>). Oprávnění <code>drive.file</code> nevyžaduje ověření aplikace Googlem, jen se při přihlášení zobrazí upozornění „aplikace není ověřena“, kde zvolte <em>Pokročilé → Pokračovat</em>.</li>
                    </ol></details></li>
                </ul>
              </div>
            </div>

            <div class="card" id="bezpecnost">
              <div class="card-header"><h5 class="card-title mb-0">Zabezpečení</h5></div>
              <div class="card-body">
                <ul>
                  <li>Na stránce <strong>Zabezpečení</strong> změníte heslo, nastavíte automatické odhlášení po nečinnosti a přidáte <strong>Passkey</strong> (přihlášení otiskem, obličejem nebo PIN zařízení; funguje jen na HTTPS, heslo zůstává jako záloha).</li>
                  <li>Po 5 chybných pokusech se přihlášení na 5 minut zamkne.</li>
                  <li>Zapomenuté heslo: na přihlašovací stránce <em>Zapomenuté heslo</em>. Odkaz přijde na e-mail z Oznámení, platí 30 minut a jde použít jednou. Bez nastaveného e-mailu obnova nefunguje.</li>
                  <li><strong>Z hostingu smažte</strong> soubor <code>setup.php</code>, pokud tam ještě je (<code>diag.php</code> od verze 1.0.0 smaže aplikace sama). Ověřte také, že složka <code>app/</code> není veřejně dostupná z prohlížeče (zkuste otevřít <code><?= h($base) ?>/app/config.php</code>, nesmí se zobrazit obsah).</li>
                </ul>
              </div>
            </div>

            <div class="card" id="potize">
              <div class="card-header"><h5 class="card-title mb-0">Řešení potíží</h5></div>
              <div class="card-body">
                <dl class="mb-0">
                  <dt>Příspěvek se neodeslal a zůstal „Naplánováno“</dt>
                  <dd>Neběží cron. Zkontrolujte jeho nastavení na hostingu a otevřete adresu cronu v prohlížeči. Mělo by se zobrazit „OK, zpracováno cílů: …“.</dd>
                  <dt>Chyba „(code 190)“ nebo účet má stav „Chyba – propojit znovu“</dt>
                  <dd>Vypršel nebo byl zrušen token. Na stránce <strong>Účty a propojení</strong> použijte tlačítko <strong>Facebook a Instagram</strong>, u Threads tlačítko <strong>Threads</strong>.</dd>
                  <dt>Stav „Částečně“</dt>
                  <dd>Některý účet selhal. V detailu příspěvku je důvod. Po opravě použijte <em>Zkusit znovu</em>, už odeslané účty se neopakují.</dd>
                  <dt>Instagram odmítl obrázek</dt>
                  <dd>Poměr stran musí být od 4:5 do 1,91:1 a soubor JPEG. Obrázek oříznete tlačítkem s nůžkami.</dd>
                  <dt>Instagram nebo Facebook neumí stáhnout médium</dt>
                  <dd>Adresa v <code>base_url</code> musí být veřejná a na HTTPS, protože sítě stahují soubory z vašeho webu. Složka <code>uploads/</code> musí být čitelná.</dd>
                  <dt>Příspěvek zůstal ve stavu „Odesílá se“</dt>
                  <dd>Skript se přerušil. Po 15 minutách se označí jako selhaný a můžete zvolit <em>Zkusit znovu</em>. Automaticky se kvůli možným duplicitám neopakuje.</dd>
                  <dt>Nenačetla se čísla ve Statistikách</dt>
                  <dd>Chybí oprávnění k přehledům nebo příspěvek je příliš nový. Zkuste tlačítko <em>Obnovit</em> a v tabulce se zobrazí chyba.</dd>
                  <dt>Nahrání souboru selhalo</dt>
                  <dd>Hosting omezuje velikost nahrávaných souborů (<code>upload_max_filesize</code>, <code>post_max_size</code>). Velká videa zmenšete, nebo limit zvyšte v nastavení PHP.</dd>
                </dl>
              </div>
            </div>

            <div class="card" id="mcp">
              <div class="card-header"><h5 class="card-title mb-0">AI asistenti (Claude, ChatGPT)</h5></div>
              <div class="card-body">
                <p>Social Planner je zároveň <strong>MCP server</strong>. Claude nebo ChatGPT se k němu připojí jako konektor a v konverzaci pak může:</p>
                <ul>
                  <li>vypsat účty a skupiny, naplánované příspěvky a koncepty v libovolném období,</li>
                  <li>shrnout statistiky a říct doporučený čas zveřejnění,</li>
                  <li>použít vaše sady hashtagů a šablony,</li>
                  <li>procházet <strong>Knihovnu médií</strong> včetně slidů (podle tagu, názvu, typu) a vložit soubor do konceptu,</li>
                  <li>zjistit dostupné <strong>proměnné a data</strong> (svátek, tabulka dat, hodnoty ze zdroje Allwyn, počasí, formáty) a ověřit, jak text s proměnnými dopadne v den zveřejnění,</li>
                  <li>uložit <strong>koncept</strong> příspěvku (text i s proměnnými, účty, navržený čas, hashtagy, obrázky z odkazů nebo z knihovny, první komentář). Neznámou proměnnou asistent hned ohlásí.</li>
                </ul>
                <p>Příklad: „Připrav na sobotu 18:00 příspěvek o jackpotu Sportky pro skupinu Trafika, použij slide Jackpot z knihovny.“ Nové nástroje (od verze 1.7.0) asistent uvidí, až konektor obnoví seznam nástrojů – případně ho v Claude / ChatGPT odpojte a připojte znovu.</p>
                <p>Asistent <strong>nic nezveřejní, nenaplánuje, neupraví ani nesmaže</strong>. Koncept vždy zkontrolujete a naplánujete sami v aplikaci.</p>
                <p><strong>Připojení:</strong> adresa serveru je <code><?= h($base) ?>/mcp/index.php</code>. Přesný postup pro Claude a ChatGPT je na stránce <a href="integrace.php#mcp">Integrace (API)</a>. Při připojení se přihlásíte do Social Planneru a potvrdíte přístup; u konceptů můžete oprávnění odškrtnout a asistent pak bude jen číst.</p>
                <p class="mb-0"><strong>Odebrání:</strong> na stránce Integrace (API) v tabulce Připojení asistenti. Přístupový token platí hodinu a asistent si ho sám obnovuje; po odebrání přestane fungovat okamžitě. Pokud připojení na hostingu selže s chybou 401 i po přihlášení, hosting nepředává hlavičku Authorization – zkontrolujte, že na serveru je soubor <code>mcp/.htaccess</code>.</p>
              </div>
            </div>

            <div class="card" id="neumi">
              <div class="card-header"><h5 class="card-title mb-0">Co aplikace neumí</h5></div>
              <div class="card-body">
                <ul class="mb-0">
                  <li>Publikovat do <strong>Facebook skupin</strong> (Meta tuto možnost pro aplikace třetích stran zrušila v dubnu 2024).</li>
                  <li>Upravit příspěvek, který už je na síti zveřejněný (použijte Duplikovat a starý smažte na síti).</li>
                  <li>Facebook Reels a video story na Facebooku, správu a čtení odpovědí na Threads.</li>
                  <li>Různé oříznutí obrázku pro různé účty (ořez je jeden pro celý příspěvek).</li>
                  <li>Proměnné v odkazu a štítcích.</li>
                </ul>
              </div>
            </div>

            <div class="card" id="verze">
              <div class="card-header"><h5 class="card-title mb-0">Verze a změny</h5></div>
              <div class="card-body">
                <p>Nainstalovaná verze: <strong><?= h(APP_NAME . ' ' . APP_VERSION) ?></strong>, PHP <?= h(PHP_VERSION) ?>.</p>
                <?= changelog_html((string)@file_get_contents(ROOT . '/CHANGELOG.md')) ?>
              </div>
            </div>

          </div>
        </div>
<?php layout_foot();
