# Social Planner – plánovač příspěvků (Facebook stránky, Instagram, Threads)

Verze je v `app/lib/version.php`, změny v `app/CHANGELOG.md`. Podrobný návod je v aplikaci v **Nápovědě**.

PHP 7.4+ (doporučeno 8.1+; rozšíření pdo_sqlite, curl, fileinfo, mbstring), SQLite, čistý JavaScript, UI AdminKit (Bootstrap 5). Bez Composeru a knihoven.

## Struktura (od verze 1.0.0)
Obsah zipu se nahrává přímo do kořene webu (document root), např. na Wedosu do `www/domains/…`.

```
/                    index.php = veřejná úvodní stránka; ostatní stránky administrace a vstupní body (prehled.php, kalendar.php, novy.php, api.php, cron.php, connect.php …)
assets/js/           skripty stránek
assets/css/app.css   vlastní styly
assets/adminkit/     šablona AdminKit (MIT)
assets/css/landing.css, assets/fonts/, assets/img/   úvodní stránka (písmo Plus Jakarta Sans, OFL)
uploads/             nahrané obrázky a videa (musí být zapisovatelná a veřejná)
mcp/                 MCP server pro Claude / ChatGPT (adresa konektoru {base_url}/mcp/index.php); .htaccess předává hlavičku Authorization
oauth/               OAuth 2.1 pro MCP: register.php, authorize.php (souhlas), token.php, revoke.php
.well-known/         metadata OAuth (oauth-authorization-server, oauth-protected-resource); .htaccess mapuje adresy bez přípony
app/                 neveřejná část, chráněná app/.htaccess
  config.php         konfigurace (vytvoříte z config.example.php, v zipu NENÍ)
  lib/               knihovny PHP
  data/              databáze planner.sqlite a log (zapisovatelná)
  CHANGELOG.md, README.md
```

Adresy stránek jsou v kořeni a nemění se. Adresy registrované jinde musí zůstat stejné:
`connect.php` (Meta), `threads_connect.php` a `threads_hook.php` (Threads), `gdrive_connect.php` (Google), `cron.php` (cron), `inbox.php` (API), `mcp/`, `oauth/*` a `.well-known/*` (MCP konektory). MCP předpokládá instalaci v kořeni domény (`base_url` bez podsložky).

## Instalace
1. Nahrajte obsah zipu do kořene webu.
2. `app/config.example.php` zkopírujte na `app/config.php` a vyplňte `app_id`, `app_secret`, `base_url` (adresa kořene, bez lomítka na konci), `cron_key`, případně klíče Threads a Google.
3. Heslo: otevřete `setup.php`, vygenerovaný řádek vložte do `admin_hash` v `app/config.php` a `setup.php` smažte.
4. Ověřte, že `https://VASE-DOMENA/app/config.php` ani `…/app/CHANGELOG.md` se v prohlížeči nezobrazí (u nginx zakažte složku `app/` ručně).
5. **Nutné HTTPS** – Instagram a Threads stahují média z `base_url/uploads/…`.

## Aktualizace
Nahrajte obsah nového zipu přes stávající soubory. `app/config.php`, `app/data/` a `uploads/` zip nepřepíše (nejsou v něm). Databázi aplikace doplní sama a po změně verze jednorázově smaže soubory, které nová verze už nepoužívá (seznam v `app/lib/version.php`).

## Cron (každou minutu)
`https://VASE-DOMENA/cron.php?key=CRON_KEY` nebo `php /cesta/k/webu/cron.php`.

## Poznámky
- `dry_run => true` v configu: nic se neodešle, vše se jen zapíše do `app/data/planner.log`.
- Tokeny jsou v databázi nešifrovaně – zálohy uchovávejte bezpečně.
- Verze Graph API (`graph_version`) občas zastarává – zvyšte ji podle dokumentace Mety.
