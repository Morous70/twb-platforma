---
titulek: Jak připojit Google Disk a nastavit cílovou složku
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-google-disk
souvisi: jak-se-zmena-dostane-na-televizi, jak-nastavit-prehravac, hlasky-google-disk
klicova: google disk, připojit, cílová složka, oprávnění, editor, odstraněné soubory, koš
---
# Jak připojit Google Disk a nastavit cílovou složku

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Aplikace připravuje obsah pro televizi do jedné složky na Google Disku. Odtud si ho přehrávač stahuje. Aby to fungovalo, musí být aplikace připojená k účtu Google a znát cílovou složku.

## Postup

1. V menu klikněte na **Nastavení**.
2. Otevřete kartu **Google Disk**.
3. Pokud je stav **Nepřipojeno**, klikněte na **Připojit Google Disk** a přihlaste se k účtu Google, který má k cílové složce přístup. Potvrďte oprávnění.
4. Po návratu do aplikace je u stavu uvedeno **Připojeno** a název účtu.
5. Do pole **Cílová složka** vložte odkaz na složku na Google Disku (nebo její ID). Je to složka, kterou čte přehrávač.
6. Zvolte, co se stane s odstraněnými soubory (viz níže).
7. Klikněte na **Uložit**. Vedle pole se objeví název složky.

[SNÍMEK: Nastavení → Google Disk, stav Připojeno a vyplněná Cílová složka]

## Důležité: účet musí mít právo zapisovat

Složku musí vlastnit připojený účet, nebo ji musí mít sdílenou jako **Editor**. Bez toho aplikace nemůže soubory nahrávat.

## Pozor na obsah složky

Ze složky se odstraní všechny soubory, které právě nemají běžet. Podsložky zůstávají beze změny. Cílová složka proto nesmí obsahovat nic, co chcete zachovat.

## Odstraněné soubory

Volba **Odstraněné soubory** určuje, co se stane se soubory, které mají být ze složky pryč:
- **přesunout do koše Google Disku**: lze je 30 dní obnovit,
- **smazat trvale**.

## Jak Google Disk odpojit

Na kartě **Google Disk** klikněte na **Odpojit**. Aplikace se zeptá: „Odpojit Google Disk? Obrazovka se pak přestane aktualizovat.“ Po odpojení se obsah televize nemění, dokud disk znovu nepřipojíte.

## Co se stane potom

Aplikace při každé synchronizaci upraví obsah složky. Viz [Jak se změna dostane na televizi](jak-se-zmena-dostane-na-televizi).

## Časté chyby

- **Složka neexistuje.** Odkaz nebo ID je špatně, nebo účet složku nevidí.
- **V config.php chybí google_client_id a google_client_secret.** Karta tuto zprávu zobrazí, pokud na serveru chybí přístupové údaje ke Google. Doplní se v souboru `config.php` na serveru.
- **Připojený Google účet nemá do cílové složky právo zapisovat – složku mu nasdílejte jako Editor.** Nasdílejte složku účtu jako Editor.
- **Soubor vlastní jiný účet.** Aplikace ho nemůže odstranit. Vlastník musí soubor smazat, nebo změnit vlastnictví.
- **V Přehledu je varování „Google Disk není připojen“ nebo „Není nastavena cílová složka“.** Doplňte kroky výše.
