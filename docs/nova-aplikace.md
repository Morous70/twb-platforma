# Nová aplikace z modulů

## 1. Co přinést do nového projektu
Stačí přístup k repozitáři `Morous70/twb-platforma` (přečte si `CLAUDE.md`) a zadání v tomto tvaru:

```
Aplikace: <název> (slug <slug>), složka apps/<slug>
Účel: <co dělá, pro koho>
Moduly: <jadro, prihlaseni, zalohy, aktualizace, galerie, …>
Přihlášení: místní (heslo/Google/Passkey) | přes přihlašovací server
Doména a cesta: <adresa nasazení>, Update Server slug: <slug>
Vlastní funkce: <co modul nepokrývá>
Omezení: <např. jen čeština, jeden uživatel>
```

Tajné údaje (klíče, hesla, tokeny) se do chatu ani repozitáře nedávají: patří do `config.php` na serveru. Z `config.sample.php` se vezme jen seznam jmen.

## 2. Kostra aplikace
```
apps/<slug>/
  VERSION            např. 0.1.0
  modules.lock       {"app":"<slug>","moduly":{"jadro":"0.1.0", …}}
  build.json         (volitelné) koren, povinne, jen_full
  index.php, health.php, config.sample.php, install.php
  lib/               kód jen pro tuto aplikaci
  assets/            vlastní JS/CSS
```
`health.php` musí po klíči z `update_health_key` provést migrace a vrátit `{"ok":true,"version":…}` (vyžaduje modul `aktualizace`).

## 3. Zapojení modulů (pořadí)
1. `require modules/jadro/boot.php` (v balíčku `__DIR__/modules`, při vývoji `../../modules`).
2. `Db::pripoj(...)`, `Nastaveni`, `Protokol`, `Host`.
3. Migrace modulů: `(new Migrace($db))->spust('<modul>', \TWB\<Modul>\Migrace::class)`.
4. Moduly dostanou názvy tabulek aplikace a funkce pro vazby (viz konstruktory a `docs/moduly.md`).
5. Rozhraní `Zdroje`, `Promenne`, `Prvek` propojují galerii, data a slidy.
6. Verze modulů zapsat do `modules.lock` (`php tools/sestavit.php <slug> --zamek`).

## 4. Co se kde mění
- Chyba nebo vylepšení společné věci: oprava v `modules/<modul>`, test, zvýšit verzi v `module.json`, aplikace převezme novou verzi až po `--zamek` a vydání.
- Věc jen pro tuto aplikaci: do `apps/<slug>/lib`.
- Nový společný kus: nový modul (`module.json`, `src/`, `tests/<modul>/`, řádek v tabulce `docs/moduly.md`).

## 5. Vydání
`php tools/sestavit.php <slug>` → `dist/twb-<slug>-<verze>-full.zip` (první instalace přes FTP) a `-update.zip` (Update Server). SHA-256 a velikost se vypíšou.
