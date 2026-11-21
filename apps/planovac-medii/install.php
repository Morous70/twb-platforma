<?php
// Instalace: vytvoří tabulky a nastaví heslo. Po instalaci se stránka zablokuje.
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/schema.php';

$error = null;
try {
    create_schema();
} catch (Throwable $e) {
    $error = 'Nelze se připojit k databázi nebo vytvořit tabulky: ' . $e->getMessage();
}

if (!$error && installed()) {
    exit('<p style="font-family:sans-serif">Aplikace je už nainstalovaná. <a href="./">Přejít do aplikace</a></p>');
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['p1'] ?? '';
    $p2 = $_POST['p2'] ?? '';
    if (mb_strlen($p1) < 8) $error = 'Heslo musí mít aspoň 8 znaků.';
    elseif ($p1 !== $p2) $error = 'Hesla se neshodují.';
    else {
        set_setting('password_hash', password_hash($p1, PASSWORD_DEFAULT));
        set_setting('delete_mode', 'trash');
        app_log('info', 'Aplikace nainstalována.');
        header('Location: ./');
        exit;
    }
}
?><!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php page_head('Instalace – ' . APP_NAME); ?>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="center-page">
<form method="post" class="panel login">
  <h1>Instalace</h1>
  <p class="muted">Databáze je připravena. Zvolte heslo pro přístup do aplikace.</p>
  <?php if ($error): ?><div class="errbox"><?= h($error) ?></div><?php endif; ?>
  <label class="field">Heslo<input type="password" name="p1" required minlength="8" autofocus></label>
  <label class="field">Heslo znovu<input type="password" name="p2" required></label>
  <button class="btn primary">Dokončit instalaci</button>
</form>
</body></html>
