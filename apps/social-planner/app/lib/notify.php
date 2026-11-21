<?php
declare(strict_types=1);
// Oznámení e-mailem + jednoduché úložiště nastavení (tabulka meta).

function meta_get(string $key, ?string $default = null): ?string {
    $q = db()->prepare("SELECT value FROM meta WHERE key = ?");
    $q->execute([$key]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function meta_set(string $key, string $value): void {
    db()->prepare("INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$key, $value]);
}

function notify_settings(): array {
    return [
        'email'    => (string)meta_get('notify_email', ''),
        'on_fail'  => meta_get('notify_on_fail', '1') === '1',
        'on_token' => meta_get('notify_on_token', '1') === '1',
    ];
}

function send_mail(string $subject, string $body): bool {
    $to = notify_settings()['email'];
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (cfg('dry_run')) { log_line("DRY-RUN e-mail pro $to: $subject"); return true; }
    $host = parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost';
    $from = (string)cfg('mail_from', 'planovac@' . preg_replace('/^www\./', '', $host));
    $headers = "From: Plánovač příspěvků <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B'), $body, $headers);
    log_line(($ok ? 'E-MAIL odeslán' : 'E-MAIL SELHAL') . " ($to): $subject");
    return $ok;
}

/** Selhání publikace: $lines = ['facebook:Název – chyba', …]. */
function notify_failure(array $post, array $lines): void {
    if (!notify_settings()['on_fail'] || !$lines) return;
    $url = rtrim((string)cfg('base_url'), '/') . '/seznam.php?status=attention';
    $txt = trim((string)$post['message']);
    $txt = mb_strlen($txt) > 120 ? mb_substr($txt, 0, 120) . '…' : $txt;
    send_mail('Plánovač: příspěvek se nepodařilo odeslat',
        "Příspěvek „" . ($txt !== '' ? $txt : '(bez textu)') . "“ se nepodařilo odeslat na tyto účty:\n\n- " .
        implode("\n- ", $lines) . "\n\nZkontrolujte ho a použijte „Znovu“:\n$url\n");
}
