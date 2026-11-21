<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Zabezpečení', 'bezpecnost.php');
?>
        <h1 class="h3 mb-3">Zabezpečení</h1>
        <div class="row">
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Heslo</h5></div>
              <div class="card-body">
                <div class="alert alert-warning d-none" id="noMail">Není nastavený e-mail pro oznámení, takže nelze obnovit zapomenuté heslo. Nastavte ho v <a href="oznameni.php">Oznámeních</a>.</div>
                <form id="pwForm" novalidate>
                  <div class="mb-2"><label class="form-label" for="pwCur">Současné heslo</label><input class="form-control" type="password" id="pwCur" autocomplete="current-password"></div>
                  <div class="mb-2"><label class="form-label" for="pwNew">Nové heslo (min. 8 znaků)</label><input class="form-control" type="password" id="pwNew" autocomplete="new-password"></div>
                  <div class="mb-3"><label class="form-label" for="pwNew2">Nové heslo znovu</label><input class="form-control" type="password" id="pwNew2" autocomplete="new-password"></div>
                  <button class="btn btn-primary" type="submit">Změnit heslo</button>
                </form>
              </div>
            </div>
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Automatické odhlášení</h5></div>
              <div class="card-body">
                <form id="idleForm" class="d-flex align-items-end gap-2" novalidate>
                  <div><label class="form-label" for="idle">Po nečinnosti (minuty, 0 = nikdy)</label><input class="form-control" type="number" id="idle" min="0" max="1440"></div>
                  <button class="btn btn-primary" type="submit">Uložit</button>
                </form>
                <div class="form-text mt-2">Nečinnost se počítá od poslední akce v aplikaci. 5 až 1 440 minut.</div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Passkey (přihlášení bez hesla)</h5></div>
              <div class="card-body">
                <p class="text-muted small mb-2">Přihlášení otiskem, obličejem nebo PIN zařízení. Heslo zůstává vždy jako záloha. Funguje jen na HTTPS; po změně domény je potřeba Passkey zaregistrovat znovu. <span id="rp"></span></p>
                <div class="list-group mb-3" id="pkList"></div>
                <div class="d-flex gap-2">
                  <input class="form-control" id="pkName" placeholder="Název zařízení (např. Telefon)" maxlength="100">
                  <button class="btn btn-primary text-nowrap" id="pkAdd">Přidat Passkey</button>
                </div>
                <div class="form-text d-none" id="pkNo">Tento prohlížeč nebo adresa Passkey nepodporuje (vyžaduje HTTPS).</div>
              </div>
            </div>
          </div>
        </div>
<?php layout_foot(['webauthn.js', 'bezpecnost.js']);
