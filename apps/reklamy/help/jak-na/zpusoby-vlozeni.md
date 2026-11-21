---
titulek: Způsoby vložení
sekce: jak-na
admin: ne
kontext: zona
souvisi: vlozeni-na-web, weby-a-zony
klicova: iframe, popup, xml-rpc, e-mail, php, json
---
# Způsoby vložení

| Způsob | Kdy |
|---|---|
| Asynchronní JavaScript | doporučeno; nezdržuje stránku, víc zón jedním požadavkem |
| JavaScript (document.write) | starší weby; ne na stránkách načítaných asynchronně |
| Rám (iframe) | web nepovoluje cizí skripty; reklama je oddělená |
| Obrázek s odkazem | e-maily a místa bez JavaScriptu; jen obrázkové bannery a slidy |
| Vyskakovací okno | otevře se při prvním kliknutí na stránce |
| PHP na serveru | banner načte server webu, funguje bez JavaScriptu |
| XML-RPC | stávající kód pro Revive (`openads.view`) stačí přesměrovat |
| JSON | vlastní aplikace |

U **obrázku s odkazem** nahraďte NAHODNE v obou adresách stejnou jedinečnou hodnotou (např. ID e-mailu) – obrázek a kliknutí pak vedou na stejný banner.

U vložení ze serveru (PHP, XML-RPC) nejsou k dispozici cookies návštěvníka, proto se tam neuplatní frekvence; cílení na stránku potřebuje adresu stránky v parametru.
