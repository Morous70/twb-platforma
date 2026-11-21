# TrafikaBR Update Server – malé API

Toto API slouží pro jednoduché interní automatizace Update Serveru. První podporovanou akcí je okamžitá kontrola Google Drive Inboxu.

## Autentizace

Použijte API token z administrace **Drive Inbox → Malé API a automatická kontrola**. Token je tajný údaj. Posílejte jej v hlavičce:

```http
Authorization: Bearer gdi_...
```

Kvůli kompatibilitě se shared hostingem je podporována také hlavička:

```http
X-Update-Token: gdi_...
```

Token nikdy nevkládejte do URL.

## Okamžitá kontrola a stažení z Google Drive

```http
POST /api/v1/drive-inbox-check.php
```

Příklad:

```bash
curl -X POST -H "X-Update-Token: gdi_..." https://updates.trafikabr.cz/api/v1/drive-inbox-check.php
```

Endpoint zkontroluje předávací složku, stáhne nové ZIPy, ověří SHA-256 a platné balíčky publikuje jako release. Současně přesune do Koše Google Drive již bezpečně zpracované soubory starší než 7 dnů.

Pro opakované zpracování položek, které dříve skončily chybou, použijte `retry=1`:

```bash
curl -X POST -H "X-Update-Token: gdi_..." "https://updates.trafikabr.cz/api/v1/drive-inbox-check.php?retry=1"
```

Úspěšná odpověď obsahuje například:

```json
{
  "ok": true,
  "action": "drive_inbox.check",
  "summary": {
    "checked": 2,
    "published": 1,
    "skipped": 1,
    "errors": 0,
    "cleanup_packages": 1,
    "cleanup_files_trashed": 2,
    "cleanup_errors": 0,
    "cleanup_requires_reconnect": false
  },
  "items": []
}
```

Pokud některá položka skončí chybou, server může vrátit HTTP `207` a podrobnosti jsou v `items`. Neplatný token vrací HTTP `401`.

## Stav Drive Inboxu

```http
GET /api/v1/drive-inbox-status.php
```

Příklad:

```bash
curl -H "X-Update-Token: gdi_..." https://updates.trafikabr.cz/api/v1/drive-inbox-status.php
```

Vrací stav připojení Google Drive, informaci o oprávnění pro úklid, nastavenou retenci 7 dnů, čas poslední kontroly, poslední úspěšnou publikaci, poslední úklid, případnou chybu úklidu a počet zpracovaných balíčků.

## Bezpečnost

- API token je tajný. V administraci jej lze zobrazit ikonou oka po opětovném zadání administrátorského hesla; po obnovení stránky se znovu skryje.
- Token nepředávejte do klientských aplikací, které používají koncoví uživatelé.
- Při podezření na únik token v administraci okamžitě vygenerujte znovu; starý přestane platit.
- Pro akce měnící stav používejte přednostně `POST`.
- Automatický úklid používá OAuth scope `drive`, protože zpracované soubory přesouvá do Koše. Po upgradu ze staršího `drive.readonly` připojení je nutné Google Drive jednou znovu připojit.
