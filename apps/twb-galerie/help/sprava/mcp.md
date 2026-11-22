---
titulek: Připojení AI (MCP)
sekce: sprava
admin: ne
souvisi: api, autor-a-licence, kategorie-a-tagy
klicova: mcp, ai, claude, chatgpt, konektor, connector, oauth, umělá inteligence, prompt, model, vygenerováno
---
# Připojení AI (MCP)

Galerie má vlastní **MCP server** (Model Context Protocol). Přes něj do ní Claude, ChatGPT a další AI asistenti můžou rovnou poslat obrázek, video nebo PDF,
které vytvořili, i s názvem, tagy, kategorií a údaji o AI (model a prompt). Umějí také hledat v galerii a upravovat údaje médií. **Mazat média nemůžou.**

Adresa MCP serveru je na stránce **API a MCP**, má tvar `https://galerie.kazbo.eu/mcp.php`.

## Připojení v Claude (claude.ai, aplikace v počítači i v telefonu)
1. V Claude otevřete **Nastavení → Konektory** a zvolte **Přidat vlastní konektor**.
2. Název napište třeba „TWB Galerie“, do adresy vložte adresu MCP serveru. Pole pro OAuth Client ID a tajemství nechte prázdná.
3. Claude vás přesměruje do galerie. Když nejste přihlášení, přihlaste se (heslem, Google nebo Passkey).
4. Na stránce **Povolit přístup?** zkontrolujte, že se vracíte na *claude.ai*, a povolte. Zaškrtnutím „Nahrávat nová média…“ povolíte zápis; bez něj Claude smí jen číst.
5. V konverzaci zapněte konektor (ikona nástrojů) a napište třeba: *„Vygeneruj obrázek podzimního lesa a ulož ho do galerie s tagy podzim a les.“*

Připojení vidíte v tabulce **Připojení AI** na stránce API a MCP, kde ho jde kdykoli odebrat. Opětovné připojení ze stejné aplikace nahradí to předchozí.
ChatGPT (Nastavení → Konektory, režim pro vývojáře) se připojuje stejně.

## Claude Code a jiné klienty s API klíčem
Klient, který umí poslat vlastní hlavičku, se nemusí přihlašovat přes OAuth: stačí [API klíč](api) v hlavičce `Authorization: Bearer tgk_…`.
Klíč **Čtení** dovolí jen hledání a detail, klíč **Čtení i zápis** i nahrávání a úpravy. Platí pro něj stejný limit požadavků jako u API.

```
claude mcp add --transport http galerie https://galerie.kazbo.eu/mcp.php --header "Authorization: Bearer tgk_…"
```

## Nástroje
| Nástroj | Co dělá | Oprávnění |
|---|---|---|
| `nahraj_medium` | uloží nové médium z adresy (`url`) nebo z base64 (`data_base64`, do 20 MB) s názvem, tagy, kategorií, poznámkou, autorem a licencí | zápis |
| `hledej_media` | seznam médií s hledáním (`q`) a filtry (druh, kategorie, tagy, orientace, archiv), stránkování | čtení |
| `detail_media` | všechny údaje média podle id nebo odkazu: atribuce, veřejný odkaz, náhledy 150–1920 px | čtení |
| `uprav_medium` | změní název, poznámku, kategorii, tagy, autora, licenci nebo archiv | zápis |
| `seznam_kategorii` | kategorie s počtem médií | čtení |
| `vytvor_kategorii` | založí kategorii | zápis |
| `seznam_licenci` | číselník licencí (kódy pro `licence_kod`) | čtení |
| `seznam_tagu` | používané tagy s počtem médií | čtení |

## Médium vytvořené AI
Když AI u `nahraj_medium` vyplní `ai_model` nebo `ai_prompt`, galerie:

- nastaví licenci **Vygenerováno AI** (jde přepsat polem `licence_kod`),
- jako autora uvede název modelu (jde přepsat polem `autor`),
- do poznámky připíše „Vytvořeno AI (model).“ a prompt – podle obojího pak jde médium najít hledáním.

Typ souboru galerie ověří podle obsahu stejně jako při běžném nahrání.

## ZIP s obrázky
Víc obrázků najednou pošle AI jako ZIP: `nazev` nebo adresa končí na `.zip`, u `data_base64` stačí, že obsah je ZIP. Každý obrázek se uloží jako samostatné médium a tagy, kategorie, poznámka, autor, licence i údaje o AI (`ai_model`, `ai_prompt`) platí pro všechny – mají přednost před metadaty obrázků. Jiné soubory v ZIPu se přeskočí. Odpověď vrátí seznam `media`, přeskočené soubory `preskoceno` a `chyby`.
Soubor z adresy se stahuje jen z veřejných adres (ne z vnitřní sítě). Větší soubory než 20 MB posílejte přes adresu.

## Zabezpečení
- Přihlášení konektoru je OAuth 2.1 s PKCE; tokeny platí hodinu a obnovují se, v databázi je jen jejich otisk. Připojení nejsou v zálohách.
- Každé nahrání a úprava přes MCP se zapíše do protokolu i s názvem aplikace.
- Galerie musí běžet v kořeni domény (např. `https://galerie.kazbo.eu`), protože klienti hledají metadata přihlášení na adrese `/.well-known/oauth-authorization-server`.
