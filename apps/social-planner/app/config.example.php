<?php
// Zkopírujte jako config.php a vyplňte.
return [
    // Meta aplikace (developers.facebook.com)
    'app_id'       => 'VASE_APP_ID',
    'app_secret'   => 'VAS_APP_SECRET',
    // ID konfigurace z Facebook Login for Business (Meta aplikace → Facebook Login for Business → Konfigurace).
    // Když je vyplněné, použije se místo seznamu oprávnění. Nechte prázdné u klasického „Facebook Login“.
    'login_config_id' => '',
    // Threads má vlastní klíče: Meta → vaše aplikace → případ použití „Threads API“ → Nastavení (ID aplikace Threads a její tajný klíč).
    // Do polí „Redirect Callback URL“, „Uninstall callback“ a „Delete callback“ zadejte <base_url>/threads_connect.php a <base_url>/threads_hook.php.
    'threads_app_id'     => '',
    'threads_app_secret' => '',
    // Záloha na Google Drive (Zálohy → Google Drive): Google Cloud Console → OAuth klient typu „Web application“,
    // povolené API „Google Drive API“, adresa pro přesměrování <base_url>/gdrive_connect.php
    'google_client_id'     => '',
    'google_client_secret' => '',
    'graph_version'=> 'v23.0',                       // zkontrolujte aktuální verzi v dokumentaci Mety
    // Veřejná adresa aplikace BEZ lomítka na konci (musí být HTTPS, Instagram stahuje média z této URL)
    'base_url'     => 'https://example.cz',

    // Heslo pro přihlášení do aplikace: php -r "echo password_hash('vaseheslo', PASSWORD_DEFAULT);"
    'admin_hash'   => '',

    // Tajný klíč pro spouštění cronu přes HTTP (cron.php?key=...). Pro CLI cron není potřeba.
    'cron_key'     => 'ZMENTE_NA_DLOUHY_NAHODNY_RETEZEC',

    'timezone'     => 'Europe/Prague',

    // true = nic se neodesílá na Metu, jen se loguje (pro testování)
    'dry_run'      => false,
];
