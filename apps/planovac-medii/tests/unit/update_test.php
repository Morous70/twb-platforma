<?php
// Aktualizace z TWB Update Serveru (verze 1.6.0): podpis, kontrola, instalace, návrat.
// Testovací Update Server běží jako php -S s vlastním klíčem; instalace přepisuje dočasnou kopii aplikace, ve které testy běží.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/update.php';

$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$GLOBALS['CFG']['update_public_key'] = base64_encode(sodium_crypto_sign_publickey($kp));
$GLOBALS['CFG']['update_token'] = 'upd_testovaci_token_123';
set_setting('backup_password', 'Heslo-zaloh-1');

/** Podepsaná odpověď latest.php. */
function signed(array $payload, string $sk, ?array $fakePayload = null): string {
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
    return json_encode(['algorithm' => 'Ed25519', 'payload' => $fakePayload ?? $payload, 'payload_b64' => base64_encode($raw),
                        'signature' => base64_encode(sodium_crypto_sign_detached($raw, $sk))], JSON_UNESCAPED_SLASHES);
}
function release(string $version, string $zip): array {
    return ['app' => 'media-planner', 'version' => $version, 'release_id' => 'rel-' . str_replace('.', '-', $version), 'size' => filesize($zip),
            'sha256' => hash_file('sha256', $zip), 'released_at' => '2026-10-18T10:00:00+02:00', 'download_path' => '/api/v1/download.php?id=rel-' . str_replace('.', '-', $version)];
}

// ---------- testovací Update Server ----------
$srv = TMP_DIR . '/update-server';
@mkdir($srv, 0775, true);
file_put_contents("$srv/router.php", <<<'PHP'
<?php
$dir = __DIR__;
$tok = $_SERVER['HTTP_X_UPDATE_TOKEN'] ?? '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents("$dir/last-request.txt", $_SERVER['REQUEST_URI'] . "\n" . $auth);
if ($tok !== 'upd_testovaci_token_123' || $auth !== "Bearer $tok") { http_response_code(401); exit('{"error":"unauthorized"}'); }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/v1/latest.php') {
    if (is_file("$dir/status")) { http_response_code((int)file_get_contents("$dir/status")); exit; }
    header('Content-Type: application/json'); readfile("$dir/latest.json"); exit;
}
if ($path === '/api/v1/download.php') { header('Content-Type: application/zip'); readfile("$dir/package.zip"); exit; }
http_response_code(404);
PHP);
$port = 19000 + getmypid() % 900;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$srv/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $srv);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
register_shutdown_function(function () use ($proc) { $s = proc_get_status($proc); if ($s['running']) posix_kill($s['pid'], SIGTERM); });
$GLOBALS['CFG']['update_url'] = "http://127.0.0.1:$port";
$serve = function (string $latest, ?int $status = null) use ($srv) {
    file_put_contents("$srv/latest.json", $latest);
    $status ? file_put_contents("$srv/status", (string)$status) : @unlink("$srv/status");
};

/** Balíček z aktuální kopie aplikace s jinou verzí; $mod může soubory upravit (relativní cesta => obsah | null = vynechat). */
function package(string $version, array $mod = [], array $extra = []): string {
    $zip = TMP_DIR . "/pkg-$version-" . bin2hex(random_bytes(3)) . '.zip';
    $z = new ZipArchive(); $z->open($zip, ZipArchive::CREATE);
    $z->addEmptyDir('media-planner');
    foreach (update_app_files(APP_DIR) as $rel) {
        if ($rel === '.user.ini') continue; // cizí soubor hostingu, v balíčku nikdy není
        if (array_key_exists($rel, $mod)) { if ($mod[$rel] !== null) $z->addFromString("media-planner/$rel", $mod[$rel]); continue; }
        $z->addFile(APP_DIR . "/$rel", "media-planner/$rel");
    }
    $z->addFromString('media-planner/VERSION', "$version\n");
    foreach ($mod as $rel => $c) if ($c !== null && !is_file(APP_DIR . "/$rel")) $z->addFromString("media-planner/$rel", $c);
    foreach ($extra as $name => $c) $z->addFromString($name, $c);
    $z->close();
    return $zip;
}
$installed = fn() => trim((string)file_get_contents(APP_DIR . '/VERSION'));
$orig = $installed();

// ---------- podpis ----------
test('platný podpis: údaje se berou z podepsaných bajtů', function () use ($sk) {
    $p = ['app' => 'media-planner', 'version' => '9.0.0', 'release_id' => 'r1', 'size' => 10, 'sha256' => str_repeat('a', 64), 'released_at' => 'x', 'download_path' => '/api/v1/download.php?id=r1'];
    eq('9.0.0', update_verify(signed($p, $sk))['version']);
    $fake = ['version' => '99.0.0'] + $p;
    eq('9.0.0', update_verify(signed($p, $sk, $fake))['version'], 'nepodepsané „payload“ se ignoruje');
});

