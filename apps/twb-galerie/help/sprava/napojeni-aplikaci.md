---
titulek: Napojení jiné aplikace
sekce: sprava
admin: ano
souvisi: api, autor-a-licence, verejne-odkazy
klicova: napojení, zdroj, pixabay, commons, social planner, knihovna médií, adaptér, náhled, atribuce, integrace
---
# Napojení jiné aplikace

Galerii lze zapojit do jiné aplikace jako zdroj obrázků, stejně jako Pixabay nebo Wikimedia Commons v Knihovně médií Social Planneru: aplikace v galerii hledá, ukáže náhledy, a vybraný obrázek si stáhne k sobě i s autorem a licencí.

## Co je potřeba
1. Na stránce **API** vytvořte klíč s oprávněním **Čtení**, pojmenovaný podle aplikace (např. „Social Planner“). Pro každou aplikaci zvlášť, ať jde jeden klíč zrušit bez vlivu na ostatní.
2. V nastavení té aplikace vyplňte **adresu galerie** (`https://galerie.kazbo.eu`) a **klíč**. Klíč patří jen na server aplikace, nikdy do JavaScriptu v prohlížeči ani do odkazu.

## Jak to aplikace dělá
| Krok | Volání |
|---|---|
| Hledání | `GET /media?druh=image&q=…&na_stranku=24`; `q` hledá v názvu, tazích, autorovi i poznámce bez diakritiky a řadí podle relevance (`q_rezim=kterykoli` = stačí jedno slovo; navíc `tagy`, `kategorie`, `orientace`, `min_sirka`…) |
| Náhled ve výsledcích | `nahledy.320` z odpovědi rovnou do `<img>` (bez klíče) |
| Vložený odkaz | `GET /media/odkaz?url=…` (detail média, veřejný odkaz nebo adresa náhledu) |
| Stažení | `GET /media/{id}/soubor?sirka=1920` s klíčem |
| Duplicita | `stranka_url` uložit u staženého souboru a příště porovnat |
| Autor a licence | `autor`, `licence_kod`, `licence_verze`, `licence_odkaz`, hotový text v `atribuce.text` |

Kódy licencí (`cc0`, `cc-by`, `cc-by-sa`, `vlastni`, `placena`, `pixabay`, `pexels`, `ai`, `jine`) jsou stejné jako v Social Planneru a Kvízech, převádět je není potřeba. Médium bez licence má `licence_kod` null.

## Příklad (PHP)
```php
$galerie = 'https://galerie.kazbo.eu'; $klic = 'tgk_…';   // z nastavení aplikace
$ch = curl_init($galerie . '/api.php/v1/media?' . http_build_query(['druh' => 'image', 'q' => 'hory', 'na_stranku' => 24]));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $klic]]);
$odpoved = json_decode((string)curl_exec($ch), true);
foreach ($odpoved['media'] ?? [] as $m) {
    echo '<img src="' . htmlspecialchars($m['nahledy']['320']) . '" alt=""> ' . htmlspecialchars($m['atribuce']['text']) . "\n";
}
```

## Dobré zvyky
- Hledejte až po krátké pauze v psaní (asi 0,5 s). Jeden klíč smí 300 požadavků za minutu.
- Archivovaná média se v hledání neukazují. Co v aplikacích nechcete nabízet, stačí přesunout do archivu.
- U licencí CC BY a CC BY-SA (`atribuce.povinne` = true) musí aplikace autora uvést u každého použití.
- Podepsané adresy náhledů platí jen 1–2 hodiny. Neukládejte je, ukládejte stažený soubor.
