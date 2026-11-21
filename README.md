# TWB platforma

Společný repozitář aplikací TWB a jejich sdílených modulů.

```
modules/   sdílené moduly (jádro, přihlášení, galerie, slidy, HTML, data, …)
apps/      aplikace: planovac-medii, social-planner, reklamy (připravuje se)
tools/     sestavení balíčků aplikací z modulů
tests/     testy modulů
docs/      dokumentace (Update Server a další)
```

Stav: aplikace jsou importované beze změny. Moduly (`jadro`, `tagy`, `pisma`, `obrazky`, `napoveda`) jsou hotové a otestované, aplikace je zatím nepoužívají. Pravidla a stav modulů: [docs/moduly.md](docs/moduly.md). Testy: `php tests/run.php`.
