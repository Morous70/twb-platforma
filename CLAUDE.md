# TWB platforma – pokyny pro práci (čte je každá nová relace)

Soukromý repozitář `Morous70/twb-platforma`: sdílené moduly (`modules/`) a aplikace (`apps/`). Vlastník píše česky; komentáře, texty uživatelům a commit zprávy česky, názvy modulů a jmenné prostory bez diakritiky.

**Než začneš:** přečti `docs/moduly.md` (zásady, stav modulů) a `docs/nova-aplikace.md` (jak se aplikace skládá z modulů).

**Pravidla**
- Co je společné, patří do modulu v `modules/`, ne do aplikace. Specifika aplikace se modulu předají (funkce, rozhraní, haky), nikdy podmínka na jméno aplikace.
- Starší aplikace (`planovac-medii`, `social-planner`) se NEPŘEPÍNAJÍ na moduly, dokud to vlastník výslovně nenařídí.
- Hosting: Wedos NoLimit, PHP 8.5 (kód musí běžet od 8.1), SQLite, bez Composeru a bez závislostí navíc.
- Každá změna modulu má test (`php tests/run.php <modul>`); chování převzaté z původní aplikace se ověřuje proti zmrazené kopii originálu (`tests/<modul>/original/`, needitovat).
- Řetězce typu `„$x“` v PHP psát `„{$x}“` (uvozovka se jinak bere jako část jména proměnné).
- Vydání: `php tools/sestavit.php <aplikace>`; verze modulů v `apps/<app>/modules.lock`.
- Commit zprávy česky, jedna logická změna, před pushem projít `php tests/run.php`.
