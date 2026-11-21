// Editor HTML dokumentů v galerii (od 1.16.0).
// Vizuálně = SunEditor (upravuje jen obsah <body>, hlavička se styly a skripty zůstává beze změny), Kód = celý zdroj.
// Plocha dokumentu je 1920×1080 jako obrazovka; ve vizuálním režimu je zmenšená na šířku okna.
const HW = 1920, HH = 1080;
let sunLoad = null;
function loadSunEditor() {
  sunLoad ??= new Promise((res, rej) => {
    const l = document.createElement('link');
    l.rel = 'stylesheet'; l.href = 'assets/vendor/suneditor.min.css';
    document.head.append(l);
    const sc = document.createElement('script');
    sc.src = 'assets/vendor/suneditor.min.js';
    sc.onload = () => res(window.SUNEDITOR);
    sc.onerror = () => { sunLoad = null; rej(new Error('Editor se nepodařilo načíst. Zkontrolujte připojení a zkuste to znovu.')); };
    document.head.append(sc);
  });
  return sunLoad;
}

// Styly prvků, které vkládá vizuální editor (obrázky, tabulky). Stejné v editoru i v dokumentu pro přehrávač.
const HTML_EDITOR_CSS = `.se-component { display: flex; margin: 0 0 0.4em; }
.se-image-container figure, .se-component figure { margin: 0; display: block; max-width: 100%; }
.se-image-container img { max-width: 100%; height: auto; }
.__se__float-left { float: left; margin-right: 0.5em; } .__se__float-right { float: right; margin-left: 0.5em; }
.__se__float-center, .__se__float-center figure { margin-left: auto; margin-right: auto; justify-content: center; }
table[class^="se-table"] { border-collapse: collapse; width: 100%; }
table[class^="se-table"] td, table[class^="se-table"] th { border: 1px solid currentColor; padding: 0.25em 0.5em; vertical-align: top; }`;

/** Rozdělí zdroj na část do otevírací značky <body>, obsah, a zbytek. Skripty z obsahu jsou zvlášť (editor by je zahodil). */
function htmlParts(src) {
  const open = /<body\b[^>]*>/i.exec(src);
  if (!open) return { pre: '', body: src, post: '', scripts: [], bare: true };
  const start = open.index + open[0].length;
  const end = src.toLowerCase().lastIndexOf('</body');
  const pre = src.slice(0, start);
  const body = end >= start ? src.slice(start, end) : src.slice(start);
  const post = end >= start ? src.slice(end) : '';
  const scripts = [];
  const clean = body.replace(/<script\b[\s\S]*?<\/script\s*>/gi, (s) => { scripts.push(s); return ''; });
  return { pre, body: clean, post, scripts, bare: false };
}
/** Složí zdroj zpět. Skripty z obsahu jdou na jeho konec; styly editoru se doplní do hlavičky, když je obsah potřebuje. */
function htmlJoin(p, body) {
  let pre = p.pre;
  if (/class="[^"]*(se-component|se-table)/.test(body) && !/data-mp="editor"/.test(pre)) {
    const tag = `<style data-mp="editor">\n${HTML_EDITOR_CSS}\n</style>\n`;
    pre = /<\/head\s*>/i.test(pre) ? pre.replace(/<\/head\s*>/i, `${tag}</head>`) : pre.replace(/<body\b/i, `${tag}<body`);
  }
  const tail = p.scripts.length ? p.scripts.join('\n') + '\n' : '';
  if (p.bare) return body.trim() + '\n' + tail;
  return `${pre}\n${body.trim()}\n${tail}${p.post}`;
}
const hasScript = (src) => /<script\b/i.test(src);

