<?php
declare(strict_types=1);
// Společné rozvržení stránek (AdminKit): postranní menu, horní lišta, modální okno.

function asset_v(string $file): int { return (int)@filemtime(PUBLIC_DIR . '/' . $file); }

function layout_head(string $title, string $active): void {
    // Nabídka: skupina => [soubor, název, ikona (Feather)]. Skupina s prázdným názvem nemá nadpis.
    $nav = [
        'Plánování' => [
            ['prehled.php',   'Přehled',              'home'],
            ['kalendar.php',  'Kalendář',             'calendar'],
            ['novy.php',      'Nový příspěvek',       'edit-3'],
            ['seznam.php',    'Příspěvky',            'list'],
            ['hromadne.php',  'Hromadné plánování',   'layers'],
        ],
        'Obsah' => [
            ['knihovna.php',  'Knihovna médií',       'image'],
            ['promenne.php',  'Proměnné a data',      'code'],
        ],
        'Výsledky' => [
            ['statistiky.php','Statistiky',           'bar-chart-2'],
        ],
        'Účty' => [
            ['ucty.php',      'Účty a propojení',     'user-check'],
            ['skupiny.php',   'Skupiny účtů',         'users'],
        ],
        'Nastavení' => [
            ['oznameni.php',  'Oznámení',             'bell'],
            ['bezpecnost.php','Zabezpečení',          'shield'],
            ['integrace.php', 'Integrace (API)',      'share-2'],
            ['zaloha.php',    'Zálohy',               'download'],
            ['titulni.php',   'Titulní stránka',      'monitor'],
        ],
        '' => [
            ['napoveda.php',  'Nápověda',             'help-circle'],
        ],
    ];
    ?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex,nofollow">
  <title><?= h($title) ?> | Plánovač příspěvků</title>
  <link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
  <meta name="generator" content="<?= h(APP_NAME . ' ' . APP_VERSION) ?>">
  <link href="assets/adminkit/css/app.css?v=<?= asset_v('assets/adminkit/css/app.css') ?>" rel="stylesheet">
  <link href="assets/css/app.css?v=<?= asset_v('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<div class="wrapper">
  <nav id="sidebar" class="sidebar js-sidebar">
    <div class="sidebar-content js-simplebar">
      <a class="sidebar-brand" href="prehled.php"><span class="align-middle">Plánovač příspěvků</span></a>
      <ul class="sidebar-nav">
<?php foreach ($nav as $group => $items): ?>
<?php if ($group !== ''): ?>
        <li class="sidebar-header"><?= h($group) ?></li>
<?php else: ?>
        <li class="sidebar-divider"></li>
<?php endif; ?>
<?php foreach ($items as [$href, $label, $icon]): ?>
        <li class="sidebar-item<?= $href === $active ? ' active' : '' ?>">
          <a class="sidebar-link" href="<?= h($href) ?>"><i class="align-middle" data-feather="<?= h($icon) ?>"></i> <span class="align-middle"><?= h($label) ?></span></a>
        </li>
<?php endforeach; endforeach; ?>
      </ul>
      <div class="sidebar-version"><?= h(APP_NAME) ?> <?= h(APP_VERSION) ?></div>
    </div>
  </nav>

  <div class="main">
    <nav class="navbar navbar-expand navbar-light navbar-bg">
      <a class="sidebar-toggle js-sidebar-toggle"><i class="hamburger align-self-center"></i></a>
      <div class="navbar-collapse collapse">
        <ul class="navbar-nav navbar-align">
          <li class="nav-item">
            <a class="nav-link" href="logout.php"><i class="align-middle me-1" data-feather="log-out"></i> Odhlásit</a>
          </li>
        </ul>
      </div>
    </nav>

    <main class="content">
      <div class="container-fluid p-0">
        <div id="flash"></div>
<?php
}

function layout_foot(array $scripts = []): void {
    $csrf = csrf_token();
    ?>
      </div>
    </main>

    <footer class="footer">
      <div class="container-fluid">
        <div class="row text-muted">
          <div class="col-6"><small><a class="text-muted" href="napoveda.php#verze"><?= h(APP_NAME) ?> verze <?= h(APP_VERSION) ?></a></small></div>
          <div class="col-6 text-end"><small>UI: <a class="text-muted" href="https://adminkit.io/" target="_blank" rel="noopener">AdminKit</a> (MIT)</small></div>
        </div>
      </div>
    </footer>
  </div>
</div>

<div class="modal fade" id="appModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"></h5>
        <button type="button" class="btn-close" data-close aria-label="Zavřít"></button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-close>Zavřít</button></div>
    </div>
  </div>
</div>

<script>window.CSRF = <?= json_encode($csrf) ?>;</script>
<script src="assets/adminkit/js/app.js?v=<?= asset_v('assets/adminkit/js/app.js') ?>"></script>
<script src="assets/js/common.js?v=<?= asset_v('assets/js/common.js') ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="assets/js/<?= h($s) ?>?v=<?= asset_v('assets/js/' . $s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
