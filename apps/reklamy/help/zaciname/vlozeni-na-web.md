---
titulek: Vložení reklamy na web
sekce: zaciname
admin: ne
souvisi: zpusoby-vlozeni, weby-a-zony
klicova: kód, vložit, ins
---
# Vložení reklamy na web

Kód pro vložení najdete na stránce zóny. Doporučený je **asynchronní JavaScript**:

- do stránky vložte značku `<ins data-twbr-zona="…">` na místo reklamy,
- skript `asyn.php` stačí na stránce jednou, i když je na ní zón víc.

Víc zón na jedné stránce se načte jedním požadavkem a stejný banner se na stránce nezopakuje. Ostatní způsoby popisuje [Způsoby vložení](zpusoby-vlozeni).

Na webech s lištou souhlasu s cookies uveďte měřicí cookies reklamy `twbr_v` a `twbr_f` – nebo je vypněte v **Nastavení → Měření a cookies** ([Cílení a frekvence](cileni-a-frekvence)).
