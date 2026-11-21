<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Nový příspěvek', 'novy.php');
?>
        <h1 class="h3 mb-3" id="pageTitle">Nový příspěvek</h1>

        <form id="form" novalidate>
          <div class="row">
            <div class="col-lg-7">
              <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Obsah</h5></div>
                <div class="card-body">
                  <div class="d-flex gap-2 flex-wrap align-items-center mb-3 pb-3 border-bottom" id="tplBar">
                    <select class="form-select form-select-sm w-auto" id="tplSel" aria-label="Šablona"><option value="">Šablona…</option></select>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="tplUse">Použít</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="tplSave" title="Uloží text, odkaz, štítky a vybrané účty">Uložit jako šablonu</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="tplDel">Smazat šablonu</button>
                  </div>
                  <div class="mb-3">
                    <label class="form-label d-flex justify-content-between align-items-end" for="message">
                      <span>Text</span>
                      <span class="btn-group btn-group-sm" id="fmtBar" role="group" aria-label="Formátování">
                        <button type="button" class="btn btn-outline-secondary fw-bold" data-fmt="bold" title="Tučně (označte text)">B</button>
                        <button type="button" class="btn btn-outline-secondary fst-italic" data-fmt="italic" title="Kurzíva (označte text)">I</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnStyles" title="Další styly písma">Styly ▾</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnEmoji" title="Vložit emoji do textu" aria-haspopup="true">😊 Emoji</button>
                        <button type="button" class="btn btn-outline-secondary" data-fmt="bullets" title="Odrážky">• Seznam</button>
                        <button type="button" class="btn btn-outline-secondary" data-fmt="plain" title="Odstranit tučné a kurzívu z výběru">Bez formátu</button>
                      </span>
                    </label>
                    <div class="style-menu" id="styleMenu" hidden></div>
                    <div class="emoji-pop" id="emojiPop" hidden>
                      <input type="search" class="form-control form-control-sm mb-2" id="emojiQ" placeholder="Hledat emoji (např. srdce, káva, oslava)…" autocomplete="off" aria-label="Hledat emoji">
                      <div class="emoji-tabs" id="emojiTabs"></div>
                      <div class="emoji-grid" id="emojiGrid"></div>
                      <div class="style-note">Vloží se na místo kurzoru v textu nebo v prvním komentáři (podle toho, kam jste klikli naposledy).</div>
                    </div>
                    <textarea class="form-control" id="message" rows="8" placeholder="Text příspěvku…"></textarea>
                    <div class="mt-2 small" id="varBar"></div>
                    <div class="d-flex justify-content-between form-text">
                      <span>Tučné a kurzíva jsou zvláštní znaky Unicode (sítě skutečné formátování nemají) – fungují na FB i IG, ale nečtou je čtečky obrazovky.</span>
                      <span class="text-nowrap ms-2"><span id="count">0</span> / 2200</span>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label" for="link">Odkaz (nepovinné)</label>
                    <input class="form-control" type="url" id="link" placeholder="https://…">
                    <div class="form-text">Na Facebooku se u příspěvku bez obrázku zobrazí náhled odkazu. Na Instagramu odkaz v popisku není klikací.</div>
                    <div id="linkMeta" class="mt-2"></div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label" for="tagInput">Štítky (hashtagy)</label>
                    <div class="form-control tag-box" id="tagBox">
                      <span id="tagChips"></span>
                      <input type="text" id="tagInput" class="tag-input" placeholder="Napište štítek a stiskněte Enter" autocomplete="off">
                    </div>
                    <div class="input-group input-group-sm mt-2">
                      <span class="input-group-text">Sada</span>
                      <select class="form-select" id="hsSel" aria-label="Sada hashtagů"><option value="">Vyberte uloženou sadu…</option></select>
                      <button type="button" class="btn btn-outline-secondary" id="hsUse" title="Přidat štítky ze sady">Přidat</button>
                      <button type="button" class="btn btn-outline-secondary" id="hsSave" title="Uložit aktuální štítky jako sadu">Uložit jako sadu</button>
                      <button type="button" class="btn btn-outline-danger" id="hsDel" title="Smazat vybranou sadu">Smazat</button>
                    </div>
                    <div class="form-text">Přidají se na konec příspěvku jako #štítek. Instagram povoluje nejvýše 30. <span id="tagCount"></span></div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label d-block">Typ publikace</label>
                    <div class="btn-group" role="group" id="kindGroup">
                      <input type="radio" class="btn-check" name="kind" id="kFeed" value="feed" checked><label class="btn btn-outline-primary btn-sm" for="kFeed">Příspěvek</label>
                      <input type="radio" class="btn-check" name="kind" id="kReel" value="reel"><label class="btn btn-outline-primary btn-sm" for="kReel">Reel</label>
                      <input type="radio" class="btn-check" name="kind" id="kStory" value="story"><label class="btn btn-outline-primary btn-sm" for="kStory">Story</label>
                    </div>
                    <div class="form-text" id="kindHint"></div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label d-flex justify-content-between align-items-end" for="firstComment"><span>První komentář <span class="text-muted fw-normal">(volitelné)</span></span><button type="button" class="btn btn-outline-secondary btn-sm py-0" id="btnEmoji2" title="Vložit emoji do komentáře">😊</button></label>
                    <textarea class="form-control" id="firstComment" rows="2" maxlength="2000" placeholder="Např. hashtagy nebo odkaz – vloží se jako komentář hned po zveřejnění"></textarea>
                    <div class="form-text">Přidá se pod příspěvek na všech vybraných účtech. Funguje i s proměnnými {{…}}. Vyžaduje oprávnění ke komentářům u propojených účtů.</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">Obrázky nebo video</label>
                    <div class="dropzone" id="drop" tabindex="0" role="button">
                      <i class="align-middle me-1" data-feather="upload-cloud"></i> Přetáhněte sem soubory nebo klikněte pro výběr<br>
                      <small class="text-muted">Až 10 obrázků (galerie / carousel), nebo jedno video. JPEG, PNG, WebP, MP4, MOV.</small>
                    </div>
                    <input type="file" id="media" accept="image/*,video/mp4,video/quicktime" multiple hidden>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="libPick"><i class="align-middle me-1" data-feather="image"></i> Vybrat z knihovny</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="webPick" title="Wikimedia Commons, Openverse, Pixabay, Pexels – obrázek se uloží do knihovny i s autorem a licencí"><i class="align-middle me-1" data-feather="globe"></i> Z internetu</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="slideNew" title="Obrázek s textem a daty (jackpot, datum, svátek, počasí) – otevře editor slidů v nové kartě"><i class="align-middle me-1" data-feather="layout"></i> Vytvořit slide</button>
                    <div class="media-strip" id="mediaStrip"></div>
                    <small class="text-muted" id="mediaInfo"></small>
                    <div class="text-warning small mt-1" id="mediaWarn"></div>
                    <div class="small mt-2" id="creditHint"></div>
                  </div>

                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label" for="when">Kdy publikovat</label>
                      <input class="form-control" type="datetime-local" id="when">
                      <div class="form-text" id="bestHint"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label" for="repeat">Opakování</label>
                      <select class="form-select" id="repeat">
                        <option value="none">Neopakovat</option>
                        <option value="daily">Každý den</option>
                        <option value="weekly">Každý týden</option>
                        <option value="monthly">Každý měsíc</option>
                      </select>
                    </div>
                  </div>
                  <div class="mb-0" id="untilWrap" hidden>
                    <label class="form-label" for="until">Opakovat do (nepovinné, jinak bez konce)</label>
                    <input class="form-control" type="date" id="until">
                  </div>
                </div>
              </div>
            </div>

            <div class="col-lg-5">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Kam publikovat</h5>
                  <span class="badge bg-primary" id="selCount">Vybráno 0</span>
                </div>
                <div class="card-body pb-2">
                  <div id="grpBar" class="mb-2" hidden></div>
                  <input type="search" id="q" class="form-control mb-2" placeholder="Hledat účet…" autocomplete="off">
                  <div class="row g-2 mb-2">
                    <div class="col-6">
                      <select id="fNet" class="form-select form-select-sm" aria-label="Síť">
                        <option value="">Všechny sítě</option>
                        <option value="facebook">Facebook</option>
                        <option value="instagram">Instagram</option>
                      </select>
                    </div>
                    <div class="col-6 d-flex gap-1">
                      <button type="button" class="btn btn-outline-primary btn-sm flex-fill" id="selAll">Vybrat zobrazené</button>
                      <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="selNone">Zrušit</button>
                    </div>
                  </div>
                </div>
                <div class="list-group list-group-flush acc-list" id="accList"><div class="p-3 text-muted">Načítám…</div></div>
                <div class="card-footer text-muted small">Zobrazují se jen účty povolené na stránce <a href="ucty.php">Účty a propojení</a>.</div>
              </div>

              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h5 class="card-title mb-0">Náhled</h5>
                  <div class="btn-group btn-group-sm" role="group" aria-label="Síť náhledu">
                    <button type="button" class="btn btn-outline-primary active" data-pv="facebook">Facebook</button>
                    <button type="button" class="btn btn-outline-primary" data-pv="instagram">Instagram</button>
                    <button type="button" class="btn btn-outline-primary" data-pv="threads">Threads</button>
                  </div>
                </div>
                <div class="card-body bg-light" id="pv"></div>
                <div class="card-footer text-muted small">Přibližný náhled, skutečný vzhled určují sítě.</div>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 mb-4 flex-wrap">
            <button type="submit" class="btn btn-primary btn-lg" id="btnSchedule">Naplánovat</button>
            <button type="button" class="btn btn-outline-warning btn-lg" id="btnDraft" title="Uloží rozpracovaný příspěvek bez odeslání">Uložit koncept</button>
            <button type="button" class="btn btn-outline-secondary btn-lg" id="btnNow">Publikovat hned</button>
            <a href="seznam.php" class="btn btn-link btn-lg" id="btnCancel" hidden>Zrušit</a>
          </div>
        </form>
<?php layout_foot(['tags.js', 'varpick.js', 'stock.js', 'novy.js']);
