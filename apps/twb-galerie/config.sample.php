<?php
// Konfigurace TWB Media galerie. Zkopírujte jako config.php (instalace ji umí vytvořit sama) a doplňte, co potřebujete.
// Tajné údaje patří jen sem, nikdy do repozitáře.
return [
    // Veřejná adresa aplikace bez lomítka na konci (z ní se skládají veřejné odkazy na soubory, odkazy v e-mailech a Passkey)
    'base_url' => 'https://galerie.kazbo.eu',

    // Složka s databází, galerií a zálohami (výchozí: data/ vedle aplikace; musí být chráněná .htaccess)
    // 'data_dir' => __DIR__ . '/data',

    'timezone' => 'Europe/Prague',

    // Výchozí odhlášení po nečinnosti v minutách (0 = neodhlašovat); v Nastavení → Zabezpečení jde změnit
    'session_timeout_min' => 120,

    // Přihlášení přes Google (Google Cloud Console → Credentials → OAuth client ID, typ Web application)
    // Authorized redirect URI: <base_url>/google.php
    'google_client_id'     => '',
    'google_client_secret' => '',

    // Tajný klíč pro cron: <base_url>/cron.php?key=...  (spouštět každých 5–15 minut)
    'cron_key' => 'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC',

    // API: nejvýš požadavků na jeden klíč za minutu (0 = bez limitu)
    'api_limit_za_minutu' => 300,

    // Odesílatel e-mailů aplikace (adresa na vaší doméně)
    'mail_from' => 'galerie@kazbo.eu',

    // Jen pro testy: e-maily se místo odeslání uloží do této složky
    // 'mail_to_file' => __DIR__ . '/data/mail',

    // Aktualizace z TWB Update Serveru: token upd_… (administrace Update Serveru → aplikace twb-galerie)
    'update_token' => '',
];
