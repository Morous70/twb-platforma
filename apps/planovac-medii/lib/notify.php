<?php
// Upozornění e-mailem (PHP mail) a SMS přes BulkGate (Simple API – transactional).

function notify_send(string $subject, string $text): array {
    $sent = [];
    $email = trim((string)setting('notify_email', ''));
    if ($email !== '') {
        $headers = [
            'From: ' . mb_encode_mimeheader(APP_NAME, 'UTF-8') . ' <' . cfg('mail_from') . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $ok = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", $headers));
        $sent[] = $ok ? "e-mail: odesláno na $email" : "e-mail: odeslání na $email selhalo";
    }
    $phone = preg_replace('/[^\d]/', '', (string)setting('notify_phone', ''));
    $appId = trim((string)setting('bulkgate_app_id', ''));
    $token = trim((string)setting('bulkgate_token', ''));
    if ($phone !== '' && $appId !== '' && $token !== '') {
        if (strlen($phone) === 9) $phone = '420' . $phone; // české číslo bez předvolby
        $sms = mb_substr($subject . ': ' . $text, 0, 300);
        try {
            $r = http_req('POST', 'https://portal.bulkgate.com/api/1.0/simple/transactional', ['json' => [
                'application_id' => (int)$appId, 'application_token' => $token,
                'number' => $phone, 'text' => $sms, 'unicode' => true,
            ], 'timeout' => 20]);
            $ok = $r['code'] === 200 && isset($r['json']['data']);
            $sent[] = $ok ? "SMS: odesláno na +$phone" : 'SMS: chyba BulkGate – ' . ($r['json']['error'] ?? $r['body']);
        } catch (Throwable $e) {
            $sent[] = 'SMS: ' . $e->getMessage();
        }
    }
    return $sent;
}

/** Selhání synchronizace: upozorní poprvé a pak nejvýš jednou za 6 hodin. */
function notify_failure(string $error): void {
    $now = time();
    if (!setting('fail_since')) set_setting('fail_since', (string)$now);
    $last = (int)setting('fail_notified_at', 0);
    if ($last && $now - $last < 6 * 3600) return;
    set_setting('fail_notified_at', (string)$now);
    $since = date('j. n. Y H:i', (int)setting('fail_since'));
    $res = notify_send(APP_NAME . ' – chyba',
        "Synchronizace obrazovky selhává od $since.\n$error\n\n" . rtrim(cfg('base_url'), '/') . '/');
    if ($res) app_log('info', 'Upozornění na chybu: ' . implode('; ', $res));
}

function notify_recovery(): void {
    if (!setting('fail_since')) return;
    $wasNotified = (bool)setting('fail_notified_at');
    set_setting('fail_since', null);
    set_setting('fail_notified_at', null);
    if ($wasNotified) {
        $res = notify_send(APP_NAME . ' – opraveno', 'Synchronizace obrazovky opět funguje.');
        if ($res) app_log('info', 'Upozornění na obnovení: ' . implode('; ', $res));
    }
}
