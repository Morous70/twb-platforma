<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Integrace (API)', 'integrace.php');
$ep = rtrim((string)cfg('base_url'), '/') . '/inbox.php';
$mcpUrl = rtrim((string)cfg('base_url'), '/') . '/mcp/index.php';
?>
        <h1 class="h3 mb-3">Integrace (API)</h1>
        <p class="text-muted">Vaše další aplikace mohou posílat podklady (text, odkaz, obrázky) do plánovače. Vždy vzniknou jako <strong>koncepty</strong>, které si zkontrolujete, doplníte účty a naplánujete.</p>
        <div class="card" id="mcp">
          <div class="card-header"><h5 class="card-title mb-0">AI asistenti (MCP) – Claude, ChatGPT</h5><div class="small text-muted mt-1">Asistent může číst vaše účty, plán, statistiky a doporučené časy a ukládat <strong>koncepty</strong>. Nic nezveřejní, nenaplánuje ani nesmaže.</div></div>
          <div class="card-body">
            <label class="form-label" for="mcpUrl">Adresa serveru (URL konektoru)</label>
            <div class="input-group mb-1" style="max-width:640px"><input class="form-control font-monospace" id="mcpUrl" readonly value="<?= h($mcpUrl) ?>"><button class="btn btn-outline-secondary" type="button" id="mcpCopy">Kopírovat</button></div>
            <div class="form-text mb-3">Zadejte ji celou, včetně <code>/index.php</code>. Kratší tvar <code>…/mcp</code> hosting přesměrovává a Claude se přes přesměrování nepřipojí. Už připojený ChatGPT s adresou <code>…/mcp/</code> funguje dál.</div>
            <div class="row g-3">
              <div class="col-lg-6">
                <div class="border rounded p-3 h-100">
                  <h6 class="mb-2">Claude (desktop, web i mobil)</h6>
                  <ol class="small mb-0 ps-3">
                    <li>V aplikaci Claude otevřete <strong>Nastavení → Konektory</strong> (Settings → Connectors, v novějších verzích Customize → Connectors).</li>
                    <li>Zvolte <strong>Přidat vlastní konektor</strong> (Add custom connector), název třeba „Social Planner“, vložte adresu serveru. Pokročilá nastavení nechte prázdná.</li>
                    <li>Klikněte na <strong>Připojit</strong>. Otevře se přihlášení Social Planneru – přihlaste se a povolte přístup.</li>
                    <li>V konverzaci pak stačí napsat např. „Co mám naplánováno na příští týden?“</li>
                  </ol>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="border rounded p-3 h-100">
                  <h6 class="mb-2">ChatGPT</h6>
                  <ol class="small mb-0 ps-3">
                    <li>Na chatgpt.com otevřete <strong>Nastavení → Aplikace a konektory → Pokročilá nastavení</strong> a zapněte <strong>Režim vývojáře</strong> (Developer mode).</li>
                    <li>Zvolte <strong>Vytvořit</strong> konektor, vložte adresu serveru, ověření <strong>OAuth</strong>.</li>
                    <li>Přihlaste se do Social Planneru a povolte přístup. V chatu konektor zapnete v nabídce nástrojů.</li>
                    <li class="text-muted">U plánu Plus může ChatGPT používat jen nástroje pro čtení; koncepty v něm nejspíš vytvářet nepůjde.</li>
                  </ol>
                </div>
              </div>
            </div>
            <h6 class="mt-4 mb-2">Připojení asistenti</h6>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Aplikace</th><th>Návrat na</th><th>Oprávnění</th><th>Připojeno</th><th>Naposledy použito</th><th class="text-end">Volání</th><th></th></tr></thead><tbody id="mcpRows"><tr><td colspan="7" class="text-muted">Načítám…</td></tr></tbody></table></div>
          </div>
            <h6 class="mt-4 mb-2 d-flex flex-wrap align-items-center gap-2">Diagnostika připojení
              <button type="button" class="btn btn-outline-secondary btn-sm" id="mcpTest">Otestovat dostupnost</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="mcpLogReload">Obnovit záznam</button>
              <button type="button" class="btn btn-link btn-sm text-danger" id="mcpLogClear">Vymazat záznam</button></h6>
            <div id="mcpTestOut"></div>
            <div class="small text-muted mb-2">Poslední požadavky asistentů na server (nejnovější nahoře). Když připojení selže, zkuste ho znovu a pak sem pošlete snímek obrazovky.</div>
            <div class="table-responsive" style="max-height:420px;overflow:auto"><table class="table table-sm align-middle mb-0 small"><thead class="sticky-top bg-white"><tr><th>Čas</th><th>Kde</th><th>Výsledek</th><th>Podrobnosti</th><th>Klient (User-Agent)</th></tr></thead><tbody id="mcpLog"><tr><td colspan="5" class="text-muted">Načítám…</td></tr></tbody></table></div>
          </div>
          <div class="card-footer small text-muted">Koncepty od asistenta najdete v Příspěvcích mezi koncepty (zdroj „MCP: …“). Odebráním připojení přestane asistent okamžitě fungovat; v jeho aplikaci pak konektor smažte nebo připojte znovu. Na hostingu musí fungovat soubory <code>mcp/.htaccess</code> a <code>.well-known/.htaccess</code> (jsou součástí aplikace).</div>
        </div>

        <div id="newKeyBox"></div>
        <div id="alertNew"></div>
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">API klíče</h5></div>
          <div class="card-body">
            <form class="row g-2 mb-3" id="frm"><div class="col-sm-6 col-md-4"><input class="form-control" id="kname" maxlength="60" placeholder="Název aplikace, např. Eshop A" required></div><div class="col-auto"><button class="btn btn-primary">Vytvořit klíč</button></div></form>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Aplikace</th><th>Klíč</th><th>Vytvořen</th><th>Naposledy použit</th><th class="text-end">Použití</th><th></th></tr></thead><tbody id="rows"><tr><td colspan="6" class="text-muted">Načítám…</td></tr></tbody></table></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h5 class="card-title mb-0">Jak aplikace koncept pošle</h5></div>
          <div class="card-body">
            <p>Adresa: <code id="ep"><?= h($ep) ?></code>. Pošlete požadavek <strong>POST</strong> s JSON a hlavičkou <code>Authorization: Bearer VÁŠ_KLÍČ</code>.</p>
