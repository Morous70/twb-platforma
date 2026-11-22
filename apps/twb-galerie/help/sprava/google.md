---
titulek: Přihlášení přes Google
sekce: sprava
admin: ne
souvisi: prihlaseni
klicova: google, oauth, redirect
---
# Přihlášení přes Google

1. V Google Cloud Console otevřete **Google Auth Platform → Clients** a vytvořte klienta typu **Web application**.
2. Do **Authorized redirect URIs** přidejte přesně adresu, kterou ukazuje **Nastavení → Zabezpečení → Přihlášení přes Google** (`https://galerie.kazbo.eu/google.php`).
3. V **Audience** zvolte **External**; dokud je aplikace ve stavu **Testing**, přidejte svůj účet do **Test users**.
4. Client ID a Client secret vložte do `config.php` na serveru (`google_client_id`, `google_client_secret`).
5. V **Nastavení → Zabezpečení** klikněte na **Propojit Google účet** a vyberte účet. Přihlásit se smí jen tento jeden účet.
