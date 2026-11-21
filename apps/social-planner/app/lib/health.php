<?php
declare(strict_types=1);
require_once __DIR__ . '/notify.php';

/** Označí stav účtu; při přechodu do chyby pošle upozornění. */
function set_account_health(PDO $pdo, array $acc, bool $ok, string $msg = ''): void {
    $was = $acc['health'] ?? null;
    $pdo->prepare("UPDATE accounts SET health = ?, health_msg = ?, health_at = ? WHERE id = ?")
        ->execute([$ok ? 'ok' : 'error', $ok ? null : mb_substr($msg, 0, 300), time(), $acc['id']]);
    if (!$ok && $was !== 'error' && notify_settings()['on_token']) {
        send_mail('Plánovač: účet vyžaduje znovu propojit',
            "Účet {$acc['platform']}:{$acc['name']} nelze použít.\n\nChyba: $msg\n\nPropojte účty znovu:\n" . rtrim((string)cfg('base_url'), '/') . "/connect.php\n");
    }
}

/** Zkontroluje všechny povolené účty (dotaz na Graph API s tokenem účtu). */
function check_accounts(PDO $pdo): array {
    $res = ['checked' => 0, 'bad' => 0];
    try { threads_refresh_tokens($pdo); } catch (Throwable $e) { /* nevadí, ověření účtu to ukáže */ }
    foreach ($pdo->query("SELECT * FROM accounts WHERE enabled = 1")->fetchAll() as $acc) {
        $res['checked']++;
        try {
            if ($acc['platform'] === 'threads') threads_api('GET', $acc['remote_id'], ['access_token' => $acc['token'], 'fields' => 'id,username']);
            else graph('GET', $acc['remote_id'], ['access_token' => $acc['token'], 'fields' => 'id,name']);
            set_account_health($pdo, $acc, true);
        } catch (Throwable $e) {
            // síťový výpadek nepovažujeme za problém účtu
            if (str_starts_with($e->getMessage(), 'Síťová chyba')) continue;
            $res['bad']++;
            set_account_health($pdo, $acc, false, $e->getMessage());
        }
    }
    meta_set('last_health', (string)time());
    try { refresh_avatars($pdo); } catch (Throwable $e) { /* nevadí */ }
    return $res;
}

function check_accounts_if_due(PDO $pdo): void {
    if (time() - (int)meta_get('last_health', '0') >= 86400) check_accounts($pdo);
}