<pre class="border rounded p-3 small" id="ex">curl -X POST <?= h($ep) ?> \
  -H "Authorization: Bearer spk_…" \
  -H "Content-Type: application/json" \
  -d '{
    "external_id": "produkt-1234",
    "message": "Nová kolekce je tady! 🎉",
    "link": "https://www.eshop.cz/produkt/1234",
    "tags": ["novinka", "eshop"],
    "images": ["https://www.eshop.cz/img/1234-1.jpg", "https://www.eshop.cz/img/1234-2.jpg"],
    "first_comment": "Objednávky do 15:00 odesíláme ještě dnes.",
    "group": "Hlavní účty",
    "suggested_at": "2026-12-01T10:00:00+01:00"
  }'</pre>
            <table class="table table-sm small">
              <thead><tr><th>Pole</th><th>Popis</th></tr></thead>
              <tbody>
                <tr><td><code>message</code></td><td>Text příspěvku (max. 5 000 znaků). Lze použít proměnné <code>{{…}}</code> z plánovače.</td></tr>
                <tr><td><code>link</code></td><td>Odkaz (http/https), např. na produkt.</td></tr>
                <tr><td><code>images</code></td><td>Pole adres obrázků (max. 10, každý do 15 MB). Plánovač je stáhne do knihovny médií a PNG, WebP a GIF převede na JPEG.</td></tr>
                <tr><td><code>library_ids</code></td><td>Pole ID souborů, které už jsou v Knihovně médií (i slidy; video jen samotné). Dají se před <code>images</code>, celkem max. 10.</td></tr>
                <tr><td><code>tags</code></td><td>Hashtagy jako pole nebo text (s <code>#</code> i bez).</td></tr>
                <tr><td><code>tag_sets</code></td><td>Volitelně pole názvů uložených sad hashtagů (viz editor příspěvku). Přidají se k <code>tags</code>.</td></tr>
                <tr><td><code>first_comment</code></td><td>Volitelný první komentář.</td></tr>
                <tr><td><code>account_ids</code> / <code>group</code></td><td>Volitelně předvybrané účty (ID) nebo název skupiny účtů. Seznam ID: <code>GET inbox.php?resource=accounts</code>.</td></tr>
                <tr><td><code>suggested_at</code></td><td>Volitelně navržený čas (ISO 8601). Je jen předvyplněný, nic se podle něj neodešle.</td></tr>
                <tr><td><code>external_id</code></td><td>Doporučeno: ID podkladu ve vaší aplikaci. Opakované odeslání stejného ID nevytvoří duplicitu.</td></tr>
              </tbody>
            </table>
            <p class="small mb-1"><strong>Odpověď</strong> <code>201</code>: <code>{"id":12,"status":"draft","duplicate":false,"edit_url":"…"}</code>. Při opakovaném <code>external_id</code> vrátí <code>200</code> s <code>"duplicate":true</code>. Chyby: <code>401</code> špatný klíč, <code>422</code> neplatná data (text chyby popisuje pole), <code>413</code> příliš velký požadavek.</p>
            <p class="small mb-1"><strong>Pozor na hosting:</strong> některé servery hlavičku <code>Authorization</code> zahodí (pak přijde 401 i se správným klíčem). V tom případě pošlete klíč v hlavičce <code>X-API-Key: spk_…</code>, funguje stejně.</p>
            <p class="small mb-0"><strong>Stav příspěvku:</strong> <code>GET inbox.php?external_id=produkt-1234</code> (nebo <code>?id=12</code>) vrátí stav (<code>draft</code>, <code>scheduled</code>, <code>done</code>…) a výsledky po účtech. Klíč vidí jen koncepty, které sám vytvořil.</p>
          </div>
        </div>
<?php layout_foot(['integrace.js']);
