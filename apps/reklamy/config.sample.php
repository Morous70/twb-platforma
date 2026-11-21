<?php
// Konfigurace TWB Reklamy. Zkopírujte jako config.php (instalace ji umí vytvořit sama) a doplňte, co potřebujete.
// Tajné údaje patří jen sem, nikdy do repozitáře.
return [
    // Veřejná adresa aplikace bez lomítka na konci (přes ni se generují kódy pro vložení a Passkey)
    'base_url' => 'https://ads.kazbo.eu',

    // Složka s databází, galerií a zálohami (výchozí: data/ vedle aplikace; musí být chráněná .htaccess)
    // 'data_dir' => __DIR__ . '/data',

    'timezone' => 'Europe/Prague',

    // Odhlášení po nečinnosti v minutách (0 = neodhlašovat)
    'session_timeout_min' => 120,

    // Přihlášení přes Google (Google Cloud Console → Credentials → OAuth client ID, typ Web application)
    // Authorized redirect URI: <base_url>/google.php
    'google_client_id'     => '',
    'google_client_secret' => '',

    // Tajný klíč pro cron: <base_url>/cron.php?key=...  (spouštět každých 5–15 minut)
    'cron_key' => 'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC',

    // Odesílatel e-mailů aplikace (adresa na vaší doméně)
    'mail_from' => 'reklamy@kazbo.eu',

    // Aktualizace z TWB Update Serveru: token upd_… (administrace Update Serveru → aplikace reklamy)
    'update_token' => '',
];