/** Obrázek z galerie jako data: URL (zmenšený na plochu 1920×1080, aby se dokument vešel do 5 MB). */
async function imgToDataUrl(url) {
  const blob = await (await fetch(url, { credentials: 'same-origin' })).blob();
  const bmp = await createImageBitmap(blob);
  const k = Math.min(1, HW / bmp.width, HH / bmp.height);
  const c = document.createElement('canvas');
  c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
  c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
  let out = c.toDataURL(blob.type === 'image/png' ? 'image/png' : 'image/jpeg', 0.86);
  if (out.length > 1.5e6 && blob.type === 'image/png') out = c.toDataURL('image/jpeg', 0.86);
  return out;
}
/** Text souboru: UTF-8, jinak Windows-1250 (starší soubory z Windows). */
async function readTextFile(file) {
  const buf = await file.arrayBuffer();
  try { return new TextDecoder('utf-8', { fatal: true }).decode(buf); } catch { return new TextDecoder('windows-1250').decode(buf); }
}
function insertAtCaret(ta, text) {
  const s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? s;
  ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + text.length;
  ta.focus();
}

async function editHtml(m) {
  clearInterval(timer);
  let src;
  try {
    [src] = await Promise.all([m ? api('html_get', undefined, `&id=${m.id}`).then((r) => r.source) : api('html_new').then((r) => r.source), loadFontList()]);
  } catch (e) { toast(e.message, 'error'); return; }
  try { DATA_ROWS = await api('data_list'); } catch { DATA_ROWS = []; }
  try { SOURCES = await api('source_tree'); } catch { SOURCES = []; }
  let name = m ? m.original_name.replace(/\.html?$/i, '') : '';
  let tags = [...(m ? m.tags || [] : currentTags())];
  let mode = m && hasScript(src) ? 'code' : 'visual';
  let dirty = false, ed = null, baseBody = null, parts = htmlParts(src);

  main.innerHTML = `
    <div class="head slide-head">
      <div class="row grow" style="gap:12px">
        <button class="btn" id="heBack">← Zpět</button>
        <input type="text" id="heName" value="${esc(name)}" placeholder="Název dokumentu" style="max-width:280px">
        <input type="text" id="heGrp" value="${esc(m?.grp || '')}" list="groupList" placeholder="Skupina" maxlength="64" style="max-width:130px"
          title="Skupina: média ve stejné skupině se nahrazují (např. ER)">${groupList()}
        <button class="btn" id="heTags" title="Tagy pro třídění a hledání">🏷 Tagy <span class="cnt" id="heTagCnt">${tags.length || ''}</span></button>
      </div>
      <div class="row save-bar">
        ${helpBtn('jak-pridat-html-dokument', true)}
        <div class="seg" role="tablist" aria-label="Režim úprav">
          <button class="${mode === 'visual' ? 'on' : ''}" id="heModeV" role="tab">Vizuálně</button><button class="${mode === 'code' ? 'on' : ''}" id="heModeC" role="tab">Kód</button>
        </div>
        <button class="btn" id="hePreview">Náhled</button>
        <button class="btn primary" id="heSave">Uložit</button>
      </div>
    </div>
    <div class="errbox" id="heErr" hidden style="margin-bottom:12px"></div>
    <div class="row he-tools" style="gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <button class="btn sm" id="heImg">🖼 Obrázek z galerie</button>
      <select id="heVar" style="max-width:260px"><option value="">+ Vložit hodnotu…</option><optgroup label="Kalendář">${VARS.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</optgroup>${dataVarOptions()}</select>
      ${SOURCES.length ? '<button class="btn sm" id="heSrc" title="Hodnota ze vzdáleného zdroje (např. Allwyn)">Ze zdroje…</button>' : ''}
      <button class="btn sm ghost" id="heVarHelp" title="Přehled proměnných a formátů">Proměnné a formáty</button>
      <span class="grow"></span>
      <button class="btn sm" id="heFileBtn">Nahrát soubor HTML</button><input type="file" id="heFile" accept=".html,.htm,text/html" hidden>
    </div>
    <div class="warnbox small" id="heWarn" hidden style="margin-bottom:10px"></div>
    <div class="he-visual" id="heVisual" ${mode === 'visual' ? '' : 'hidden'}><textarea id="heSun"></textarea></div>
    <textarea id="heCode" class="he-code" spellcheck="false" ${mode === 'code' ? '' : 'hidden'} aria-label="Zdrojový kód HTML"></textarea>
    <p class="hint small" style="margin:8px 0 0">Plocha dokumentu je 1920 × 1080 bodů jako obrazovka – co se do ní nevejde, přehrávač nezobrazí.
      Hodnoty z Dat (např. <code>{datum}</code>) se dosadí při uložení a pak se samy obnovují. JavaScript se spouští jen v <b>Náhledu</b> a v přehrávači.</p>`;

  const code = $('#heCode');
  code.value = src;
  const warn = (html) => { $('#heWarn').innerHTML = html || ''; $('#heWarn').hidden = !html; };
  const scriptNote = () => (hasScript(currentSource()) ? 'Dokument obsahuje JavaScript. Ve vizuálním režimu se skripty nespouštějí a zůstanou beze změny; prvky, které skript vytváří nebo mění, uvidíte jen v <b>Náhledu</b>. Skript upravíte v režimu <b>Kód</b>.' : '');

  /** Aktuální zdroj podle režimu. Ve vizuálním režimu bez změn zůstává zdroj přesně takový, jaký byl. */
  function currentSource() {
    if (mode === 'code') return code.value;
    const body = ed ? ed.getContents(true) : null;
    return body !== null && body !== baseBody ? htmlJoin(parts, body) : src;
  }

  // ---------- vizuální editor ----------
  const frameStyle = () => {
    const fontCss = FONT_LIST.map((f) => Object.entries(f.files).map(([w, u]) => `@font-face { font-family: '${f.name}'; font-weight: ${f.custom ? '100 900' : w}; src: url('${new URL(u, location.href).href}'); }`).join('\n')).join('\n');
    const styles = [...parts.pre.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style\s*>/gi)].map((x) => x[1]).join('\n');
    const links = [...parts.pre.matchAll(/<link\b[^>]*rel=["']?stylesheet[^>]*>/gi)].map((x) => x[0]).join('');
    return `${links}<style data-mp="frame">${fontCss}\n${styles}\n${HTML_EDITOR_CSS}\nhtml { overflow: hidden; }</style>`;
  };
  const bodyAttrs = () => {
    const doc = new DOMParser().parseFromString(`${parts.pre || '<body>'}</body>`, 'text/html');
    return { style: doc.body.getAttribute('style') || '', cls: doc.body.getAttribute('class') || '' };
  };
  let zoom = 1;
  const fitVisual = () => {
    if (!ed) return;
    const box = $('#heVisual');
    if (!box?.isConnected || box.hidden) return;
    const el = ed.core.context.element;
    zoom = Math.min(1, (box.clientWidth - 2) / HW);
    const h = Math.round(HH * zoom);
    el.wysiwygFrame.style.height = `${h}px`;
    el.wysiwygFrame.style.minHeight = `${h}px`;
    const wd = ed.core._wd;
    if (wd?.documentElement) wd.documentElement.style.zoom = String(zoom);
  };
  const applyFrame = () => {
    if (!ed || !$('#heVisual')) return;
    const wd = ed.core._wd;
    wd.head.querySelectorAll('style[data-mp="frame"], link[data-mp]').forEach((x) => x.remove());
    wd.head.insertAdjacentHTML('beforeend', frameStyle());
    const b = wd.body, a = bodyAttrs();
    b.setAttribute('style', `${a.style};width:${HW}px;min-height:${HH}px;box-sizing:border-box`);
    b.className = `sun-editor-editable ${a.cls}`.trim();
    fitVisual();
  };
  /** Nový obsah editoru; výchozí stav pro zjištění, zda uživatel ve vizuálním režimu něco změnil. */
  const setBody = (html) => { ed.setContents(html); baseBody = ed.getContents(true); };
  async function startVisual() {
    let S;
    try { S = await loadSunEditor(); } catch (e) { toast(e.message, 'error'); setMode('code'); return; }
    if (!$('#heSun')) return;
    ed = S.create($('#heSun'), {
      lang: window.SUNEDITOR_CS, iframe: true, iframeCSSFileName: new URL('assets/html-editor-frame.css', location.href).href,
      width: '100%', height: `${Math.round(HH * 0.4)}px`, resizingBar: false, showPathLabel: false, charCounter: false,
      font: [...FONT_LIST.map((f) => f.name), 'Arial', 'Georgia', 'Verdana'],
      fontSize: [24, 32, 40, 48, 56, 64, 72, 88, 110, 140, 180, 240, 320], fontSizeUnit: 'px',
      formats: ['p', 'div', 'h1', 'h2', 'h3', 'h4'],
      buttonList: [['undo', 'redo'], ['font', 'fontSize', 'formatBlock'], ['bold', 'underline', 'italic', 'strike'], ['fontColor', 'hiliteColor'],
        ['align', 'list', 'lineHeight'], ['table', 'image', 'link', 'horizontalRule'], ['removeFormat']],
      imageFileInput: false, imageUrlInput: true,
      attributesWhitelist: { all: 'style|class|id|title|lang|dir|width|height|colspan|rowspan' },
      addTagsWhitelist: 'section|article|header|footer|main|figure|figcaption|small|mark|sup|sub|svg|path|circle|rect|g|line|polyline|polygon|ellipse|defs|lineargradient|stop|text',
      linkProtocol: 'https://',
    });
    setBody(parts.body);
    ed.onChange = () => { dirty = true; };
    ed.onInput = () => { dirty = true; };
    applyFrame();
    // po načtení písem a obrázků v rámu přepočítat (rám se vytváří asynchronně)
    setTimeout(applyFrame, 300);
  }

  function setMode(next) {
    if (next === mode) return;
    if (next === 'code') {
      code.value = currentSource();
      $('#heVisual').hidden = true; code.hidden = false;
      warn('');
    } else {
      src = code.value;
      parts = htmlParts(src);
      $('#heVisual').hidden = false; code.hidden = true;
      if (ed) { setBody(parts.body); applyFrame(); } else startVisual();
      warn(scriptNote());
    }
    mode = next;
    $('#heModeV').classList.toggle('on', mode === 'visual');
    $('#heModeC').classList.toggle('on', mode === 'code');
  }
  $('#heModeV').onclick = () => setMode('visual');
  $('#heModeC').onclick = () => setMode('code');
  code.oninput = () => { dirty = true; };
  code.onkeydown = (e) => { if (e.key === 'Tab' && !e.shiftKey) { e.preventDefault(); insertAtCaret(code, '  '); dirty = true; } };

  /** Vložení na místo kurzoru v obou režimech. */
  const insert = (html, text = null) => {
    if (mode === 'code') { insertAtCaret(code, text ?? html); dirty = true; return; }
    if (!ed) return;
    ed.insertHTML(html, true);
    dirty = true;
  };
  const insertVar = (v) => insert(esc(v), v);
  const insertAny = (v) => {
    if (v.startsWith('@date:')) { const x = v.slice(6); pickDateFormat(x, '', (f) => insertVar(`{${x}${f}}`)); }
    else if (v.startsWith('@num:')) { const x = v.slice(5); pickNumFormat(x, [], (f) => insertVar(`{${x}${f}}`)); }
    else insertVar(v);
  };
  $('#heVar').onchange = (ev) => { const v = ev.target.value; ev.target.value = ''; if (v) insertAny(v); };
  $('#heSrc')?.addEventListener('click', () => pickSource('text', insertAny));
  $('#heVarHelp').onclick = () => filterHelp();
  $('#heImg').onclick = () => pickImage('Obrázek z galerie', async (id) => {
    const x = mediaById(id);
    if (!x?.url) return;
    try {
      const data = await imgToDataUrl(x.url);
      insert(`<img src="${data}" alt="${esc(x.original_name)}" style="max-width:100%">`);
      toast(`Obrázek „${x.original_name}“ vložen do dokumentu.`);
    } catch (e) { toast('Obrázek se nepodařilo vložit: ' + e.message, 'error'); }
  });

  // ---------- nahrání souboru ----------
  $('#heFileBtn').onclick = () => $('#heFile').click();
  $('#heFile').onchange = async (ev) => {
    const f = ev.target.files[0];
    ev.target.value = '';
    if (!f) return;
    if (f.size > 5 * 1024 * 1024) { toast('Soubor je větší než 5 MB.', 'error'); return; }
    if ((dirty || m) && !confirm(`Nahradit obsah dokumentu souborem „${f.name}“?`)) return;
    const text = await readTextFile(f);
    src = text; parts = htmlParts(src); code.value = src; dirty = true;
    if (!$('#heName').value.trim()) $('#heName').value = f.name.replace(/\.html?$/i, '');
    if (mode === 'visual') {
      if (hasScript(src)) setMode('code');
      else if (ed) { setBody(parts.body); applyFrame(); }
    }
    toast(`Načteno ze souboru „${f.name}“. Uložením nahradíte obsah dokumentu.`);
  };

  // ---------- náhled, uložení, odchod ----------
  $('#hePreview').onclick = (ev) => busy(ev.currentTarget, 'Připravuji…', async () => {
    try {
      const r = await api('html_preview', { source: currentSource() });
      openModal(`<div class="row" style="margin-bottom:12px"><b class="grow">Náhled – takhle dokument uvidí přehrávač</b><button class="btn sm" data-close>Zavřít</button></div>
        <div id="hePrevBox"></div>
        ${r.unknown.length ? `<p class="warnbox small" style="margin-top:10px">Neznámé proměnné (zůstanou v textu): ${r.unknown.map((u) => `<code>${esc(u)}</code>`).join(', ')}</p>` : ''}
        ${r.hosts.length ? `<p class="hint small" style="margin-top:8px">Načítá se z internetu: ${r.hosts.map(esc).join(', ')}</p>` : ''}`);
      $('#modalBox').classList.add('wide');
      htmlFrame($('#hePrevBox'), { html: r.html });
    } catch (e) { toast(e.message, 'error'); }
  });
  const ro = new ResizeObserver(() => { if (!$('#heVisual')) { ro.disconnect(); return; } fitVisual(); });
  ro.observe(main);
  const guard = (ev) => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } };
  addEventListener('beforeunload', guard);
  window.slideEditorDirty = () => (dirty ? 'Dokument má neuložené změny. Opravdu odejít?' : false);
  const leave = () => { ro.disconnect(); removeEventListener('beforeunload', guard); dirty = false; ed?.destroy?.(); ed = null; };
  $('#heName').oninput = () => { dirty = true; };
  $('#heGrp').oninput = () => { dirty = true; };
  $('#heTags').onclick = () => editTagsModal(tags, (t) => { if (JSON.stringify(t) !== JSON.stringify(tags)) dirty = true; tags = t; $('#heTagCnt').textContent = tags.length || ''; });
  $('#heBack').onclick = () => {
    if (dirty && !confirm('Dokument má neuložené změny. Opravdu odejít?')) return;
    leave(); route();
  };
  $('#heSave').onclick = (ev) => {
    $('#heErr').hidden = true;
    if (!$('#heName').value.trim()) { $('#heName').focus(); $('#heErr').textContent = 'Zadejte název dokumentu.'; $('#heErr').hidden = false; return; }
    const source = currentSource();
    busy(ev.currentTarget, 'Ukládám…', async () => {
      try {
        const r = await api('html_save', { id: m?.id || 0, name: $('#heName').value, source, grp: $('#heGrp').value.trim(), tags });
        dirty = false;
        m = r.media;
        src = source;
        if (mode === 'visual' && ed) { parts = htmlParts(src); baseBody = ed.getContents(true); }
        const i = store.media.findIndex((x) => x.id === r.media.id);
        i >= 0 ? (store.media[i] = r.media) : store.media.unshift(r.media);
        toast(`Dokument „${r.media.original_name}“ uložen.`);
        const notes = [];
        if (r.warning) notes.push(esc(r.warning));
        if (r.unknown?.length) notes.push(`Neznámé proměnné (zůstanou v textu): ${r.unknown.map((u) => `<code>${esc(u)}</code>`).join(', ')}`);
        if (mode === 'visual' && scriptNote()) notes.push(scriptNote());
        warn(notes.join('<br>'));
      } catch (e) { $('#heErr').textContent = 'Dokument se nepodařilo uložit: ' + e.message; $('#heErr').hidden = false; toast('Dokument se nepodařilo uložit.', 'error'); }
    });
  };

  if (mode === 'visual') startVisual();
  else if (m && hasScript(src)) warn('Dokument obsahuje JavaScript, proto se otevřel v režimu <b>Kód</b>. Text můžete upravit i v režimu <b>Vizuálně</b> – skripty zůstanou beze změny.');
}
