# Moduly TWB platformy

## Zásady
- Modul = složka `modules/<název>/` s `module.json`, `src/` (třídy `TWB\<Název>\…`), případně `assets/`, `fonty/` a testy v `tests/<název>/`.
- Název modulu a jmenný prostor jsou bez diakritiky (`jadro`, `tagy`, `pisma`), popisy a texty uživatelům jsou česky.
- Minimální verze PHP 8.1 (hosting běží na 8.5). Testy mění upozornění a zastaralé funkce na chyby.
- Moduly nepoužívají globální funkce ani konstanty. Od aplikace smějí použít jen to, co dostanou přes `TWB\Jadro\Host`
  (databáze, nastavení, protokol, datová složka, konfigurace, registr akcí a haků).
- Názvy tabulek a sloupců, které patří aplikaci (např. `media`, `library`), se předávají konstruktoru modulu.
- Co se v aplikacích liší, řeší aplikace přes **haky** (`Registr::hak`, `Registr::sluc`) nebo přes funkci předanou konstruktoru
  (např. `Pisma` se přes ni ptá, které slidy písmo používají).
- Modul s vlastními tabulkami má třídu `Migrace` implementující `ModulMigrace`; verze schématu se ukládá do `module_versions`.
- Změna modulu musí být zpětně kompatibilní v rámci hlavní verze. Každá aplikace má `modules.lock` s verzemi, které používá.

## Spuštění testů
```
php tests/run.php            # všechny moduly
php tests/run.php tagy       # jeden modul
```

## Stav modulů
| Modul | Verze | Stav |
|---|---|---|
| jadro | 0.1.0 | zavaděč, Db, Nastaveni, Protokol, Registr, Host, Migrace |
| tagy | 0.1.0 | hotovo, sloučeno z obou aplikací |
| pisma | 0.1.0 | hotovo; vestavěná písma ve složce `fonty/` |
| obrazky | 0.1.0 | hotovo; komprese, EXIF, průhlednost, animované GIFy (opravena detekce GIFu se dvěma snímky) |
| napoveda | 0.1.0 | hotovo (PHP); klient `assets/help.js` je zatím převzatý z Plánovače beze změny |
| prihlaseni, zalohy, aktualizace | – | další v pořadí; přihlášení a zálohy se v aplikacích výrazně liší |
| galerie, slidy, html, data | – | po jednoduchých modulech |

## Známé chyby nalezené při vyčleňování
- `Napoveda::zkontroluj()` a původní `help_check()` v Plánovači: ve zprávách typu `„$k“` bere PHP uvozovku jako součást jména proměnné. V modulu opraveno, v Plánovači zmizí po přepnutí na modul.
- Původní `img_is_animated_gif()` nepoznala GIF se dvěma snímky, takže by se zmenšil a přišel o animaci. V modulu opraveno.
- `imagedestroy()` je od PHP 8.5 zastaralé, moduly ho nepoužívají.
