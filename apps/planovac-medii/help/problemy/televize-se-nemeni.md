---
titulek: Na televizi se nic nemění
sekce: problemy
admin: ne
kontext: prehled
souvisi: jak-se-zmena-dostane-na-televizi, jak-vynutit-synchronizaci, hlasky-google-disk, jak-spustit-diagnostiku
klicova: televize, nemění se, stará obrazovka, synchronizace, cron, nic se nezobrazuje
---
# Na televizi se nic nemění

## Co se děje

Upravili jste scénář nebo nahráli soubor, ale televize ukazuje pořád to samé.

## Zkuste to po pořádku

1. **Počkejte pár minut.** Změna se na televizi dostane po synchronizaci, obvykle do několika minut. Viz [Jak se změna dostane na televizi](jak-se-zmena-dostane-na-televizi).
2. **Otevřete Přehled a podívejte se nahoru.** Pokud je tam červený rámeček **Poslední synchronizace selhala: …**, nebo oranžové varování, přečtěte si ho. Většinou řekne, co je špatně.
3. **Podívejte se do panelu Právě teď.** Je tam to, co jste čekali? Pokud ne, problém je ve scénáři, ne v televizi. Viz [Scénář neběží, ačkoli by měl](scenar-nebezi) a [Na televizi běží něco jiného, než čekám](na-televizi-bezi-neco-jineho).
4. **Klikněte na Synchronizovat nyní.** Viz [Jak vynutit okamžitou změnu na televizi](jak-vynutit-synchronizaci). Hláška uvede, co se stalo.
5. **Zkontrolujte u Poslední kontrola** v panelu **Právě teď**, kdy aplikace naposledy kontrolovala plán. Pokud je to dlouho (déle než 20 minut), nespouští se pravidelná úloha na serveru (cron). Obraťte se na správce.

## Pro správce

6. V **Nastavení → Systém → Diagnostika** klikněte na **Spustit kontrolu**. Hledejte položky se značkou **✕** nebo **!**. Viz [Jak spustit diagnostiku](jak-spustit-diagnostiku).
7. Zkontrolujte **Nastavení → Google Disk**: je disk připojen a je nastavená cílová složka? Viz [Hlášky Google Disku a synchronizace](hlasky-google-disk).
8. Zkontrolujte **Nastavení → Přehrávač**: ozývá se Synology? Pokud se neozvalo déle než 15 minut, **Přehled** na to upozorní. Viz [Jak nastavit přehrávač a skript pro Synology](jak-nastavit-prehravac).
9. Přehrávač může mít vypnutou nebo pomalou vlastní synchronizaci. Ta slouží jen jako záloha, hlavní pokyn přichází ze skriptu na Synology.

## Když nic nepomůže

Pošlete podpoře výsledek Diagnostiky a popis, co jste změnili a v kolik hodin. Viz [Kontakt a podpora](kontakt).

## Co se stane, když se synchronizace dlouho nedaří

Pokud je nastavené upozornění, přijde e-mail nebo SMS. Viz [Jak nastavit upozornění na chyby](jak-nastavit-upozorneni).
