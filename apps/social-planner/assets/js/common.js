'use strict';
// Sdílené pomocné funkce pro všechny stránky (window.App)
(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // vyhledávání bez ohledu na diakritiku a velikost písmen
  const norm = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;


  // text příspěvku = zpráva + odkaz + hashtagy (stejně jako na serveru)
  const hashtags = (tags) => (Array.isArray(tags) ? tags : String(tags || '').split(/\s+/).filter(Boolean)).map(t => '#' + t).join(' ');
  const compose = (message, link, tags, withLink = true) =>
    [String(message || '').trim(), withLink ? (link || '') : '', hashtags(tags)].filter(Boolean).join('\n\n');
  const cpLen = (s) => [...String(s)].length;      // délka ve znacích (ne UTF-16 jednotkách)
  const cleanTag = (t) => String(t).replace(/^#+/, '').replace(/[^\p{L}\p{N}_]/gu, '');

  // Styly písma pro Facebook/Instagram = speciální znaky Unicode (sítě skutečné formátování nemají; podobně jako yaytext.com)
  const range = (a, base, n = 26) => Object.fromEntries([...Array(n)].map((_, i) => [String.fromCharCode(a + i), String.fromCodePoint(base + i)]));
  const mk = (A, a, d, ex = {}) => ({ ...range(65, A), ...range(97, a), ...(d ? range(48, d, 10) : {}), ...ex });
  const SMALLCAPS = 'ᴀʙᴄᴅᴇꜰɢʜɪᴊᴋʟᴍɴᴏᴘǫʀꜱᴛᴜᴠᴡxʏᴢ';
  const FLIP = { a:'ɐ',b:'q',c:'ɔ',d:'p',e:'ǝ',f:'ɟ',g:'ƃ',h:'ɥ',i:'ᴉ',j:'ɾ',k:'ʞ',l:'l',m:'ɯ',n:'u',o:'o',p:'d',q:'b',r:'ɹ',s:'s',t:'ʇ',u:'n',v:'ʌ',w:'ʍ',x:'x',y:'ʎ',z:'z',
    A:'∀',B:'ꓭ',C:'Ɔ',D:'ꓷ',E:'Ǝ',F:'Ⅎ',G:'⅁',H:'H',I:'I',J:'ſ',K:'ꓘ',L:'⅂',M:'W',N:'N',O:'O',P:'Ԁ',Q:'Ό',R:'ꓤ',S:'S',T:'⊥',U:'∩',V:'Λ',W:'M',X:'X',Y:'⅄',Z:'Z',
    '1':'⇂','2':'ᄅ','3':'Ɛ','4':'ᔭ','5':'ϛ','6':'9','7':'ㄥ','8':'8','9':'6','0':'0','.':'˙',',':"'",'?':'¿','!':'¡','(':')',')':'(' };
  const STYLES = {
    bold:       { label: 'Tučné',                 map: mk(0x1D5D4, 0x1D5EE, 0x1D7EC) },
    italic:     { label: 'Kurzíva',               map: mk(0x1D608, 0x1D622, 0) },
    bolditalic: { label: 'Tučná kurzíva',         map: mk(0x1D63C, 0x1D656, 0x1D7EC) },
    serifbold:  { label: 'Patkové tučné',         map: mk(0x1D400, 0x1D41A, 0x1D7CE) },
    serifital:  { label: 'Patková kurzíva',       map: mk(0x1D434, 0x1D44E, 0, { h: 'ℎ' }) },
    serifbi:    { label: 'Patková tučná kurzíva', map: mk(0x1D468, 0x1D482, 0x1D7CE) },
    script:     { label: 'Psací',                 map: mk(0x1D49C, 0x1D4B6, 0, { B:'ℬ',E:'ℰ',F:'ℱ',H:'ℋ',I:'ℐ',L:'ℒ',M:'ℳ',R:'ℛ',e:'ℯ',g:'ℊ',o:'ℴ' }) },
    boldscript: { label: 'Psací tučné',           map: mk(0x1D4D0, 0x1D4EA, 0) },
    fraktur:    { label: 'Fraktur',               map: mk(0x1D504, 0x1D51E, 0, { C:'ℭ',H:'ℌ',I:'ℑ',R:'ℜ',Z:'ℨ' }) },
    boldfrak:   { label: 'Fraktur tučný',         map: mk(0x1D56C, 0x1D586, 0) },
    double:     { label: 'Dvojitý tah',           map: mk(0x1D538, 0x1D552, 0x1D7D8, { C:'ℂ',H:'ℍ',N:'ℕ',P:'ℙ',Q:'ℚ',R:'ℝ',Z:'ℤ' }) },
    mono:       { label: 'Strojopis',             map: mk(0x1D670, 0x1D68A, 0x1D7F6) },
    circled:    { label: 'V kroužku',             map: { ...mk(0x24B6, 0x24D0, 0), ...Object.fromEntries([...'123456789'].map((c, i) => [c, String.fromCodePoint(0x2460 + i)])), '0': '⓪' } },
    squared:    { label: 'Ve čtverci',            map: mk(0x1F130, 0x1F130, 0) },
    wide:       { label: 'Široké',                map: mk(0xFF21, 0xFF41, 0xFF10) },
    smallcaps:  { label: 'Malé kapitálky',        map: Object.fromEntries([...SMALLCAPS].map((c, i) => [String.fromCharCode(97 + i), c])) },
    flip:       { label: 'Vzhůru nohama',         map: FLIP, flip: true },
    strike:     { label: 'Přeškrtnuté',           mark: '̶' },
    underline:  { label: 'Podtržené',             mark: '̲' },
  };
  const STYLE_LIST = Object.entries(STYLES).map(([key, v]) => ({ key, label: v.label }));
  // zpětná mapa: stylizovaný znak -> základní znak
  const REV = new Map();
  for (const st of Object.values(STYLES)) if (st.map && !st.flip) for (const [plain, fancy] of Object.entries(st.map)) if (!REV.has(fancy)) REV.set(fancy, plain);
  const MARKS = /[̶̲]/g;
  const unstyle = (s) => {
    return [...String(s).normalize('NFD').replace(MARKS, '')].map(ch => REV.get(ch) ?? ch).join('').normalize('NFC');
  };
  const isStyled = (s, key) => {
    const st = STYLES[key];
    if (st.mark) return String(s).includes(st.mark);
    const vals = new Set(Object.values(st.map));
    return [...String(s)].some(ch => vals.has(ch));
  };
  function applyStyle(s, key) {
    const st = STYLES[key];
    if (!st) return s;
    if (isStyled(s, key)) return unstyle(s);
    const plain = unstyle(s);
    if (st.mark) return [...plain.normalize('NFD')].map(ch => /[\s]/.test(ch) || /[̀-ͯ]/.test(ch) ? ch : ch + st.mark).join('');
    const out = [...plain.normalize('NFD')].map(ch => st.map[ch] ?? ch);
    return (st.flip ? out.reverse() : out).join('');
  }

  // oříznutí: největší obdélník daného poměru (a = šířka/výška) × zoom, vystředěný na (cx, cy), uvnitř obrázku
  function cropRect(W, H, a, zoom, cx, cy) {
    let w = W / H > a ? H * a : W, h = W / H > a ? H : W / a;
    w *= zoom; h *= zoom;
    return { w, h, x: Math.min(Math.max(cx - w / 2, 0), W - w), y: Math.min(Math.max(cy - h / 2, 0), H - h) };
  }

  async function api(action, body) {
    const opt = body ? { method: 'POST', body, headers: { 'X-CSRF': window.CSRF } } : {};
    const r = await fetch('api.php?action=' + action, opt);
    const j = await r.json().catch(() => ({ error: 'Neplatná odpověď serveru' }));
    if (r.status === 401) { location.href = 'login.php'; throw new Error('Přihlášení vypršelo'); }
    if (!r.ok) throw new Error(j.error || 'Chyba ' + r.status);
    return j;
  }
  const form = (obj) => { const f = new FormData(); for (const k in obj) f.append(k, obj[k]); return f; };

  let flashTimer;
  function flash(text, type = 'success') {
    const box = $('#flash');
    if (!box) { alert(text); return; }
    box.innerHTML = `<div class="alert alert-${type}" role="alert"><div class="alert-message">${esc(text)}</div></div>`;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { box.innerHTML = ''; }, type === 'danger' ? 8000 : 3500);
  }

  const STATUS = { draft: 'Koncept', scheduled: 'Naplánováno', publishing: 'Odesílá se…', done: 'Odesláno', partial: 'Částečně', failed: 'Selhalo', projected: 'Plánované opakování' };
  const STATUS_CLASS = { draft: 'bg-secondary', scheduled: 'bg-primary', publishing: 'bg-info', done: 'bg-success', partial: 'bg-warning', failed: 'bg-danger', projected: 'bg-secondary' };
  const REPEAT = { daily: 'denně', weekly: 'týdně', monthly: 'měsíčně' };
  const statusBadge = (s) => `<span class="badge ${STATUS_CLASS[s] || 'bg-secondary'}">${esc(STATUS[s] || s)}</span>`;
  const NET = { facebook: ['badge-fb', 'FB', 'Facebook'], instagram: ['badge-ig', 'IG', 'Instagram'], threads: ['badge-th', 'TH', 'Threads'] };
  const netBadge = (p) => `<span class="badge ${(NET[p] || NET.instagram)[0]}">${(NET[p] || NET.instagram)[1]}</span>`;
  // profilovka účtu s malým štítkem sítě; bez obrázku se ukáže iniciála
  const avatar = (a) => {
    const n = NET[a.platform] || NET.instagram, net = `<span class="badge acc-net ${n[0]}">${n[1]}</span>`;
    const img = a.avatar_at
      ? `<img src="uploads/avatars/${+a.id}.jpg?v=${+a.avatar_at}" alt="">`
      : `<span class="d-flex align-items-center justify-content-center bg-light text-muted fw-bold rounded-circle" style="width:32px;height:32px">${esc(String(a.name || '?').replace(/^@/, '').charAt(0).toUpperCase())}</span>`;
    return `<span class="acc-av">${img}${net}</span>`;
  };
  const fmt = (ts) => new Date(ts * 1000).toLocaleString('cs-CZ', { dateStyle: 'medium', timeStyle: 'short' });
  const repeatInfo = (p) => !p.repeat_mode || p.repeat_mode === 'none' ? '' :
    `↻ ${REPEAT[p.repeat_mode]}${p.repeat_until ? ' do ' + new Date(p.repeat_until * 1000).toLocaleDateString('cs-CZ') : ''}`;
  const icons = () => { try { if (window.feather) window.feather.replace(); } catch (e) { /* ignoruj */ } };

  function actions(p) {
    const upcoming = p.status === 'scheduled' && p.repeat_mode && p.repeat_mode !== 'none';
    return (['draft', 'scheduled', 'failed', 'partial'].includes(p.status) ? `<a class="btn btn-sm btn-primary" href="novy.php?edit=${p.id}">Upravit</a> ` : '') +
      `<a class="btn btn-sm btn-outline-primary" href="novy.php?copy=${p.id}">Duplikovat</a> ` +
      (['failed', 'partial'].includes(p.status) ? `<button type="button" class="btn btn-sm btn-primary" data-retry="${p.id}">Zkusit znovu</button> ` : '') +
      `<button type="button" class="btn btn-sm btn-outline-danger" data-del="${p.id}" data-series="${upcoming ? 1 : 0}">${upcoming ? 'Smazat a ukončit opakování' : 'Smazat'}</button>`;
  }

  // obsah okna s detailem příspěvku (seznam i kalendář): vlevo média, vpravo údaje
  function detail(p) {
    const real = !p.projected;
    const list = p.media && p.media.length ? p.media : (p.media_url ? [{ url: p.media_url, type: p.media_type }] : []);
    const left = !list.length ? '' : `<div class="pd-media">
      ${list[0].type === 'video'
        ? `<video src="${esc(list[0].url)}" class="pd-main" controls></video>`
        : `<img src="${esc(list[0].url)}" class="pd-main" alt="">`}
      ${list.length > 1 ? `<div class="pd-thumbs">${list.map((m, i) => `<img src="${esc(m.url)}" class="pd-thumb${i ? '' : ' active'}" data-pd-src="${esc(m.url)}" alt="">`).join('')}</div>` : ''}
    </div>`;
    return `<div class="pd${left ? '' : ' pd-single'}">
      ${left}
      <div class="pd-info">
        <div class="pd-meta"><span class="pd-date">${fmt(p.ts ?? p.scheduled_at)}</span>${p.kind === 'reel' ? ' <span class="badge bg-info">Reel</span>' : p.kind === 'story' ? ' <span class="badge bg-info">Story</span>' : ''} ${statusBadge(p.status)}
          ${repeatInfo(p) ? `<span class="text-primary small">${esc(repeatInfo(p))}</span>` : ''}</div>
        <div class="post-text pd-text">${esc(p.message) || '<em class="text-muted">(bez textu)</em>'}</div>
        ${p.link ? `<p class="mb-2"><a href="${esc(p.link)}" target="_blank" rel="noopener noreferrer">${esc(p.link)}</a></p>` : ''}
        ${p.tags ? `<p class="text-primary mb-3">${esc(hashtags(p.tags))}</p>` : ''}
        ${p.first_comment ? `<div class="small text-muted mb-3"><strong>První komentář:</strong> <span style="white-space:pre-wrap">${esc(p.first_comment)}</span></div>` : ''}
        <h6 class="text-muted text-uppercase small mt-3 mb-2">Účty</h6>
        <ul class="list-unstyled mb-3">${p.targets.map(t => `
          <li class="mb-1">${netBadge(t.platform)} ${esc(t.name)}${real ? ` – ${statusBadge(t.status === 'pending' ? 'scheduled' : t.status)}` : ''}
            ${t.error ? `<div class="text-danger small">${esc(t.error)}</div>` : ''}</li>`).join('')}
        </ul>
        ${real ? `<div class="d-flex gap-2 flex-wrap">${actions(p)}</div>`
               : '<p class="text-muted small mb-0">Vznikne automaticky po odeslání předchozího výskytu. Opakování zrušíte smazáním nejbližšího naplánovaného příspěvku.</p>'}
      </div>
    </div>`;
  }

  // velké okno se zavírá jen tlačítkem (✕ nebo „Zavřít“), ne klikem mimo ani klávesou Esc
  function modal(title, bodyHtml, size = 'xl') {
    const el = $('#appModal');
    $('.modal-title', el).textContent = title;
    $('.modal-body', el).innerHTML = bodyHtml;
    $('.modal-dialog', el).className = 'modal-dialog modal-dialog-centered md-' + size;
    window.bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static', keyboard: false }).show();
    return el;
  }
  document.addEventListener('click', (e) => {
    if (e.target.closest('#appModal [data-close]')) { modalHide(); return; }
    const th = e.target.closest('.pd-thumb');
    if (th) {
      const box = th.closest('.pd-media'), main = box.querySelector('.pd-main');
      main.src = th.dataset.pdSrc;
      box.querySelectorAll('.pd-thumb').forEach(x => x.classList.toggle('active', x === th));
    }
  });
  const modalOpen = () => $('#appModal') && $('#appModal').classList.contains('show');
  const modalHide = () => { const m = window.bootstrap.Modal.getInstance($('#appModal')); if (m) m.hide(); };

  // „Zkusit znovu“ a „Smazat“ – použitelné v libovolném kontejneru; po akci se zavolá done()
  function bindActions(root, done) {
    root.addEventListener('click', async (e) => {
      const t = e.target.closest('[data-del],[data-retry]');
      if (!t) return;
      try {
        if (t.dataset.del) {
          const msg = t.dataset.series === '1'
            ? 'Smazat tento příspěvek? Opakování tím skončí (již odeslané příspěvky na síti zůstanou).'
            : 'Smazat příspěvek z plánovače? (Již odeslané příspěvky na síti zůstanou.)';
          if (!confirm(msg)) return;
          await api('delete', form({ id: t.dataset.del }));
        } else {
          t.disabled = true;
          await api('retry', form({ id: t.dataset.retry }));
        }
        modalHide();
        done();
      } catch (err) { flash(err.message, 'danger'); done(); }
    });
  }

  window.App = { $, $$, esc, norm, pad, ymd, api, form, flash, STATUS, STATUS_CLASS, REPEAT, statusBadge, netBadge, NET, avatar,
                 fmt, repeatInfo, icons, actions, detail, modal, modalOpen, modalHide, bindActions,
                 hashtags, compose, cpLen, cleanTag, applyStyle, unstyle, isStyled, cropRect, STYLE_LIST };
})();
