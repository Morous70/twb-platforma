<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/graph.php';
require_once __DIR__ . '/app/lib/linkmeta.php';
require_once __DIR__ . '/app/lib/vars.php';
require_once __DIR__ . '/app/lib/data.php';
require_once __DIR__ . '/app/lib/auth.php';
require_once __DIR__ . '/app/lib/webauthn.php';
require_once __DIR__ . '/app/lib/besttime.php';
require_once __DIR__ . '/app/lib/landing.php';
require_once __DIR__ . '/app/lib/oauth.php';
require_login(true);

$pdo = db();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();
// editor slidů (převzatý z Plánovače médií) posílá tělo jako JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $_POST = json_decode((string)file_get_contents('php://input'), true) ?: [];
}

switch ($action) {

case 'accounts':
    json_out(['accounts' => $pdo->query("SELECT a.id, a.platform, a.remote_id, a.name, a.page_id, a.enabled, a.health, a.health_msg, a.health_at, a.token_expires, a.avatar_at, p.name AS page_name
                                  FROM accounts a LEFT JOIN accounts p ON p.platform = 'facebook' AND p.remote_id = a.page_id
                                  ORDER BY a.name COLLATE NOCASE, a.platform DESC")->fetchAll()]);

case 'posts':
    $posts = $pdo->query("SELECT * FROM posts ORDER BY scheduled_at DESC LIMIT 200")->fetchAll();
    $tq = $pdo->prepare("SELECT t.id, t.status, t.error, t.remote_post_id, a.platform, a.name
                         FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.post_id = ?");
    foreach ($posts as &$p) {
        $tq->execute([$p['id']]);
        $p['targets'] = $tq->fetchAll();
        $p['media_url'] = $p['media_file'] ? 'uploads/' . rawurlencode($p['media_file']) : null;
        $p['media'] = media_list($pdo, $p);
    }
    json_out(['posts' => $posts]);

case 'linkmeta':
    $u = trim((string)($_POST['url'] ?? ''));
    if (!preg_match('#^https?://#i', $u)) json_out(['error' => 'Odkaz musí začínat http:// nebo https://.'], 422);
    try { json_out(['meta' => link_meta($u)]); }
    catch (RuntimeException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'linkimage':   // obrázek z odkazu (stáhne server, protože prohlížeč na cizí web nesmí)
    $u = trim((string)($_POST['url'] ?? ''));
    try {
        $r = fetch_url($u, 8_000_000);
        if (!preg_match('#^image/#', $r['type'])) throw new RuntimeException('Adresa neukazuje na obrázek.');
        header('Content-Type: ' . explode(';', $r['type'])[0]);
        echo $r['body']; exit;
    } catch (RuntimeException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'check_accounts':
    set_time_limit(120);
    $res = check_accounts($pdo);
    try { refresh_avatars($pdo, true); } catch (Throwable $e) { /* nevadí */ }
    json_out(['ok' => true] + $res);

case 'stats':
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $q = $pdo->prepare("SELECT t.id, t.post_id, t.published_at, t.stat_likes, t.stat_comments, t.stat_shares, t.stat_reach, t.stat_saves, t.stat_at,
                               a.platform, a.name AS account, p.message, p.media_file
                        FROM targets t JOIN accounts a ON a.id = t.account_id JOIN posts p ON p.id = t.post_id
                        WHERE t.status = 'done' AND t.published_at >= ? ORDER BY t.published_at DESC LIMIT 300");
    $q->execute([time() - $days * 86400]);
    $rows = $q->fetchAll();
    foreach ($rows as &$r) $r['media_url'] = $r['media_file'] ? 'uploads/' . rawurlencode($r['media_file']) : null;
    json_out(['rows' => $rows, 'last' => (int)meta_get('last_stats', '0')]);

case 'best_time':
    $ids = array_filter(explode(',', (string)($_GET['accounts'] ?? '')), 'ctype_digit');
    json_out(best_times($pdo, array_map('intval', $ids), max(14, min(365, (int)($_GET['days'] ?? 120)))));

case 'stats_refresh':
    set_time_limit(240);
    json_out(['ok' => true] + refresh_stats($pdo, max(1, min(365, (int)($_POST['days'] ?? 30)))));

case 'library':
    library_sync($pdo);
    require_once __DIR__ . '/app/lib/stockimg.php';
    $items = $pdo->query("SELECT id, file, name, type, size, w, h, created_at, tags, archived_at, design IS NOT NULL AS slide, credit, source_url FROM library ORDER BY created_at DESC, id DESC")->fetchAll();
    $use = library_usage_map($pdo);
    $disk = 0; $arch = ['count' => 0, 'size' => 0];
    foreach ($items as &$it) {
        $it['url'] = 'uploads/' . rawurlencode($it['file']);
        $it['tags'] = item_tags($it);
        $it['archived'] = !empty($it['archived_at']);
        $it['slide'] = !empty($it['slide']);
        $c = $it['credit'];
        $it['credit'] = $c ? img_credit_decode($c) : null;
        $it['credit_text'] = $c ? img_credit_text($c) : '';
        $it['credit_needed'] = $c && in_array($it['credit']['license'], ['cc-by', 'cc-by-sa', 'jine'], true);   // licence vyžaduje uvést autora
        $it['url'] .= $it['slide'] ? '?v=' . (int)@filemtime(PUBLIC_DIR . '/uploads/' . $it['file']) : '';
        $it['used'] = $use[$it['file']]['all'] ?? 0;
        $it['used_upcoming'] = $use[$it['file']]['upcoming'] ?? 0;
        $disk += (int)$it['size'];
        if ($it['archived']) { $arch['count']++; $arch['size'] += (int)$it['size']; }
    }
    unset($it);
    json_out(['items' => $items, 'tags' => array_values(tags_all($pdo)), 'total_size' => $disk, 'archive' => $arch]);

case 'library_upload':
    set_time_limit(300);
    $f = $_FILES['media'] ?? null;
    if (!$f || empty($f['name'])) json_out(['error' => 'Nevybrali jste žádný soubor.'], 422);
    try { $upTags = tags_clean($pdo, (array)($_POST['tags'] ?? [])); } catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }
    $map = ['image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'video/mp4' => ['video', 'mp4'], 'video/quicktime' => ['video', 'mov']];
    $added = 0; $errs = [];
    foreach ((array)$f['name'] as $i => $n) {
        $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { $errs[] = "$n: nahrání selhalo (kód $err, zkontrolujte upload_max_filesize a post_max_size)"; continue; }
        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($map[$mime])) { $errs[] = "$n: podporováno je JPEG, PNG, MP4, MOV"; continue; }
        $fn = bin2hex(random_bytes(12)) . '.' . $map[$mime][1];
        if (!move_uploaded_file($tmp, PUBLIC_DIR . '/uploads/' . $fn)) { $errs[] = "$n: soubor se nepodařilo uložit"; continue; }
        library_register($pdo, $fn, $map[$mime][0], (string)$n, $upTags);
        $added++;
    }
    json_out(['ok' => true, 'added' => $added, 'errors' => $errs]);

case 'library_rename':
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    if ($name === '') json_out(['error' => 'Zadejte název.'], 422);
    $pdo->prepare("UPDATE library SET name = ? WHERE id = ?")->execute([$name, (int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'library_delete':
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
    $del = 0; $kept = [];
    foreach ($ids as $id) {
        $q = $pdo->prepare("SELECT * FROM library WHERE id = ?"); $q->execute([$id]);
        $it = $q->fetch();
        if (!$it) continue;
        if (media_in_use($pdo, $it['file']) ) { $kept[] = $it['name']; continue; }   // používá ho nějaký příspěvek
        $pdo->prepare("DELETE FROM library WHERE id = ?")->execute([$id]);
        @unlink(PUBLIC_DIR . '/uploads/' . basename($it['file']));
        $del++;
    }
    json_out(['ok' => true, 'deleted' => $del, 'kept' => $kept]);

case 'library_tags':   // hromadně: ids[], add[], remove[]
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
    if (!$ids) json_out(['error' => 'Nevybrali jste žádný soubor.'], 422);
    try { json_out(['ok' => true, 'changed' => tags_bulk($pdo, $ids, (array)($_POST['add'] ?? []), (array)($_POST['remove'] ?? []))]); }
    catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'library_set_tags':   // tagy jednoho souboru: id, tags[]
    try {
        $tags = tags_clean($pdo, (array)($_POST['tags'] ?? []));
        item_set_tags($pdo, (int)($_POST['id'] ?? 0), $tags);
        json_out(['ok' => true, 'tags' => $tags]);
    } catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'tag_rename':
    try { json_out(['ok' => true, 'changed' => tag_rename($pdo, (string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''))]); }
    catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'tag_delete':
    json_out(['ok' => true, 'changed' => tag_delete($pdo, (string)($_POST['name'] ?? ''))]);

case 'library_archive':   // ids[], archive = 1 | 0; soubor zůstává v příspěvcích, jen se nenabízí ve výběru
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
    $arch = !empty($_POST['archive']);
    $st = $pdo->prepare($arch ? "UPDATE library SET archived_at = ? WHERE id = ? AND archived_at IS NULL"
                              : "UPDATE library SET archived_at = NULL WHERE ? > 0 AND id = ? AND archived_at IS NOT NULL");
    $n = 0;
    foreach ($ids as $id) { $st->execute([time(), $id]); $n += $st->rowCount(); }
    json_out(['ok' => true, 'changed' => $n]);

case 'security_get':
    $pks = $pdo->query("SELECT id, name, created_at, last_used FROM passkeys ORDER BY id")->fetchAll();
    json_out(['idle' => auth_idle_minutes(), 'has_email' => notify_settings()['email'] !== '', 'passkeys' => $pks,
              'rp_id' => wa_rp_id(), 'https' => str_starts_with((string)cfg('base_url'), 'https://'), 'pw_in_db' => (string)meta_get('password_hash', '') !== '']);

case 'password_change':
    if ($left = auth_lock_remaining()) json_out(['error' => 'Příliš mnoho pokusů, zkuste to za ' . (int)ceil($left / 60) . ' min.'], 429);
    $cur = (string)($_POST['current'] ?? ''); $n1 = (string)($_POST['new'] ?? ''); $n2 = (string)($_POST['new2'] ?? '');
    if (!auth_password_hash() || !password_verify($cur, auth_password_hash())) { auth_fail('změna hesla – špatné současné heslo'); json_out(['error' => 'Současné heslo není správné.'], 422); }
    if (mb_strlen($n1) < 8) json_out(['error' => 'Nové heslo musí mít alespoň 8 znaků.'], 422);
    if ($n1 !== $n2) json_out(['error' => 'Nová hesla se neshodují.'], 422);
    meta_set('password_hash', password_hash($n1, PASSWORD_DEFAULT));
    log_line('HESLO změněno v nastavení');
    json_out(['ok' => true]);

case 'idle_save':
    $m = (int)($_POST['minutes'] ?? 0);
    if ($m !== 0 && ($m < 5 || $m > 1440)) json_out(['error' => 'Zadejte 0 (nikdy) nebo 5 až 1440 minut.'], 422);
    meta_set('idle_minutes', (string)$m);
    json_out(['ok' => true]);

case 'passkey_reg_options':
    $ch = random_bytes(32);
    auth_token_create($pdo, 'wa-reg', 300, bin2hex($ch));
    $ex = [];
    foreach ($pdo->query("SELECT cred_id FROM passkeys")->fetchAll() as $r) $ex[] = ['type' => 'public-key', 'id' => $r['cred_id']];
    json_out(['publicKey' => [
        'challenge' => b64u_enc($ch), 'rp' => ['name' => 'Plánovač příspěvků', 'id' => wa_rp_id()],
        'user' => ['id' => b64u_enc('planovac'), 'name' => 'planovac', 'displayName' => 'Správce'],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'timeout' => 60000, 'attestation' => 'none', 'excludeCredentials' => $ex,
        'authenticatorSelection' => ['residentKey' => 'required', 'userVerification' => 'required'],
    ]]);

case 'passkey_reg_verify':
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100) ?: 'Zařízení';
    $cred = json_decode((string)($_POST['cred'] ?? ''), true);
    if (!is_array($cred)) json_out(['error' => 'Neplatný požadavek.'], 422);
    try { [$cid, $pem, $alg, $cnt] = wa_register($pdo, $cred); }
    catch (Throwable $e) { json_out(['error' => $e->getMessage()], 422); }
    $dup = $pdo->prepare("SELECT 1 FROM passkeys WHERE cred_hash = ?"); $dup->execute([hash('sha256', $cid)]);
    if ($dup->fetch()) json_out(['error' => 'Toto zařízení už je zaregistrované.'], 422);
    $pdo->prepare("INSERT INTO passkeys (name, cred_hash, cred_id, public_key, alg, sign_count, created_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([$name, hash('sha256', $cid), b64u_enc($cid), $pem, $alg, $cnt, time()]);
    log_line("PASSKEY přidáno: $name");
    json_out(['ok' => true]);

case 'passkey_rename':
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    if ($name === '') json_out(['error' => 'Zadejte název.'], 422);
    $pdo->prepare("UPDATE passkeys SET name = ? WHERE id = ?")->execute([$name, (int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'passkey_delete':
    $pdo->prepare("DELETE FROM passkeys WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    log_line('PASSKEY odebráno');
    json_out(['ok' => true]);

case 'backup_info':
    $files = glob(backup_dir() . '/planner-*.sqlite') ?: [];
    rsort($files);
    $up = backup_uploads_list(); $sz = 0;
    foreach ($up as $f) $sz += (int)@filesize(PUBLIC_DIR . '/uploads/' . $f);
    json_out([
        'auto' => meta_get('backup_auto', '1') === '1',
        'last' => (int)meta_get('backup_last', '0'),
        'db_size' => (int)@filesize(ROOT . '/data/planner.sqlite'),
        'uploads' => count($up), 'uploads_size' => $sz,
        'zip' => class_exists('ZipArchive'),
        'files' => array_map(fn($f) => ['name' => basename($f), 'size' => (int)filesize($f)], $files),
    ]);

case 'backup_set':
    meta_set('backup_auto', !empty($_POST['auto']) ? '1' : '0');
    json_out(['ok' => true]);

case 'backup_now':
    $f = backup_auto($pdo); meta_set('backup_last', (string)time());
    json_out(['ok' => true, 'name' => basename($f)]);

case 'gd_info':
    json_out(['configured' => gd_configured(), 'connected' => gd_connected(), 'email' => meta_get('gd_email', ''), 'enabled' => meta_get('gd_enabled', '0') === '1',
              'uploads' => meta_get('gd_uploads', '0') === '1', 'keep' => (int)meta_get('gd_keep', '8'), 'last' => (int)meta_get('gd_last', '0'),
              'last_name' => meta_get('gd_last_name', ''), 'error' => meta_get('gd_error', ''), 'redirect' => gd_redirect()]);

case 'gd_set':
    meta_set('gd_enabled', !empty($_POST['enabled']) ? '1' : '0');
    meta_set('gd_uploads', !empty($_POST['uploads']) ? '1' : '0');
    meta_set('gd_keep', (string)max(2, min(60, (int)($_POST['keep'] ?? 8))));
    json_out(['ok' => true]);

case 'gd_now':
    if (!gd_connected()) json_out(['error' => 'Google Drive není propojený.'], 422);
    try { $n = gd_backup($pdo); } catch (Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
    json_out(['ok' => true, 'name' => $n]);

case 'gd_disconnect':
    gd_disconnect(); meta_set('gd_enabled', '0');
    json_out(['ok' => true]);

case 'mcp_grants':
    json_out(['grants' => oauth_grants_list($pdo)]);

case 'mcp_log':
    json_out(['log' => oauth_log_list($pdo, 80)]);

case 'mcp_log_clear':
    $pdo->exec("DELETE FROM mcp_log");
    json_out(['ok' => true]);

case 'mcp_selftest':
    json_out(['tests' => oauth_selftest()]);

case 'mcp_revoke':
    oauth_revoke_grant($pdo, (int)($_POST['id'] ?? 0));
    json_out(['ok' => true]);

case 'apikeys':
    json_out(['keys' => $pdo->query("SELECT id, name, prefix, created_at, last_used, uses FROM api_keys ORDER BY id")->fetchAll(),
              'endpoint' => rtrim((string)cfg('base_url'), '/') . '/inbox.php',
              'drafts' => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE source IS NOT NULL AND status = 'draft'")->fetchColumn()]);

case 'apikey_create':
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
    if ($name === '') json_out(['error' => 'Zadejte název aplikace (např. „Eshop A“).'], 422);
    $dup = $pdo->prepare("SELECT 1 FROM api_keys WHERE name = ?"); $dup->execute([$name]);
    if ($dup->fetchColumn()) json_out(['error' => 'Klíč s tímto názvem už existuje.'], 422);
    [$id, $raw] = apikey_create($pdo, $name);
    log_line("API klíč vytvořen: $name");
    json_out(['ok' => true, 'id' => $id, 'key' => $raw]);

case 'apikey_delete':
    $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    log_line('API klíč odebrán');
    json_out(['ok' => true]);

case 'landing_get':
    json_out(['settings' => landing_settings($pdo)]);

case 'landing_save':
    try { json_out(['ok' => true, 'settings' => landing_save($pdo, $_POST)]); }
    catch (RuntimeException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'notify_get':
    json_out(['settings' => notify_settings(), 'mail_from' => (string)cfg('mail_from', '')]);

case 'notify_save':
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Neplatná e-mailová adresa.'], 422);
    meta_set('notify_email', $email);
    meta_set('notify_on_fail', !empty($_POST['on_fail']) ? '1' : '0');
    meta_set('notify_on_token', !empty($_POST['on_token']) ? '1' : '0');
    json_out(['ok' => true]);

case 'notify_test':
    if (notify_settings()['email'] === '') json_out(['error' => 'Nejdřív uložte e-mailovou adresu.'], 422);
    $ok = send_mail('Plánovač: zkušební zpráva', "Oznámení z plánovače příspěvků fungují.\n");
    if (!$ok) json_out(['error' => 'E-mail se nepodařilo odeslat (funkce mail() na hostingu selhala).'], 500);
    json_out(['ok' => true]);

case 'groups':
    json_out(['groups' => $pdo->query("SELECT id, name, account_ids FROM account_groups ORDER BY name COLLATE NOCASE")->fetchAll()]);

case 'group_save':
    $name = trim((string)($_POST['name'] ?? ''));
    $gid = (int)($_POST['id'] ?? 0);
    if ($name === '' || mb_strlen($name) > 60) json_out(['error' => 'Zadejte název skupiny (nejvýše 60 znaků).'], 422);
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['accounts'] ?? []))));
    if (!$ids) json_out(['error' => 'Vyberte alespoň jeden účet.'], 422);
    $dup = $pdo->prepare("SELECT id FROM account_groups WHERE name = ? AND id != ?");
    $dup->execute([$name, $gid]);
    if ($dup->fetch()) json_out(['error' => 'Skupina s tímto názvem už existuje.'], 422);
    if ($gid) $pdo->prepare("UPDATE account_groups SET name=?, account_ids=? WHERE id=?")->execute([$name, implode(',', $ids), $gid]);
    else $pdo->prepare("INSERT INTO account_groups (name, account_ids) VALUES (?,?)")->execute([$name, implode(',', $ids)]);
    json_out(['ok' => true]);

case 'group_delete':
    $pdo->prepare("DELETE FROM account_groups WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'templates':
    json_out(['templates' => $pdo->query("SELECT id, name, message, link, tags, account_ids FROM templates ORDER BY name COLLATE NOCASE")->fetchAll()]);

case 'template_save':
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) json_out(['error' => 'Zadejte název šablony (nejvýše 80 znaků).'], 422);
    $acc = implode(',', array_map('intval', (array)($_POST['accounts'] ?? [])));
    $pdo->prepare("INSERT INTO templates (name, message, link, tags, account_ids) VALUES (?,?,?,?,?)
                   ON CONFLICT(name) DO UPDATE SET message=excluded.message, link=excluded.link, tags=excluded.tags, account_ids=excluded.account_ids")
        ->execute([$name, (string)($_POST['message'] ?? ''), trim((string)($_POST['link'] ?? '')) ?: null, trim((string)($_POST['tags'] ?? '')) ?: null, $acc]);
    json_out(['ok' => true]);

case 'import_image':
    set_time_limit(120);
    try { $fn = inbox_fetch_image($pdo, trim((string)($_POST['url'] ?? ''))); }
    catch (Throwable $e) { json_out(['error' => $e->getMessage()], 422); }
    json_out(['ok' => true, 'file' => $fn]);

case 'hashtag_sets':
    json_out(['sets' => $pdo->query("SELECT id, name, tags FROM hashtag_sets ORDER BY name COLLATE NOCASE")->fetchAll()]);

case 'hashtag_set_save':
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) json_out(['error' => 'Zadejte název sady (nejvýše 60 znaků).'], 422);
    $tl = [];
    foreach (preg_split('/[\s,;]+/u', (string)($_POST['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim($t, '#'));
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tl), true)) $tl[] = $t;
    }
    if (!$tl) json_out(['error' => 'Sada musí obsahovat aspoň jeden štítek.'], 422);
    if (count($tl) > 30) json_out(['error' => 'Sada může mít nejvýše 30 štítků.'], 422);
    $pdo->prepare("INSERT INTO hashtag_sets (name, tags) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET tags = excluded.tags")->execute([$name, implode(' ', $tl)]);
    json_out(['ok' => true]);

case 'hashtag_set_delete':
    $pdo->prepare("DELETE FROM hashtag_sets WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'template_delete':
    $pdo->prepare("DELETE FROM templates WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'variables':
    $list = $pdo->query("SELECT id, name, type, value FROM variables ORDER BY name")->fetchAll();
    json_out(['variables' => $list, 'builtin' => BUILTIN_VARS]);

case 'var_save':
    $name = strtolower(trim((string)($_POST['name'] ?? '')));
    $type = ($_POST['type'] ?? '') === 'list' ? 'list' : 'text';
    $value = trim((string)($_POST['value'] ?? ''));
    $vid = (int)($_POST['id'] ?? 0);
    if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name)) json_out(['error' => 'Název: písmena a–z bez diakritiky, číslice a podtržítko, začíná písmenem.'], 422);
    if (isset(BUILTIN_VARS[$name]) || in_array(norm_key($name), CALENDAR_VARS, true)) json_out(['error' => 'Název „' . $name . '“ je vyhrazený pro vestavěnou proměnnou.'], 422);
    if ($value === '') json_out(['error' => 'Zadejte hodnotu.'], 422);
    $dup = $pdo->prepare("SELECT id FROM variables WHERE name = ? AND id != ?");
    $dup->execute([$name, $vid]);
    if ($dup->fetch()) json_out(['error' => 'Proměnná s tímto názvem už existuje.'], 422);
    if ($vid) $pdo->prepare("UPDATE variables SET name=?, type=?, value=? WHERE id=?")->execute([$name, $type, $value, $vid]);
    else $pdo->prepare("INSERT INTO variables (name, type, value) VALUES (?,?,?)")->execute([$name, $type, $value]);
    json_out(['ok' => true]);

case 'var_delete':
    $pdo->prepare("DELETE FROM variables WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

// ---------- Obrázky z internetu (1.8.0, z aplikace Kvízy) ----------
case 'stock_providers':
    require_once __DIR__ . '/app/lib/stockimg.php';
    json_out(['providers' => array_map(fn($k, $p) => ['key' => $k, 'name' => $p['name'], 'url' => $p['url'], 'needs_key' => $p['needs_key'], 'ready' => !$p['needs_key'] || stock_key($k) !== ''],
                                      array_keys(STOCK_PROVIDERS), STOCK_PROVIDERS),
              'licenses' => array_map(fn($k, $l) => ['key' => $k, 'name' => $l, 'hint' => IMG_LICENSE_HINTS[$k] ?? ''], array_keys(IMG_LICENSES), IMG_LICENSES)]);

case 'stock_search':   // GET p, q – jen hledá, nic nestahuje
    require_once __DIR__ . '/app/lib/stockimg.php';
    $q = trim((string)($_GET['q'] ?? ''));
    try { $hits = $q === '' ? [] : stock_search((string)($_GET['p'] ?? 'commons'), $q); }
    catch (RuntimeException $e) { json_out(['error' => $e->getMessage()], 422); }
    $have = array_flip(array_filter($pdo->query("SELECT source_url FROM library WHERE source_url IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)));
    json_out(['items' => array_map(fn($h) => ['title' => $h['title'], 'thumb' => $h['thumb'], 'width' => $h['width'], 'height' => $h['height'],
        'page_url' => $h['page_url'], 'ref' => $h['ref'] ?? $h['page_url'], 'credit_text' => img_credit_text(img_credit_from($h['credit'])),
        'in_library' => isset($have[$h['page_url']])], $hits)]);

case 'stock_import':   // url (jedna) nebo urls (víc řádků, max. 20), tags[] – stáhne do knihovny s autorem a licencí
    require_once __DIR__ . '/app/lib/stockimg.php';
    @set_time_limit(300);
    try { $tags = tags_clean($pdo, (array)($_POST['tags'] ?? [])); } catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }
    $urls = isset($_POST['urls']) ? preg_split('~[\r\n]+~', (string)$_POST['urls']) : [(string)($_POST['url'] ?? '')];
    $urls = array_slice(array_values(array_filter(array_map('trim', $urls), fn($u) => $u !== '')), 0, 20);
    if (!$urls) json_out(['error' => 'Vložte odkaz na obrázek.'], 422);
    $ids = []; $had = 0; $errors = [];
    foreach ($urls as $u) {
        try {
            [$id, $existed] = stock_import($pdo, stock_resolve_url($u), $tags);
            $ids[] = $id; $existed ? $had++ : log_line("Knihovna médií: obrázek z internetu $u");
        } catch (RuntimeException $e) { $errors[] = (count($urls) > 1 ? $u . ': ' : '') . $e->getMessage(); }
    }
    if (!$ids) json_out(['error' => implode(' ', $errors)], 422);
    json_out(['ok' => true, 'ids' => $ids, 'new' => count($ids) - $had, 'had' => $had, 'errors' => $errors]);

case 'stock_keys':   // GET: jsou klíče nastavené? POST pixabay_key, pexels_key (prázdné = beze změny, clear_* = smazat)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['pixabay' => trim((string)meta_get('pixabay_key', '')) !== '', 'pexels' => trim((string)meta_get('pexels_key', '')) !== '']);
    foreach (['pixabay', 'pexels'] as $p) {
        $k = trim((string)($_POST[$p . '_key'] ?? ''));
        if (!empty($_POST['clear_' . $p])) meta_set($p . '_key', '');
        elseif ($k !== '') {
            if (!preg_match('/^[\x21-\x7e]{8,200}$/', $k)) json_out(['error' => 'API klíč ' . ucfirst($p) . ' má nesprávný tvar.'], 422);
            meta_set($p . '_key', $k);
        }
    }
    json_out(['ok' => true]);

case 'library_credit':   // id, author, source, license, license_other, source_url, license_url – ruční úprava autora a licence
    require_once __DIR__ . '/app/lib/stockimg.php';
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE library SET credit = ? WHERE id = ?")->execute([img_credit_from($_POST), $id]);
    json_out(['ok' => true]);

// ---------- Slidy (1.6.0) ----------
case 'fonts':
    require_once __DIR__ . '/app/lib/fonts.php';
    json_out(array_map('font_out', array_keys(fonts_all())));

case 'font':   // soubor vlastního písma (app/data je z internetu zablokovaná)
    require_once __DIR__ . '/app/lib/fonts.php';
    $f = fonts_all()[(string)($_GET['key'] ?? '')] ?? null;
    if (!$f || !$f['custom']) json_out(['error' => 'Písmo nenalezeno.'], 404);
    $path = CUSTOM_FONT_DIR . '/' . $f['file'];
    header('Content-Type: ' . (str_ends_with($f['file'], '.otf') ? 'font/otf' : 'font/ttf'));
    header('Cache-Control: private, max-age=604800');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;

case 'font_upload':
    require_once __DIR__ . '/app/lib/fonts.php';
    $f = $_FILES['font'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) json_out(['error' => 'Vyberte soubor s písmem (TTF nebo OTF).'], 422);
    try { [$font, $missing] = font_upload($f['tmp_name'], (string)$f['name'], (string)($_POST['name'] ?? '')); json_out(['font' => $font, 'missing' => $missing]); }
    catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'font_rename':
    require_once __DIR__ . '/app/lib/fonts.php';
    try { json_out(['font' => font_rename((string)($_POST['key'] ?? ''), (string)($_POST['name'] ?? ''))]); }
    catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'font_delete':
    require_once __DIR__ . '/app/lib/fonts.php';
    try { font_delete((string)($_POST['key'] ?? '')); json_out(['ok' => true]); }
    catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }

case 'fx_styles':
    require_once __DIR__ . '/app/lib/slide.php';
    json_out(['styles' => FX_STYLES, 'stops' => FX_STOPS, 'ranges' => FX_RANGES]);

case 'slide_formats':
    require_once __DIR__ . '/app/lib/slide.php';
    json_out(array_map(fn($k, $f) => ['key' => $k, 'w' => $f[0], 'h' => $f[1], 'name' => $f[2]], array_keys(SLIDE_FORMATS), SLIDE_FORMATS));

case 'slide_get':
    require_once __DIR__ . '/app/lib/slide.php';
    $m = slide_get((int)($_GET['id'] ?? 0));
    if (!$m) json_out(['error' => 'Slide nenalezen.'], 404);
    json_out(['id' => (int)$m['id'], 'name' => $m['name'], 'tags' => item_tags($m), 'design' => $m['design'], 'file' => $m['file'],
              'url' => 'uploads/' . rawurlencode($m['file']) . '?v=' . (int)@filemtime(PUBLIC_DIR . '/uploads/' . $m['file']), 'used' => library_usage($pdo, $m['file'])]);

case 'resolve':   // editor: dosazené proměnné + přesné rozvržení textů tak, jak je vykreslí server (čas = teď, nebo ts)
    require_once __DIR__ . '/app/lib/slide.php';
    $t = !empty($_POST['ts']) ? dt_from_ts((int)$_POST['ts']) : new DateTimeImmutable();
    $design = sanitize_design($_POST['design'] ?? []);
    $res = [];
    $ev = design_eval($design, $t);
    foreach ($design['elements'] as $e) {
        $r = $ev[$e['id']];
        if ($e['type'] === 'text') { $L = layout_text($e, $r['text']); unset($L['font']); $r['layout'] = $L; }
        if ($e['type'] === 'weather') $r = weather_editor_info($e, $t) + $r;
        $res[$e['id']] = $r;
    }
    json_out($res);

case 'slide_preview':
    require_once __DIR__ . '/app/lib/slide.php';
    @set_time_limit(60);
    $t = !empty($_POST['ts']) ? dt_from_ts((int)$_POST['ts']) : new DateTimeImmutable();
    try { $jpg = render_slide(sanitize_design($_POST['design'] ?? []), $t, 85); }
    catch (Throwable $e) { json_out(['error' => 'Slide se nepodařilo vykreslit: ' . $e->getMessage()], 500); }
    json_out(['image' => 'data:image/jpeg;base64,' . base64_encode($jpg)]);

case 'slide_save':   // id (0 = nový), name, design, tags[]
    require_once __DIR__ . '/app/lib/slide.php';
    @set_time_limit(60);
    $id = (int)($_POST['id'] ?? 0);
    if ($id && !slide_get($id)) json_out(['error' => 'Slide nenalezen.'], 404);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    if ($name === '') json_out(['error' => 'Zadejte název slidu.'], 422);
    $design = sanitize_design($_POST['design'] ?? []);
    try { $tags = array_key_exists('tags', $_POST) ? tags_clean($pdo, (array)$_POST['tags']) : null; }
    catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }
    $new = !$id;
    if ($new) {
        $file = bin2hex(random_bytes(12)) . '.jpg';
        $pdo->prepare("INSERT INTO library (file, name, type, size, w, h, created_at, tags, design) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$file, $name, 'image', 0, $design['w'], $design['h'], time(), $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null, json_encode($design, JSON_UNESCAPED_UNICODE)]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE library SET name = ? WHERE id = ?")->execute([$name, $id]);
        if ($tags !== null) item_set_tags($pdo, $id, $tags);
    }
    try { $file = slide_store($id, $design, new DateTimeImmutable()); }
    catch (Throwable $e) {
        if ($new) $pdo->prepare("DELETE FROM library WHERE id = ?")->execute([$id]);
        json_out(['error' => 'Slide se nepodařilo vykreslit: ' . $e->getMessage()], 500);
    }
    log_line("Slide „{$name}“ uložen.");
    json_out(['ok' => true, 'media' => ['id' => $id, 'name' => $name, 'file' => $file, 'url' => 'uploads/' . rawurlencode($file) . '?v=' . time(), 'w' => $design['w'], 'h' => $design['h']]]);

case 'slide_duplicate':
    require_once __DIR__ . '/app/lib/slide.php';
    $m = slide_get((int)($_POST['id'] ?? 0));
    if (!$m) json_out(['error' => 'Slide nenalezen.'], 404);
    $name = mb_substr($m['name'] . ' (kopie)', 0, 120);
    $file = bin2hex(random_bytes(12)) . '.jpg';
    $pdo->prepare("INSERT INTO library (file, name, type, size, w, h, created_at, tags, design) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$file, $name, 'image', 0, $m['w'], $m['h'], time(), $m['tags'], json_encode($m['design'], JSON_UNESCAPED_UNICODE)]);
    $nid = (int)$pdo->lastInsertId();
    try { slide_store($nid, $m['design'], new DateTimeImmutable()); }
    catch (Throwable $e) { $pdo->prepare("DELETE FROM library WHERE id = ?")->execute([$nid]); json_out(['error' => 'Slide se nepodařilo vykreslit: ' . $e->getMessage()], 500); }
    json_out(['ok' => true, 'id' => $nid]);

// ---------- Proměnné a data (1.5.0) ----------
case 'data_list':
    $today = new DateTimeImmutable('today');
    json_out(['rows' => array_map(function ($r) use ($today) {
        $d = parse_any_date($r['param']);
        return ['id' => (int)$r['id'], 'grp' => $r['grp'], 'param' => $r['param'], 'value' => $r['value'],
                'date' => $d ? $d->format('Y-m-d') : null, 'past' => $d ? $d < $today : false];
    }, data_rows(true))]);

case 'data_save':   // jeden řádek: id (0 = nový), grp, param, value
    try { [$g, $p, $v] = data_clean_row($_POST['grp'] ?? '', $_POST['param'] ?? '', $_POST['value'] ?? ''); }
    catch (InvalidArgumentException $e) { json_out(['error' => 'Řádek nelze uložit: ' . $e->getMessage() . '.'], 422); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id) $pdo->prepare('UPDATE data_rows SET grp = ?, param = ?, value = ?, updated_at = ? WHERE id = ?')->execute([$g, $p, $v, time(), $id]);
    else { $pdo->prepare('INSERT INTO data_rows (grp, param, value, updated_at) VALUES (?,?,?,?)')->execute([$g, $p, $v, time()]); $id = (int)$pdo->lastInsertId(); }
    json_out(['ok' => true, 'id' => $id]);

case 'data_import':   // text (řádky Skupina<TAB>Parametr<TAB>Hodnota z Excelu), replace = nahradit skupiny
    $rows = []; $errors = [];
    foreach (preg_split('/\r?\n/', (string)($_POST['text'] ?? '')) as $i => $line) {
        if (trim($line) === '') continue;
        $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : (str_contains($line, '|') ? '|' : null));
        $cols = $sep ? array_map('trim', explode($sep, $line)) : [];
        if (count($cols) < 3) { $errors[] = 'řádek ' . ($i + 1) . ': očekávám 3 sloupce (Skupina, Parametr, Hodnota)'; continue; }
        if ($i === 0 && norm_key($cols[0]) === 'skupina') continue;   // záhlaví
        try { $rows[] = data_clean_row($cols[0], $cols[1], implode(' ', array_slice($cols, 2))); }
        catch (Throwable $e) { $errors[] = 'řádek ' . ($i + 1) . ': ' . $e->getMessage(); }
    }
    if (!$rows) json_out(['error' => $errors ? 'Nic k vložení – ' . implode('; ', array_slice($errors, 0, 3)) : 'Vložte řádky z Excelu (Skupina, Parametr, Hodnota).'], 422);
    $pdo->beginTransaction();
    if (!empty($_POST['replace'])) {
        $del = $pdo->prepare('DELETE FROM data_rows WHERE id = ?');
        foreach (array_unique(array_map(fn($r) => norm_key($r[0]), $rows)) as $gk) foreach (data_rows() as $old) if (norm_key($old['grp']) === $gk) $del->execute([(int)$old['id']]);
    }
    $ins = $pdo->prepare('INSERT INTO data_rows (grp, param, value, updated_at) VALUES (?,?,?,?)');
    foreach ($rows as [$g, $p, $v]) $ins->execute([$g, $p, $v, time()]);
    $pdo->commit();
    json_out(['ok' => true, 'imported' => count($rows), 'errors' => $errors]);

case 'data_delete':
    $del = $pdo->prepare('DELETE FROM data_rows WHERE id = ?');
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    foreach ($ids as $id) $del->execute([$id]);
    json_out(['ok' => true, 'deleted' => count($ids)]);

case 'data_delete_past':
    $today = new DateTimeImmutable('today'); $n = 0;
    $del = $pdo->prepare('DELETE FROM data_rows WHERE id = ?');
    foreach (data_rows(true) as $r) { $d = parse_any_date($r['param']); if ($d && $d < $today) { $del->execute([(int)$r['id']]); $n++; } }
    json_out(['ok' => true, 'deleted' => $n]);

case 'data_preview':   // aktuální hodnoty skupiny pro nápovědu
    $t = new DateTimeImmutable(); $g = (string)($_GET['grp'] ?? '');
    json_out(['nearest' => data_value("$g.nejbližší", '', $t), 'nearestParam' => data_value("$g.nejbližší.parametr", '', $t), 'last' => data_value("$g.poslední", '', $t)]);

case 'sources':
    json_out(['sources' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'url' => $x['url'], 'interval_min' => (int)$x['interval_min'],
        'format' => $x['format'], 'csv_header' => (int)$x['csv_header'] === 1,
        'rows' => $x['format'] === 'csv' && is_array($x['json']) ? count($x['json']) : null,
        'fetched_at' => $x['fetched_at'] ? (int)$x['fetched_at'] : null, 'ok_at' => $x['ok_at'] ? (int)$x['ok_at'] : null, 'error' => $x['error'],
        'has_data' => $x['json'] !== null], sources_all(true))]);

case 'source_save':
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $interval = max(5, min(1440, (int)($_POST['interval_min'] ?? 15)));
    $format = ($_POST['format'] ?? 'json') === 'csv' ? 'csv' : 'json';
    $csvHeader = !isset($_POST['csv_header']) || !empty($_POST['csv_header']) ? 1 : 0;
    if ($name === '' || mb_strlen($name) > 60) json_out(['error' => 'Zadejte název zdroje (nejvýš 60 znaků).'], 422);
    if (preg_match('/[{}|:.]/u', $name)) json_out(['error' => 'Název zdroje nesmí obsahovat znaky { } | : a tečku.'], 422);
    if (in_array(norm_key($name), CALENDAR_VARS, true)) json_out(['error' => 'Tento název je vyhrazený pro proměnnou kalendáře.'], 422);
    foreach (sources_all(true) as $x) if ((int)$x['id'] !== $id && norm_key($x['name']) === norm_key($name)) json_out(['error' => 'Zdroj s tímto názvem už existuje.'], 422);
    if (!preg_match('~^https?://~i', $url)) json_out(['error' => 'Adresa musí začínat http:// nebo https://.'], 422);
    if ($id) {
        $o = $pdo->prepare('SELECT url, format, csv_header FROM sources WHERE id = ?'); $o->execute([$id]); $old = $o->fetch();
        $pdo->prepare('UPDATE sources SET name = ?, url = ?, interval_min = ?, format = ?, csv_header = ? WHERE id = ?')->execute([$name, $url, $interval, $format, $csvHeader, $id]);
        if (!$old || $old['url'] !== $url || $old['format'] !== $format || (int)$old['csv_header'] !== $csvHeader) $pdo->prepare('UPDATE sources SET fetched_at = NULL, hash = NULL, error = NULL WHERE id = ?')->execute([$id]);
    } else {
        $pdo->prepare('INSERT INTO sources (name, url, interval_min, format, csv_header, created_at) VALUES (?,?,?,?,?,?)')->execute([$name, $url, $interval, $format, $csvHeader, time()]);
        $id = (int)$pdo->lastInsertId();
    }
    $src = null; foreach (sources_all(true) as $x) if ((int)$x['id'] === $id) $src = $x;
    $r = source_fetch($src);   // hned stáhnout, ať je vidět, jestli funguje
    json_out(['ok' => $r['ok'], 'id' => $id, 'error' => $r['error']]);

case 'source_delete':
    $pdo->prepare('DELETE FROM sources WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'source_fetch':
    $src = null; foreach (sources_all(true) as $x) if ((int)$x['id'] === (int)($_POST['id'] ?? 0)) $src = $x;
    if (!$src) json_out(['error' => 'Zdroj nenalezen.'], 404);
    json_out(source_fetch($src));

case 'source_tree':   // hodnoty všech zdrojů pro výběr proměnné
    json_out(['sources' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'ok_at' => $x['ok_at'] ? (int)$x['ok_at'] : null, 'error' => $x['error'],
        'groups' => $x['json'] !== null ? source_tree($x) : []], sources_all(true))]);

case 'weather_settings':
    $rows = $pdo->query('SELECT place, fetched_at, ok_at, error FROM weather ORDER BY place')->fetchAll();
    json_out(['key_set' => weather_configured(), 'place' => weather_default_place(), 'interval' => WEATHER_INTERVAL_MIN, 'key_url' => WEATHER_KEY_URL, 'places' => $rows]);

case 'weather_save':
    $key = trim((string)($_POST['api_key'] ?? ''));
    if ($key !== '' && !preg_match('/^[\x21-\x7e]{8,200}$/', $key)) json_out(['error' => 'API klíč má nesprávný tvar.'], 422);
    $place = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['place'] ?? ''))), 0, 100);
    $changed = ($key !== '' && $key !== weather_api_key()) || !empty($_POST['clear_key']);
    if ($key !== '') meta_set('weather_api_key', $key);
    if (!empty($_POST['clear_key'])) meta_set('weather_api_key', '');
    meta_set('weather_place', $place);
    if ($changed) $pdo->exec('UPDATE weather SET fetched_at = NULL');   // jiný klíč: stáhnout znovu
    json_out(['ok' => true]);

case 'weather_test':
    if (!weather_configured()) json_out(['error' => 'Nejdřív uložte API klíč.'], 422);
    $place = weather_place((string)($_POST['place'] ?? ''));
    if ($place === '') json_out(['error' => 'Zadejte místo.'], 422);
    $r = weather_fetch($place);
    if (!$r['ok']) json_out(['error' => $r['error']], 422);
    $t = new DateTimeImmutable(); $d = $r['data']; $days = [];
    for ($i = 0; $i < 3; $i++) if ($x = weather_day($d, $i, $t)) $days[] = ['label' => weather_day_label($x['date'], $t), 'max' => weather_int($x['max']), 'min' => weather_int($x['min']), 'icon' => ow_icon_url($x['icon']), 'desc' => weather_ucfirst($x['desc'])];
    json_out(['place' => $place, 'name' => $d['name'] ?? '', 'temp' => weather_int($d['now']['temp']), 'feels' => weather_int($d['now']['feels']),
              'desc' => weather_ucfirst($d['now']['desc']), 'icon' => ow_icon_url($d['now']['icon']), 'humidity' => $d['now']['humidity'], 'wind' => weather_int($d['now']['wind']), 'days' => $days]);

case 'datefmt':   // dialogy Formát data / čísla: jak vypadá proměnná expr v jednotlivých formátech (dnes)
    $expr = trim((string)($_POST['expr'] ?? ''));
    $fmts = array_slice(array_map('strval', (array)($_POST['formats'] ?? [])), 0, 40);
    if ($expr === '' || mb_strlen($expr) > 300 || preg_match('/[{}|]/', $expr)) json_out(['error' => 'Neplatná proměnná.'], 422);
    $user = user_vars($pdo); $t = new DateTimeImmutable(); $res = [];
    foreach ($fmts as $f) {
        if (mb_strlen($f) > 150 || preg_match('/[{}]/', $f)) { $res[] = null; continue; }
        [$txt, $unk] = resolve_vars('{{' . $expr . $f . '}}', $t, ['user' => $user]);
        $res[] = $unk ? null : $txt;
    }
    json_out(['values' => $res]);

case 'vars_preview':   // náhled textu s dosazenými proměnnými pro čas ts (Nový příspěvek)
    $ts = (int)($_POST['ts'] ?? 0) ?: time();
    $out = [];
    foreach ((array)($_POST['texts'] ?? []) as $i => $txt) {
        [$v, $unk] = resolve_vars(mb_substr((string)$txt, 0, 70000), dt_from_ts($ts), ['index' => 0, 'user' => user_vars($pdo)]);
        $out[] = ['text' => $v, 'unknown' => $unk];
    }
    json_out(['items' => $out]);

case 'get':
    $q = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $p = $q->fetch();
    if (!$p) json_out(['error' => 'Příspěvek nenalezen.'], 404);
    $p['media_url'] = $p['media_file'] ? 'uploads/' . rawurlencode($p['media_file']) : null;
    $p['media'] = media_list($pdo, $p);
    $t = $pdo->prepare("SELECT account_id FROM targets WHERE post_id = ?");
    $t->execute([$p['id']]);
    $p['account_ids'] = array_map('intval', array_column($t->fetchAll(), 'account_id'));
    json_out(['post' => $p]);

case 'create':
case 'update':
    $editing = $action === 'update';
    $old = null;
    if ($editing) {
        $q = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
        $q->execute([(int)($_POST['id'] ?? 0)]);
        $old = $q->fetch();
        if (!$old) json_out(['error' => 'Příspěvek nenalezen.'], 404);
        if (!in_array($old['status'], ['draft', 'scheduled', 'failed', 'partial'], true)) json_out(['error' => 'Upravit lze jen naplánovaný nebo neodeslaný příspěvek. Odeslaný příspěvek můžete duplikovat.'], 422);
    }
    $message = trim((string)($_POST['message'] ?? ''));
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['accounts'] ?? []))));
    $when = (string)($_POST['scheduled_at'] ?? '');
    $draft = !empty($_POST['draft']);                 // koncept: nic se neodesílá, stačí rozpracovaný obsah
    $now_flag = !$draft && !empty($_POST['now']);          // publikovat hned lze i při úpravě (koncept, naplánovaný, neodeslaný)
    if (!$ids && !$draft) json_out(['error' => 'Vyberte alespoň jeden účet.'], 422);

    $ts = $now_flag ? time() : strtotime($when);
    if ($ts === false) { if ($draft) $ts = time(); else json_out(['error' => 'Neplatné datum a čas.'], 422); }
    if (!$draft) {
        $bad = unknown_vars($message, user_vars($pdo), $ts);
        if ($bad) json_out(['error' => 'Neznámá proměnná: ' . implode(', ', $bad) . '. Zkontrolujte zápis v nabídce Proměnné a data.'], 422);
    }

    // odkaz a štítky
    $link = trim((string)($_POST['link'] ?? ''));
    if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) {
        json_out(['error' => 'Odkaz musí začínat http:// nebo https://.'], 422);
    }
    $fc = trim((string)($_POST['first_comment'] ?? ''));
    if (mb_strlen($fc) > 2000) json_out(['error' => 'První komentář může mít nejvýše 2 000 znaků.'], 422);
    if (!$draft && $fc !== '') {
        $bad = unknown_vars($fc, user_vars($pdo), $ts);
        if ($bad) json_out(['error' => 'Neznámá proměnná v komentáři: ' . implode(', ', $bad) . '.'], 422);
    }
    $tagList = [];
    foreach (preg_split('/[\s,;]+/u', (string)($_POST['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim($t, '#'));
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tagList), true)) $tagList[] = $t;
    }
    if (count($tagList) > 30) json_out(['error' => 'Instagram povoluje nejvýše 30 štítků.'], 422);
    $tags = implode(' ', $tagList);

    $accs = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT * FROM accounts WHERE id IN ($in)");
        $q->execute($ids);
        $accs = $q->fetchAll();
    }
    if (count($accs) !== count($ids)) json_out(['error' => 'Neznámý účet.'], 422);
    foreach ($accs as $a) {
        if (!(int)$a['enabled']) json_out(['error' => 'Účet „' . $a['name'] . '“ je v nastavení zakázán.'], 422);
    }
    $hasIg = (bool)array_filter($accs, fn($a) => $a['platform'] === 'instagram');
    $hasTh = (bool)array_filter($accs, fn($a) => $a['platform'] === 'threads');
    if (!$draft && $hasTh && mb_strlen($fc) > 500) json_out(['error' => 'První komentář pro Threads může mít nejvýše 500 znaků.'], 422);

    // Média: pořadí určuje pole „order“ (e:soubor = už uložený, n:i = i-tý nově nahraný soubor)
    $current = []; $src = $old;
    if (!$editing && !empty($_POST['copy_from'])) {    // duplikace: média zdrojového příspěvku lze znovu použít
        $q = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
        $q->execute([(int)$_POST['copy_from']]);
        $src = $q->fetch() ?: null;
    }
    if ($src) foreach (media_list($pdo, $src) as $m) $current[$m['file']] = $m['type'];
    $uploads = [];
    if (!empty($_FILES['media']['name'])) {
        $f = $_FILES['media'];
        foreach ((array)$f['name'] as $i => $n) {
            $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) json_out(['error' => 'Nahrání souboru selhalo (kód ' . $err . ', zkontrolujte upload_max_filesize a post_max_size).'], 422);
            $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $map = ['image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'video/mp4' => ['video', 'mp4'], 'video/quicktime' => ['video', 'mov']];
            if (!isset($map[$mime])) json_out(['error' => 'Podporováno: JPEG, PNG, MP4, MOV.'], 422);
            $uploads[] = ['tmp' => $tmp, 'type' => $map[$mime][0], 'ext' => $map[$mime][1], 'mime' => $mime, 'orig' => (string)(is_array($f['name']) ? $f['name'][$i] : $f['name'])];
        }
    }
    if (isset($_POST['order'])) {
        $order = json_decode((string)$_POST['order'], true);
        if (!is_array($order)) json_out(['error' => 'Neplatné pořadí médií.'], 422);
    } else {   // starší klient: staré médium zůstane, nahrané soubory se přidají
        $order = array_map(fn($f) => 'e:' . $f, array_keys($current));
        if ($uploads) $order = array_map(fn($i) => 'n:' . $i, array_keys($uploads));
        if ($editing && !empty($_POST['remove_media'])) $order = [];
    }
    if (count($order) > 10) json_out(['error' => 'Nejvýše 10 obrázků v jednom příspěvku.'], 422);
    $items = [];   // [file|null, upload|null, type]
    foreach ($order as $tok) {
        $tok = (string)$tok;
        if (str_starts_with($tok, 'e:')) {
            $fn = substr($tok, 2);
            if (!isset($current[$fn])) {            // soubor z knihovny
                $lq = $pdo->prepare("SELECT type FROM library WHERE file = ?"); $lq->execute([basename($fn)]);
                $lt = $lq->fetchColumn();
                if ($lt === false) json_out(['error' => 'Neznámé médium.'], 422);
                $current[$fn] = (string)$lt;
            }
            $items[] = [$fn, null, $current[$fn]];
        } elseif (str_starts_with($tok, 'n:') && isset($uploads[(int)substr($tok, 2)])) {
            $u = $uploads[(int)substr($tok, 2)];
            $items[] = [null, $u, $u['type']];
        } else json_out(['error' => 'Neplatné médium.'], 422);
    }
    $hasVideo = (bool)array_filter($items, fn($i) => $i[2] === 'video');
    if ($hasVideo && count($items) > 1) json_out(['error' => 'Video nelze kombinovat s dalšími soubory.'], 422);
    foreach ($items as $it) {
        if ($hasIg && (($it[1]['mime'] ?? '') === 'image/png' || ($it[0] && str_ends_with($it[0], '.png')))) {
            json_out(['error' => 'Instagram přijímá jen JPEG obrázky. Nahrajte obrázek znovu (převede se na JPEG).'], 422);
        }
    }
    $kind = (string)($_POST['kind'] ?? 'feed');
    if (!in_array($kind, ['feed', 'reel', 'story'], true)) $kind = 'feed';
    if (!$draft && $kind !== 'feed') {
        $hasFb = (bool)array_filter($accs, fn($a) => $a['platform'] === 'facebook');
        if ($kind === 'story') {
            if (count($items) !== 1) json_out(['error' => 'Story má právě jeden obrázek nebo video.'], 422);
            if ($hasFb && $hasVideo) json_out(['error' => 'Video story na Facebooku aplikace zatím nepodporuje – odeberte Facebook nebo použijte obrázek.'], 422);
        } else {
            if (!$hasVideo) json_out(['error' => 'Reel vyžaduje jedno video.'], 422);
            if ($hasFb) json_out(['error' => 'Reels na Facebooku aplikace zatím nepodporuje – vyberte jen Instagram.'], 422);
        }
    }
    $file = $items ? 'x' : null;     // jen příznak; skutečné názvy vzniknou při ukládání
    $type = $items ? $items[0][2] : null;
    if ($hasIg && !$file && !$draft) json_out(['error' => 'Instagram vyžaduje obrázek nebo video.'], 422);
    if (!$file && $message === '' && $link === '') json_out(['error' => 'Napište text, přidejte odkaz nebo médium.'], 422);
    if ($hasIg && !$draft && mb_strlen(compose_text(['message' => $message, 'link' => $link, 'tags' => $tags, 'scheduled_at' => $ts])) > 2200) {
        json_out(['error' => 'Text včetně odkazu a štítků je delší než 2 200 znaků (limit Instagramu).'], 422);
    }

    if ($hasTh && !$draft) {
        if ($kind !== 'feed') json_out(['error' => 'Reels a Stories Threads nepodporují – odeberte účet Threads nebo zvolte typ „Příspěvek“.'], 422);
        if (mb_strlen(compose_text(['message' => $message, 'link' => $link, 'tags' => $tags, 'scheduled_at' => $ts])) > 500) {
            json_out(['error' => 'Text včetně odkazu a štítků je delší než 500 znaků (limit Threads).'], 422);
        }
    }

    // Opakování
    $mode = (string)($_POST['repeat'] ?? 'none');
    if (!in_array($mode, REPEAT_MODES, true)) json_out(['error' => 'Neplatné opakování.'], 422);
    $until = null; $rday = null;
    if ($mode !== 'none') {
        $rday = (int)date('j', $ts);
        $u = trim((string)($_POST['repeat_until'] ?? ''));
        if ($u !== '') {
            $until = strtotime($u . ' 23:59:59');
            if ($until === false) json_out(['error' => 'Neplatné datum konce opakování.'], 422);
            if ($until < $ts) json_out(['error' => 'Konec opakování musí být po prvním termínu.'], 422);
        }
    }

    // uložit nové soubory na disk
    $names = [];
    foreach ($items as $it) {
        if ($it[0] !== null) { $names[] = $it[0]; continue; }
        $fn = bin2hex(random_bytes(12)) . '.' . $it[1]['ext'];
        if (!move_uploaded_file($it[1]['tmp'], PUBLIC_DIR . '/uploads/' . $fn)) json_out(['error' => 'Soubor se nepodařilo uložit.'], 500);
        $names[] = $fn;
        library_register($pdo, $fn, $it[1]['type'], (string)($it[1]['orig'] ?? ''));
    }
    $file = $names[0] ?? null;

    $pdo->beginTransaction();
    if ($editing) {
        $pid = (int)$old['id'];
        $pdo->prepare("UPDATE posts SET status=?, message=?, media_file=?, media_type=?, scheduled_at=?, repeat_mode=?, repeat_until=?, repeat_day=?, link=?, tags=?, first_comment=?, kind=? WHERE id=?")
            ->execute([$draft ? 'draft' : 'scheduled', $message, $file, $type, $ts, $mode, $until, $rday, $link ?: null, $tags ?: null, $fc ?: null, $kind, $pid]);
        $pdo->prepare("DELETE FROM targets WHERE post_id = ? AND status != 'done'")->execute([$pid]);
        $have = $pdo->prepare("SELECT account_id FROM targets WHERE post_id = ?");
        $have->execute([$pid]);
        $ids = array_values(array_diff($ids, array_map('intval', array_column($have->fetchAll(), 'account_id'))));
    } else {
        $pdo->prepare("INSERT INTO posts (message, media_file, media_type, scheduled_at, status, created_at, repeat_mode, repeat_until, repeat_day, link, tags, first_comment, kind)
                       VALUES (?,?,?,?, ?, ?, ?,?,?, ?,?,?,?)")
            ->execute([$message, $file, $type, $ts, $draft ? 'draft' : 'scheduled', time(), $mode, $until, $rday, $link ?: null, $tags ?: null, $fc ?: null, $kind]);
        $pid = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM post_media WHERE post_id = ?")->execute([$pid]);
    $pm = $pdo->prepare("INSERT INTO post_media (post_id, file, position) VALUES (?,?,?)");
    foreach (array_slice($names, 1) as $i => $fn) $pm->execute([$pid, $fn, $i + 1]);
    if ($mode !== 'none') $pdo->prepare("UPDATE posts SET series_id = COALESCE(series_id, id) WHERE id = ?")->execute([$pid]);
    $ti = $pdo->prepare("INSERT INTO targets (post_id, account_id) VALUES (?,?)");
    foreach ($ids as $aid) $ti->execute([$pid, $aid]);
    $pdo->commit();

    // původní soubory, které už nic nepoužívá, smažeme
    foreach (array_keys($current) as $fn) {
        if (!in_array($fn, $names, true) && !media_in_use($pdo, $fn)) @unlink(PUBLIC_DIR . '/uploads/' . basename($fn));
    }
    if ($now_flag) { run_due(); }
    json_out(['ok' => true, 'id' => $pid]);

case 'delete':
    $id = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $q->execute([$id]);
    $p = $q->fetch();
    if (!$p) json_out(['error' => 'Nenalezeno'], 404);
    $files = array_column(media_list($pdo, $p), 'file');
    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);   // post_media se smaže kaskádou
    // média sdílí všechny výskyty série – smažeme je až s posledním příspěvkem, který je používá
    foreach ($files as $fn) {
        if (!media_in_use($pdo, $fn) && !media_in_library($pdo, $fn)) @unlink(PUBLIC_DIR . '/uploads/' . basename($fn));
    }
    json_out(['ok' => true]);

case 'overview':
    $cnt = function (string $sql, array $p = []) use ($pdo): int { $q = $pdo->prepare($sql); $q->execute($p); return (int)$q->fetchColumn(); };
    $rows = function (string $sql, array $p = []) use ($pdo): array { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll(); };
    $now = time(); $d30 = $now - 30 * 86400;
    $next = $rows("SELECT id, message, scheduled_at, repeat_mode, repeat_until, media_file, media_type, status FROM posts WHERE status = 'scheduled' ORDER BY scheduled_at LIMIT 8");
    $tq2 = $pdo->prepare("SELECT COUNT(*) FROM targets WHERE post_id = ?");
    foreach ($next as &$n) { $tq2->execute([$n['id']]); $n['targets_count'] = (int)$tq2->fetchColumn(); }
    unset($n);
    // odesláno po dnech (posledních 14 dní, lokální čas)
    $byDay = [];
    for ($i = 13; $i >= 0; $i--) $byDay[date('Y-m-d', $now - $i * 86400)] = 0;
    foreach ($rows("SELECT published_at FROM targets WHERE status = 'done' AND published_at >= ?", [$now - 14 * 86400]) as $r) {
        $k = date('Y-m-d', (int)$r['published_at']); if (isset($byDay[$k])) $byDay[$k]++;
    }
    $sum = $rows("SELECT COALESCE(SUM(stat_reach),0) reach, COALESCE(SUM(stat_likes),0) likes, COALESCE(SUM(stat_comments),0) comments, COALESCE(SUM(stat_shares),0) shares, COUNT(*) n
                  FROM targets WHERE status = 'done' AND published_at >= ?", [$d30])[0];
    json_out([
        'scheduled'  => $cnt("SELECT COUNT(*) FROM posts WHERE status = 'scheduled'"),
        'drafts'     => $cnt("SELECT COUNT(*) FROM posts WHERE status = 'draft'"),
        'api_drafts' => $cnt("SELECT COUNT(*) FROM posts WHERE status = 'draft' AND source IS NOT NULL"),
        'sent7'      => $cnt("SELECT COUNT(*) FROM targets WHERE status = 'done' AND published_at >= ?", [$now - 7 * 86400]),
        'sent30'     => (int)$sum['n'],
        'attention'  => $cnt("SELECT COUNT(*) FROM posts WHERE status IN ('failed','partial')"),
        'accounts_on' => $cnt("SELECT COUNT(*) FROM accounts WHERE enabled = 1"),
        'accounts_total' => $cnt("SELECT COUNT(*) FROM accounts"),
        'bad_accounts' => $rows("SELECT platform, name, health_msg FROM accounts WHERE enabled = 1 AND health = 'error' ORDER BY name"),
        'expiring' => $rows("SELECT platform, name, token_expires FROM accounts WHERE enabled = 1 AND token_expires IS NOT NULL AND token_expires < ? ORDER BY token_expires", [$now + 14 * 86400]),
        'failed' => $rows("SELECT id, message, scheduled_at, status FROM posts WHERE status IN ('failed','partial') ORDER BY scheduled_at DESC LIMIT 5"),
        'next' => $next,
        'by_day' => $byDay,
        'by_net' => $rows("SELECT a.platform, COUNT(*) n FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.status = 'done' AND t.published_at >= ? GROUP BY a.platform", [$d30]),
        'totals' => ['reach' => (int)$sum['reach'], 'likes' => (int)$sum['likes'], 'comments' => (int)$sum['comments'], 'shares' => (int)$sum['shares']],
        'top' => $rows("SELECT t.post_id, p.message, a.platform, a.name AS account, t.stat_likes, t.stat_comments, t.stat_reach
                        FROM targets t JOIN accounts a ON a.id = t.account_id JOIN posts p ON p.id = t.post_id
                        WHERE t.status = 'done' AND t.published_at >= ? AND t.stat_likes IS NOT NULL
                        ORDER BY (COALESCE(t.stat_likes,0) + COALESCE(t.stat_comments,0)) DESC LIMIT 5", [$d30]),
        'gd_error' => meta_get('gd_enabled', '0') === '1' ? meta_get('gd_error', '') : '',
        'times' => ['cron' => (int)meta_get('cron_last', '0'), 'health' => (int)meta_get('last_health', '0'), 'stats' => (int)meta_get('last_stats', '0'), 'backup' => (int)meta_get('backup_last', '0')],
    ]);

case 'calendar':
    $from = (int)($_GET['from'] ?? 0);
    $to   = (int)($_GET['to'] ?? 0);
    if ($from <= 0 || $to <= $from || $to - $from > 70 * 86400) json_out(['error' => 'Neplatný interval.'], 422);

    $tq = $pdo->prepare("SELECT a.platform, a.name, t.status FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.post_id = ?");
    $targets = function (int $pid) use ($tq): array { $tq->execute([$pid]); return $tq->fetchAll(); };

    $items = [];
    $q = $pdo->prepare("SELECT * FROM posts WHERE status != 'draft' AND scheduled_at >= ? AND scheduled_at < ? ORDER BY scheduled_at");
    $q->execute([$from, $to]);
    foreach ($q->fetchAll() as $p) {
        $items[] = [
            'id' => (int)$p['id'], 'ts' => (int)$p['scheduled_at'], 'status' => $p['status'], 'projected' => false,
            'message' => $p['message'], 'media_url' => $p['media_file'] ? 'uploads/' . rawurlencode($p['media_file']) : null,
            'media_type' => $p['media_type'], 'media' => media_list($pdo, $p), 'repeat_mode' => $p['repeat_mode'], 'repeat_until' => $p['repeat_until'],
            'link' => $p['link'], 'tags' => $p['tags'], 'first_comment' => $p['first_comment'], 'kind' => $p['kind'],
            'link' => $p['link'], 'tags' => $p['tags'], 'first_comment' => $p['first_comment'], 'kind' => $p['kind'],
            'targets' => $targets((int)$p['id']),
        ];
    }
    // budoucí výskyty opakování, které ještě nejsou v databázi (vznikají až po odeslání předchozího)
    $heads = $pdo->query("SELECT * FROM posts WHERE repeat_mode != 'none' AND status = 'scheduled' AND next_created = 0")->fetchAll();
    foreach ($heads as $p) {
        $tg = $targets((int)$p['id']);
        foreach (project_occurrences($p, $from, $to) as $ts) {
            $items[] = [
                'id' => (int)$p['id'], 'ts' => $ts, 'status' => 'projected', 'projected' => true,
                'message' => $p['message'], 'media_url' => $p['media_file'] ? 'uploads/' . rawurlencode($p['media_file']) : null,
                'media_type' => $p['media_type'], 'media' => media_list($pdo, $p), 'repeat_mode' => $p['repeat_mode'], 'repeat_until' => $p['repeat_until'],
            'link' => $p['link'], 'tags' => $p['tags'], 'first_comment' => $p['first_comment'], 'kind' => $p['kind'],
            'link' => $p['link'], 'tags' => $p['tags'], 'first_comment' => $p['first_comment'], 'kind' => $p['kind'],
                'targets' => $tg,
            ];
        }
    }
    usort($items, fn($a, $b) => $a['ts'] <=> $b['ts']);
    json_out(['items' => $items]);

case 'retry':
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE posts SET status='scheduled', scheduled_at=? WHERE id=? AND status IN ('failed','partial')")->execute([time(), $id]);
    run_due();
    json_out(['ok' => true]);

case 'set_enabled':
    $en = !empty($_POST['enabled']) ? 1 : 0;
    if (!empty($_POST['all'])) {
        $plat = (string)($_POST['platform'] ?? '');
        if (in_array($plat, ['facebook', 'instagram'], true)) {
            $pdo->prepare("UPDATE accounts SET enabled = ? WHERE platform = ?")->execute([$en, $plat]);
        } else {
            $pdo->prepare("UPDATE accounts SET enabled = ?")->execute([$en]);
        }
    } elseif (!empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_values(array_unique(array_map('intval', $_POST['ids'])));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE accounts SET enabled = ? WHERE id IN ($in)")->execute(array_merge([$en], $ids));
    } else {
        $pdo->prepare("UPDATE accounts SET enabled = ? WHERE id = ?")->execute([$en, (int)($_POST['id'] ?? 0)]);
    }
    json_out(['ok' => true]);

case 'disconnect':
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM accounts WHERE id = ?")->execute([$id]);
    json_out(['ok' => true]);

default:
    json_out(['error' => 'Neznámá akce'], 400);
}
