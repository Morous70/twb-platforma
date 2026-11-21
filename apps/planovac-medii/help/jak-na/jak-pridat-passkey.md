---
titulek: Jak se přihlašovat bez hesla (Passkey)
sekce: jak-na
skupina: Každodenní provoz
admin: ne
kontext: nastaveni-zabezpeceni
souvisi: nastaveni-zabezpeceni, zapomenute-heslo, hlasky-prihlaseni
klicova: passkey, přihlášení, otisk, obličej, pin, bezpečnostní klíč, bez hesla, zařízení
---
# Jak se přihlašovat bez hesla (Passkey)

## K čemu to je

Passkey je přihlášení otiskem prstu, obličejem, PINem nebo zámkem zařízení (případně bezpečnostním klíčem). Zařízení zaregistrujete jednou. Pak se na něm přihlásíte jedním potvrzením a heslo nepíšete. Heslo funguje dál.

## Co potřebujete

- Aplikaci otevřenou na adrese začínající `https://`.
- Zařízení a prohlížeč, který Passkey umí (většina současných telefonů a počítačů).
- Zapnutý zámek zařízení (otisk, obličej nebo PIN). Aplikace vždy chce, aby zařízení ověřilo, že jste to vy.

## Jak zaregistrovat zařízení

1. Přihlaste se heslem na zařízení, které chcete zaregistrovat.
2. V menu klikněte na **Nastavení** a otevřete kartu **Zabezpečení**.
3. V části **Passkey** napište do pole **Název nového zařízení** název, například „Telefon“. Když pole necháte prázdné, použije se název „Zařízení“.
4. Klikněte na **Přidat zařízení**.
5. Zařízení se zeptá na otisk, obličej nebo PIN. Potvrďte.
6. Objeví se hláška **Zařízení je zaregistrované.** a v seznamu přibude řádek s názvem.

[SNÍMEK: Nastavení → Zabezpečení, část Passkey se zaregistrovaným zařízením]

Každé zařízení zaregistrujte zvlášť (telefon, pracovní počítač).

## Jak se přihlásit

1. Otevřete aplikaci. Na přihlašovací stránce klikněte na **🔑 Přihlásit pomocí Passkey**.
2. Potvrďte otiskem, obličejem nebo PINem.
3. Aplikace se otevře. Heslo psát nemusíte.

[SNÍMEK: přihlašovací stránka s tlačítkem Přihlásit pomocí Passkey]

Tlačítko se na přihlašovací stránce ukáže jen tehdy, když je v aplikaci zaregistrované aspoň jedno zařízení a prohlížeč Passkey podporuje.

## Jak zařízení přejmenovat nebo odebrat

1. Otevřete **Nastavení → Zabezpečení**.
2. U zařízení klikněte na **Přejmenovat**, upravte název a klikněte na **Uložit**.
3. Nebo klikněte na **Odebrat** a potvrďte otázku **Odebrat zařízení „…“? Na něm se pak nepůjde přihlásit pomocí Passkey.**

Odebrání zruší platnost v aplikaci. Klíč na samotném zařízení zůstane, ale aplikace ho už nepřijme. Pokud zařízení ztratíte, odeberte ho.

## Co se stane potom

- Každé přihlášení se zapíše do **Protokolu** ve tvaru **Přihlášení: Passkey (název zařízení)**.
- V seznamu zařízení je u každého vidět, kdy bylo **přidáno** a kdy bylo **naposledy** použité (nebo **zatím nepoužito**).
- Neúspěšné pokusy se počítají do stejného omezení jako u hesla: po pěti pokusech se přihlášení na pět minut zablokuje.

## Časté chyby

- **Passkey funguje jen na adrese s HTTPS.** Otevřete aplikaci přes adresu začínající `https://`.
- **Tento prohlížeč Passkey nepodporuje.** Použijte jiný, novější prohlížeč.
- **Toto zařízení už je zaregistrované.** Je už v seznamu, nic dalšího dělat nemusíte.
- **Registrace byla zrušena nebo vypršela.** Klikněte na **Přidat zařízení** znovu a potvrďte hned.
- **Přihlášení bylo zrušeno nebo vypršelo.** Klikněte na tlačítko znovu a potvrďte hned.
- **Přihlášení pomocí Passkey se nezdařilo.** Zařízení v aplikaci nemusí být zaregistrované, nebo ho někdo odebral. Přihlaste se heslem.
- **Tlačítko Passkey na přihlašovací stránce není.** V aplikaci zatím není zaregistrované žádné zařízení, nebo prohlížeč či adresa Passkey nepodporují.

Další hlášky: [Hlášky přihlášení a zabezpečení](hlasky-prihlaseni).
