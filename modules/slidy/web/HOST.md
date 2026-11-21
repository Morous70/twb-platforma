# Editor slidů v prohlížeči – co dodává aplikace

`slide-editor.js` je verze ze Social Planneru 1.9.2 (nadmnožina editoru z Plánovače médií: navíc formáty plátna,
čas náhledu a správa písem). Soubor se v modulu neupravuje; rozdíly mezi aplikacemi řeší aplikace tím, co dodá níže.
Načítá se až po „přípravném“ skriptu aplikace (v Social Planneru `slide-shim.js`) a před spouštěčem (`slide-init.js`),
který zavolá `editSlide(m)`.

## Globální funkce a proměnné
`$`, `$$`, `esc`, `isTouch`, `timer`, `main`, `FORMATS`, `NEW_FORMAT`, `api`, `toast`, `openModal`, `closeModal`, `busy`,
`fmtTime`, `fmtDataValue`, `store`, `mediaById`, `thumb`, `helpBtn`, `currentTags`, `route`, `newTagState`, `tagOk`,
`tagText`, `tagBar`, `editTagsModal`, `whenTs`, `slideSaved`, `manageFonts`

Plánovač médií navíc zná pole `grp` (skupina média); aplikace, která ho chce, si ho do editoru doplní sama.

## Akce API (`api(akce, tělo)`)
- `slide_formats` – seznam `{key,w,h,name}`; `slide_get`, `slide_save`, `slide_preview`, `resolve`
- `fonts`, `fx_styles` – písma (modul `pisma`) a styly efektů (`Fx::STYLY`)
- `data_list`, `source_tree`, `datefmt`, `weather_settings` – patří modulu `data`; bez něj je editor jen pro text a obrázky

## Odpovědi serveru
Vykreslení a očištění šablon dělá `TWB\Slidy\Navrh` a `TWB\Slidy\Vykresleni`; editor kreslí na plátno stejným postupem.