test('podvržený podpis, cizí klíč, jiná aplikace, divná cesta ke stažení', function () use ($sk) {
    $p = ['app' => 'media-planner', 'version' => '9.0.0', 'release_id' => 'r1', 'size' => 10, 'sha256' => str_repeat('a', 64), 'released_at' => 'x', 'download_path' => '/api/v1/download.php?id=r1'];
    $j = json_decode(signed($p, $sk), true);
    $j['payload_b64'] = base64_encode(json_encode(['version' => '9.9.9'] + $p));
    throws(fn() => update_verify(json_encode($j)), 'Podpis');
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    throws(fn() => update_verify(signed($p, $other)), 'Podpis');
    throws(fn() => update_verify(signed(['app' => 'jina-aplikace'] + $p, $sk)), 'tvar');
    throws(fn() => update_verify(signed(['download_path' => 'https://zly.example/x.zip'] + $p, $sk)), 'tvar');
    throws(fn() => update_verify(signed(['sha256' => 'abc'] + $p, $sk)), 'tvar');
    throws(fn() => update_verify('{"algorithm":"none","payload":{}}'), 'podpis');
    eq(null, update_verify('{"app":"media-planner","update":null}'), 'žádné vydání');
});

test('napevno uložený klíč odpovídá TWB Update Serveru', function () {
    eq(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode(UPDATE_PUBLIC_KEY_B64, true)));
    eq('JTvTWDW+eSP/GtT9zG3t+3UraawTk82vKteb2Dci8Cg=', UPDATE_PUBLIC_KEY_B64);
});

// ---------- kontrola ----------
test('kontrola: token v obou hlavičkách, nová verze se nabídne', function () use ($serve, $sk, $srv, $orig) {
    $zip = package('9.9.9');
    copy($zip, "$srv/package.zip");
    $serve(signed(release('9.9.9', $zip), $sk));
    $st = update_check();
    eq('9.9.9', $st['available']['version'] ?? null, show($st['result']));
    contains('Bearer upd_testovaci_token_123', (string)file_get_contents("$srv/last-request.txt"));
    contains('/api/v1/latest.php?app=media-planner', (string)file_get_contents("$srv/last-request.txt"));
    eq('upd_••••••••_123', $st['token']);
});

test('kontrola: stejná nebo starší verze, žádné vydání, 401, 500', function () use ($serve, $sk, $orig) {
    $zip = package($orig);
    $serve(signed(release($orig, $zip), $sk));
    eq(null, update_check()['available']);
    $serve('{"app":"media-planner","update":null}');
    eq('no_release', update_check()['result']['result']);
    $serve('', 500);
    eq('server_error', update_check()['result']['result']);
    $GLOBALS['CFG']['update_token'] = 'upd_spatny';
    eq('auth_error', update_check()['result']['result']);
    $GLOBALS['CFG']['update_token'] = 'upd_testovaci_token_123';
    unset($GLOBALS['CFG']['update_token']);
    contains('update_token', update_check()['unsupported']);
    $GLOBALS['CFG']['update_token'] = 'upd_testovaci_token_123';
});

test('kontrola z cronu nejvýš jednou za 24 h', function () use ($serve) {
    $serve('{"app":"media-planner","update":null}');
    set_setting('update_checked_at', (string)(time() - 3600));
    set_setting('update_result', null);
    update_check_due();
    eq(null, setting('update_result'), 'před 24 h se neptá');
    set_setting('update_checked_at', (string)(time() - 90000));
    update_check_due();
    ok(setting('update_result') !== null, 'po 24 h se zeptá');
});

// ---------- instalace ----------
test('špatný kontrolní součet: nic se neinstaluje', function () use ($serve, $sk, $srv, $installed, $orig) {
    $zip = package('9.9.1');
    $rel = release('9.9.1', $zip);
    copy(package('9.9.1', ['README.md' => 'podvrh']), "$srv/package.zip");
    $serve(signed($rel, $sk));
    update_check();
    $r = update_install('9.9.1');
    ok(!$r['ok'], 'má selhat');
    contains('součet', $r['message']);
    eq($orig, $installed());
    eq('hash_error', update_status()['result']['result']);
    eq(null, update_status()['previous'], 'bez kopie předchozí verze');
});

test('nebezpečná cesta v ZIP (Zip Slip) se odmítne', function () use ($serve, $sk, $srv, $installed, $orig) {
    $zip = package('9.9.2', [], ['media-planner/../zlo.php' => '<?php echo 1;']);
    copy($zip, "$srv/package.zip");
    $serve(signed(release('9.9.2', $zip), $sk));
    update_check();
    $r = update_install('9.9.2');
    ok(!$r['ok']);
    contains('nepovolenou cestu', $r['message']);
    ok(!is_file(dirname(APP_DIR) . '/zlo.php') && !is_file(UPDATE_DIR . '/zlo.php'), 'soubor mimo aplikaci nevznikl');
    eq($orig, $installed());
});

