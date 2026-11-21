<?php
declare(strict_types=1);
// Jednorázové vytvoření hashe hesla (hosting bez příkazové řádky).
// Po vložení hashe do config.php stránka přestane fungovat; i tak ji po nastavení smažte.
require __DIR__ . '/app/lib/bootstrap.php';
if (cfg('admin_hash')) { http_response_code(403); exit('Heslo už je nastavené. Smažte tento soubor (setup.php).'); }
$line = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 10) { $err = 'Heslo musí mít alespoň 10 znaků.'; }
    else { $line = "'admin_hash'   => '" . password_hash($pw, PASSWORD_DEFAULT) . "',"; }
}
$v = fn(string $f): int => (int)@filemtime(__DIR__ . '/' . $f);
?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Nastavení hesla | Plánovač příspěvků</title>
<link href="assets/adminkit/css/app.css?v=<?= $v('assets/adminkit/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<main class="d-flex w-100">
  <div class="container d-flex flex-column">
    <div class="row vh-100">
      <div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 mx-auto d-table h-100">
        <div class="d-table-cell align-middle">
          <div class="text-center mt-4"><h1 class="h2">Nastavení hesla</h1></div>
          <div class="card"><div class="card-body"><div class="m-sm-3">
            <?php if (!empty($err)): ?><div class="alert alert-danger"><div class="alert-message"><?= h($err) ?></div></div><?php endif; ?>
            <?php if ($line): ?>
              <p>V <code>config.php</code> nahraďte řádek <code>'admin_hash' => '',</code> tímto:</p>
              <textarea class="form-control mb-3" rows="4" readonly onclick="this.select()"><?= h($line) ?></textarea>
              <p class="mb-0">Potom soubor <code>setup.php</code> ze serveru smažte.</p>
            <?php else: ?>
              <form method="post">
                <div class="mb-3">
                  <label class="form-label" for="password">Nové heslo (min. 10 znaků)</label>
                  <input class="form-control form-control-lg" id="password" type="password" name="password" required autofocus>
                </div>
                <div class="d-grid"><button type="submit" class="btn btn-lg btn-primary">Vytvořit hash</button></div>
              </form>
            <?php endif; ?>
          </div></div></div>
        </div>
      </div>
    </div>
  </div>
</main>
</body></html>
