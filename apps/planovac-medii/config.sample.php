<?php
// Zkopírujte jako config.php a vyplňte.
return [
    // Veřejná adresa aplikace (bez lomítka na konci)
    'base_url' => 'https://trafikabr.cz/media-planner',

    // Databáze MySQL (údaje najdete v administraci Wedos → Databáze)
    'db_dsn'  => 'mysql:host=md00.wedos.net;dbname=d000000_planovac;charset=utf8mb4',
    'db_user' => 'w000000_planovac',
    'db_pass' => '',

    // Google OAuth klient (Google Cloud Console → Credentials → OAuth client ID, typ Web application)
    // Authorized redirect URI: <base_url>/oauth.php
    'google_client_id'     => '',
    'google_client_secret' => '',

    // Tajný klíč pro spouštění cronu přes URL: <base_url>/cron.php?key=...
    'cron_key' => 'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC',

    // Odesílatel upozornění e-mailem (adresa na vaší doméně)
    'mail_from' => 'planovac@trafikabr.cz',

    'timezone' => 'Europe/Prague',

    // Aktualizace z TWB Update Serveru: token upd_… (administrace Update Serveru → aplikace media-planner)
    'update_token' => '',

    // Testovací režim bez Google: soubory se kopírují do data/mock-drive/<ID složky>
    'drive_mock' => false,
];
