---
titulek: Jak se změna dostane na televizi
sekce: zaciname
admin: ne
kontext: prehled
souvisi: prvni-obrazek, jak-vynutit-synchronizaci, televize-se-nemeni
klicova: synchronizace, google disk, prodleva, cron, přehrávač, televize
---
# Jak se změna dostane na televizi

## K čemu je tato stránka

Po uložení změny se obraz na televizi nezmění okamžitě. Tato stránka vysvětluje proč a jak dlouho to trvá.

## Cesta změny

1. V aplikaci uložíte scénář nebo nahrajete soubor.
2. Aplikace pravidelně vyhodnocuje, co má právě běžet. Do cílové složky na **Google Disku** nahraje soubory, které mají běžet, a ostatní z ní odstraní. Tomu se říká **synchronizace**.
3. Aplikace dá přehrávači v trafice pokyn, aby si obsah složky z Google Disku stáhl. Přehrávač ho pak promítá.

Aplikace tedy neposílá obraz přímo do televize. Připraví složku na Google Disku a přehrávač si ji stáhne.

## Kdy se synchronizuje

- **Automaticky** zhruba každých 5 minut. Spouští ji pravidelná úloha na serveru (správce jí říká **cron**).
- **Hned po kliknutí** na **Synchronizovat nyní** v **Přehledu**.

Uložení scénáře nebo nahrání souboru synchronizaci samo nespouští. Pokud nechcete čekat, klikněte na **Synchronizovat nyní**.

Aplikace také hlídá hranice časových oken. Když například scénář začíná v 9:00, při nejbližší synchronizaci po 9:00 se složka přepne.

## Co se stane potom

Soubory, které právě nemají běžet, se ze složky na Google Disku odstraní. Podle nastavení správce jdou do koše Google Disku, nebo se smažou trvale. Vaše originály v aplikaci zůstávají.

Pokyn pro přehrávač se odešle do minuty po změně složky na Google Disku. Přehrávač má kromě toho vlastní pravidelnou synchronizaci (interval se nastavuje přímo v přehrávači, správce ho může mít třeba na 60 minut). Ta slouží jako záloha, kdyby pokyn nedorazil.

Celkově se tedy nový obsah na televizi obvykle objeví **do několika minut** po uložení změny (synchronizace Google Disku běží každých 5 minut a pokyn přehrávači odejde do minuty). Pokud nespěcháte, nemusíte dělat nic. Rychleji to jde po kliknutí na **Synchronizovat nyní**.

## Časté chyby

- **Čekání na okamžitou změnu bez kliknutí na Synchronizovat nyní.** Počkejte do 5 minut nebo synchronizujte ručně.
- **Změna se neprojeví, protože scénář neběží.** Zkontrolujte v **Přehledu** panel **Právě teď**.
- **Červené nebo oranžové hlášení nahoře v Přehledu.** Přečtěte si ho, často říká, co je špatně (například chybí výchozí scénář nebo není připojen Google Disk).

Když to nepomůže: [Na televizi se nic nemění](televize-se-nemeni).
