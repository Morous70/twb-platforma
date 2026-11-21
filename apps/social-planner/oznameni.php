<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Oznámení', 'oznameni.php');
?>
        <h1 class="h3 mb-3">Oznámení</h1>
        <div class="row">
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">E-mailová upozornění</h5></div>
              <div class="card-body">
                <form id="form" novalidate>
                  <div class="mb-3">
                    <label class="form-label" for="email">E-mail pro oznámení</label>
                    <input class="form-control" type="email" id="email" placeholder="vase@adresa.cz" autocomplete="off">
                    <div class="form-text">Nechte prázdné pro vypnutí všech oznámení.</div>
                  </div>
                  <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="onFail"><label class="form-check-label" for="onFail">Příspěvek se nepodařilo odeslat (selhal nebo jen částečně)</label>
                  </div>
                  <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="onToken"><label class="form-check-label" for="onToken">Účet je třeba znovu propojit (neplatný token)</label>
                  </div>
                  <button type="submit" class="btn btn-primary">Uložit</button>
                  <button type="button" class="btn btn-outline-secondary" id="btnTest">Poslat zkušební e-mail</button>
                </form>
              </div>
              <div class="card-footer text-muted small">E-maily se odesílají funkcí mail() hostingu. Odesílatel je <code>planovac@vase-domena</code>; jiný lze nastavit v <code>app/config.php</code> klíčem <code>'mail_from'</code>. Stav tokenů se kontroluje jednou denně při běhu cronu.</div>
            </div>
          </div>
        </div>
<?php layout_foot(['oznameni.js']);
