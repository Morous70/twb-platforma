---
titulek: Jak zapnout přihlášení přes Google
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-zabezpeceni
souvisi: nastaveni-zabezpeceni, jak-pripojit-google-disk, hlasky-prihlaseni
klicova: google, přihlášení, propojit účet, odpojit, přesměrování, oauth
---
# Jak zapnout přihlášení přes Google

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Na přihlašovací stránce se objeví tlačítko **Přihlásit přes Google**. Přihlásit se tím smí jen jeden předem určený účet Google, ten, který propojíte. Heslo funguje dál.

## Co potřebujete

- Vyplněné údaje `google_client_id` a `google_client_secret` v souboru `config.php` na serveru. Jsou to stejné údaje, jaké aplikace používá pro Google Disk.
- Přístup do Google Cloud Console k tomuto OAuth klientovi.
- Scopy `openid` a `email` stačí. Pro přihlášení se žádná další oprávnění nepřidávají.

## Postup

### 1. Jednorázově v Google

1. V aplikaci otevřete **Nastavení → Zabezpečení**.
2. V části **Přihlášení přes Google** rozbalte **Co je potřeba nastavit v Google**. Uvidíte adresu, která končí `/login-google.php`.
3. V Google Cloud Console otevřete projekt aplikace a přejděte do **Google Auth Platform → Clients**. Otevřete OAuth klienta typu **Web application**, který aplikace používá (jeho Client ID je v `config.php`).
4. V poli **Authorized redirect URIs** přidejte adresu z předchozího kroku a uložte. Adresa se musí shodovat přesně, včetně `https`, případného `www` a cesty. Pole **Authorized JavaScript origins** nechte beze změny.
5. Pokud je v **Google Auth Platform → Audience** stav **Testing**, musí být váš Google účet v seznamu **Test users**.

### 2. Propojení účtu

6. V části **Přihlášení přes Google** klikněte na **Propojit Google účet**.
7. Vyberte účet Google, kterým se chcete přihlašovat, a potvrďte.
8. Vrátíte se do **Nastavení → Zabezpečení**. Objeví se hláška **Google účet pro přihlášení je propojený.** a text **Propojený účet:** s e-mailem.

[SNÍMEK: Nastavení → Zabezpečení, část Přihlášení přes Google s propojeným účtem]

Účet pro přihlášení nemusí být stejný jako účet, ke kterému je připojený Google Disk.

## Jak se přihlásit

Na přihlašovací stránce klikněte na **Přihlásit přes Google** a vyberte propojený účet. Tlačítko je vidět jen po propojení.

## Jak účet změnit nebo odpojit

- **Propojit jiný účet**: propojí nový účet, původní přestane platit.
- **Odpojit**: po potvrzení otázky **Odpojit Google účet? Přihlášení přes Google přestane fungovat. Heslo zůstane.** se objeví hláška **Google účet odpojen.** Tlačítko na přihlašovací stránce zmizí.

## Co se stane potom

Každé přihlášení se zapíše do **Protokolu** ve tvaru **Přihlášení: Google (e-mail)**. Propojení i odpojení se zapíše také. Obnovení zálohy vrátí i propojený účet z doby zálohy.

## Časté chyby

- **Tento Google účet se nesmí přihlásit.** Vybrali jste jiný účet než propojený. Pokus se zapíše do **Protokolu** a počítá se do omezení pěti pokusů.
- **Přihlášení přes Google se nezdařilo.** Zkuste to znovu. Podrobnosti jsou v **Protokolu**.
- **Google ukáže chybu a nevrátí vás do aplikace.** Nejspíš chybí adresa pro přesměrování. Doplňte ji podle kroků 3 a 4.
- **Propojení Google účtu se nezdařilo – podrobnosti v Protokolu.** Zkuste propojení znovu a podívejte se do **Protokolu**.
- **V config.php chybí google_client_id a google_client_secret.** Údaje doplňte v souboru `config.php` na serveru.
- **Přihlášení přes Google není nastavené.** Nejdřív účet propojte v **Nastavení → Zabezpečení**.

Další hlášky: [Hlášky přihlášení a zabezpečení](hlasky-prihlaseni).