test('nefunkční nová verze se sama vrátí (návrat k předchozí)', function () use ($serve, $sk, $srv, $installed, $orig) {
    $broken = "<?php\nrequire __DIR__ . '/lib/bootstrap.php';\nhttp_response_code(500);\necho 'rozbito';\n";
    $zip = package('9.9.3', ['health.php' => $broken]);
    copy($zip, "$srv/package.zip");
    $serve(signed(release('9.9.3', $zip), $sk));
    update_check();
    $r = update_install('9.9.3');
    ok(!$r['ok'], 'má selhat');
    contains('Vrácena předchozí verze', $r['message']);
    eq($orig, $installed());
    ok(!str_contains((string)file_get_contents(APP_DIR . '/health.php'), 'rozbito'), 'health.php vrácen');
    eq('rollback', update_status()['result']['result']);
});

test('úspěšná instalace: záloha, kopie předchozí verze, health, config a data zůstanou', function () use ($serve, $sk, $srv, $installed, $orig) {
    sleep(1);   // jiný čas změny souborů než při předchozím návratu (OPcache testovacího serveru)
    $cfgBefore = file_get_contents(getenv('MP_CONFIG'));
    file_put_contents(APP_DIR . '/.user.ini', 'memory_limit=512M');            // cizí soubor hostingu
    file_put_contents(APP_DIR . '/config.php', '<?php // místní konfigurace');
    $mid = make_media('zustane.jpg');
    $zip = package('9.9.9', ['README.md' => "nová verze\n", 'novy-soubor.txt' => 'nový'], ['media-planner/config.php' => 'PODVRH', 'media-planner/data/media/x.jpg' => 'PODVRH']);
    copy($zip, "$srv/package.zip");
    $serve(signed(release('9.9.9', $zip), $sk));
    update_check();
    $r = update_install('9.9.9');
    ok($r['ok'], $r['message']);
    eq('9.9.9', $installed());
    eq("nová verze\n", file_get_contents(APP_DIR . '/README.md'));
    eq('<?php // místní konfigurace', file_get_contents(APP_DIR . '/config.php'), 'config.php nepřepsán');
    ok(!is_file(MEDIA_DIR . '/x.jpg'), 'data/ z balíčku se ignoruje');
    eq('memory_limit=512M', @file_get_contents(APP_DIR . '/.user.ini'), 'cizí soubor zůstal');
    eq($cfgBefore, file_get_contents(getenv('MP_CONFIG')));
    eq('zustane.jpg', q('SELECT original_name FROM media WHERE id = ?', [$mid])->fetchColumn());
    $st = update_status();
    eq($orig, $st['previous']);
    eq(null, $st['available']);
    ok((bool)array_filter(backup_list(), fn($b) => str_contains($b['name'], '-pred-aktualizaci')), 'záloha před aktualizací');
    ok(!is_dir(UPDATE_DIR . '/staging'), 'přípravná složka uklizena');
});

test('další verze smaže soubor, který předchozí aktualizace nainstalovala a nová už nemá', function () use ($serve, $sk, $srv, $installed) {
    ok(is_file(APP_DIR . '/novy-soubor.txt'));
    $zip = package('9.9.10', ['novy-soubor.txt' => null]);
    copy($zip, "$srv/package.zip");
    $serve(signed(release('9.9.10', $zip), $sk));
    // APP_VERSION v tomto procesu je stará – nabídka se porovnává s ní, to nevadí
    update_check();
    $r = update_install('9.9.10');
    ok($r['ok'], $r['message']);
    ok(!is_file(APP_DIR . '/novy-soubor.txt'), 'odstraněný soubor smazán');
    ok(is_file(APP_DIR . '/.user.ini'), 'cizí soubor zůstal');
    eq('9.9.9', update_status()['previous']);
});

test('ruční návrat k předchozí verzi', function () use ($installed) {
    $r = update_restore_previous();
    eq('9.9.9', $r['version']);
    eq('9.9.9', $installed());
    eq(null, update_status()['previous'], 'uložená verze spotřebována');
    throws(fn() => update_restore_previous(), 'není uložená');
});

test('úklid: původní verze aplikace pro další testy', function () use ($orig, $installed) {
    // vrátit kopii aplikace do původního stavu ze zdrojů testů (runner ji vytvořil ze zdrojového stromu)
    $src = getenv('MP_TEST_SRC');
    ok($src && is_dir($src), 'MP_TEST_SRC');
    update_apply_from_source($src);
    eq($orig, $installed());
    @unlink(APP_DIR . '/.user.ini'); @unlink(APP_DIR . '/config.php');
});

function update_apply_from_source(string $src): void {
    foreach (update_app_files($src) as $rel) { if (!str_starts_with($rel, '.git/')) update_put(APP_DIR . "/$rel", "$src/$rel"); }
    foreach (update_app_files(APP_DIR) as $rel) if (!is_file("$src/$rel")) @unlink(APP_DIR . "/$rel");
}
