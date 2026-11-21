<?php
declare(strict_types=1);
// Editor slidu (od 1.6.0, převzato z TWB Plánovače médií). slide.php = nový slide, slide.php?id=N = úprava,
// ?for=novy = otevřeno z Nového příspěvku (po uložení se slide vloží do příspěvku), ?format=story = formát nového slidu.
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Slide', 'knihovna.php');
?>
        <link rel="stylesheet" href="assets/css/slide-editor.css?v=<?= h(APP_VERSION) ?>">
        <div class="pm">
          <div id="main"><p class="hint">Načítám editor…</p></div>
          <div class="modal" id="modal" hidden><div class="modal-box" id="modalBox"></div></div>
          <div class="toasts" id="toasts"></div>
        </div>
<?php layout_foot(['tags.js', 'slide-shim.js', 'slide-editor.js', 'slide-init.js']);
