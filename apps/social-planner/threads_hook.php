<?php
declare(strict_types=1);
// Adresa pro „Uninstall callback“ a „Delete callback“ v nastavení Threads v Metě (Meta ji vyžaduje vyplnit).
// Data se tu nemažou – účet odpojíte v aplikaci. Odpověď odpovídá formátu, který Meta očekává.
require __DIR__ . '/app/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['url' => rtrim((string)cfg('base_url'), '/') . '/threads_hook.php', 'confirmation_code' => 'ok']);
