---
titulek: Nastavení: Zabezpečení
sekce: nastaveni
admin: ano
kontext: nastaveni-zabezpeceni
souvisi: jak-zmenit-heslo, jak-pridat-passkey, jak-prihlasit-pres-google, zapomenute-heslo
klicova: zabezpečení, heslo, google, passkey, přihlášení, zařízení, odhlášení, nečinnost
---
# Nastavení: Zabezpečení

> 🔧 **Pro správce.**

Karta má čtyři části. Nemá tlačítko **Uložit**, každá akce se provede hned.

## Heslo

| Pole nebo tlačítko | Význam |
|---|---|
| **Současné heslo** | heslo, které teď platí |
| **Nové heslo** | nové heslo, aspoň 8 znaků |
| **Změnit heslo** | změní heslo |

Heslo funguje vždy, i když zapnete Google nebo Passkey. Pod formulářem je informace, že zapomenuté heslo jde obnovit na přihlašovací stránce a odkaz přijde na e-mail z karty **Upozornění**.

Pokud v kartě **Upozornění** není vyplněný e-mail, objeví se oranžové upozornění **V kartě Upozornění není vyplněný e-mail, takže obnovení zapomenutého hesla zatím nebude fungovat.**

Postup: [Jak změnit heslo](jak-zmenit-heslo), [Zapomenuté heslo](zapomenute-heslo).

## Automatické odhlášení

| Pole nebo tlačítko | Význam |
|---|---|
| **Odhlásit po nečinnosti (minuty)** | za jak dlouho bez práce vás aplikace odhlásí. Výchozí je 60. Povoleno je 5 až 1440, hodnota 0 znamená nikdy |
| **Uložit** | uloží hodnotu |

Dvě minuty před odhlášením se objeví okno **Brzy budete odhlášeni** s odpočtem a tlačítkem **Zůstat přihlášen**. Neuložené změny se při odhlášení ztratí.

Za práci se počítá klikání, psaní a posouvání na stránce. Automatické obnovování **Přehledu** a **Protokolu** se nepočítá, takže otevřená záložka, ve které nic neděláte, po čase vyprší. Nastavená doba platí pro všechna zařízení.

Po odhlášení se zobrazí přihlašovací stránka s hláškou **Byli jste odhlášeni z důvodu nečinnosti.** V **Protokolu** je záznam **Odhlášení z důvodu nečinnosti.**

## Přihlášení přes Google

| Pole nebo tlačítko | Význam |
|---|---|
| **Propojit Google účet** | zahájí propojení účtu, kterým se budete smět přihlásit |
| **Propojit jiný účet** | nahradí propojený účet jiným |
| **Odpojit** | zruší propojení |
| **Co je potřeba nastavit v Google** | adresa pro přesměrování, kterou musíte přidat v Google |

Postup: [Jak zapnout přihlášení přes Google](jak-prihlasit-pres-google).

## Passkey

| Pole nebo tlačítko | Význam |
|---|---|
| **Název nového zařízení** | jak se zařízení bude jmenovat v seznamu |
| **Přidat zařízení** | zaregistruje zařízení, na kterém jste |
| **Přejmenovat** | změní název zařízení |
| **Odebrat** | zruší platnost zařízení v aplikaci |

U každého zařízení je vidět, kdy bylo přidáno a kdy bylo naposledy použité.

Postup: [Jak se přihlašovat bez hesla (Passkey)](jak-pridat-passkey).

## Zálohy

Obnovení zálohy vrátí heslo i propojený Google účet z doby zálohy. Zaregistrovaná zařízení Passkey obnovení nemění.
