---
titulek: Přihlášení a zabezpečení
sekce: sprava
admin: ne
souvisi: zalohy
klicova: heslo, google, passkey, zámek
---
# Přihlášení a zabezpečení

- **Heslo** změníte v **Nastavení → Zabezpečení**. Po pěti chybných pokusech se přihlášení na chvíli zamkne.
- **Google**: v config.php vyplňte `google_client_id` a `google_client_secret`, pak v Nastavení propojte účet.
- **Passkey** (otisk prstu, obličej, PIN zařízení) funguje jen přes HTTPS.

Po nečinnosti se aplikace odhlásí (`session_timeout_min` v config.php). Tajné údaje patří jen do config.php na serveru.
