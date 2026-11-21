'use strict';
const { STYLE_LIST } = App;
const { $, $$, esc, norm, ymd, pad, api, form, flash, icons, netBadge, avatar, compose, cpLen, cleanTag, applyStyle, unstyle, hashtags, cropRect, modal, modalHide } = App;

const params = new URLSearchParams(location.search);
const editId = params.get('edit');
const copyId = params.get('copy');
let accounts = [];
const selected = new Set();
const tags = [];
let pvNet = 'facebook';
let lm = null, lmFor = '';      // načtený náhled odkazu (Open Graph)


/* ---------- výběr účtů ---------- */
function visible() {
  const q = norm($('#q').value.trim()), net = $('#fNet').value;
  return accounts.filter(a => (!q || norm(a.name).includes(q) || norm(a.page_name).includes(q)) && (!net || a.platform === net));
}
const chosen = () => accounts.filter(a => selected.has(String(a.id)));
function renderAccounts() {
  const list = visible();
  $('#selCount').textContent = `Vybráno ${selected.size}`;
  if (!accounts.length) { $('#accList').innerHTML = '<div class="p-3 text-muted">Žádný povolený účet. Povolte je na stránce <a href="ucty.php">Účty a propojení</a>.</div>'; return; }
  if (!list.length) { $('#accList').innerHTML = '<div class="p-3 text-muted">Žádný účet neodpovídá hledání.</div>'; return; }
  $('#accList').innerHTML = list.map(a => `
    <label class="list-group-item d-flex align-items-center gap-2 mb-0">
      <input class="form-check-input mt-0" type="checkbox" data-acc="${a.id}" ${selected.has(String(a.id)) ? 'checked' : ''}>
      ${avatar(a)} <span class="fw-semibold">${esc(a.name)}</span>
      ${a.platform === 'instagram' && a.page_name ? `<small class="text-muted ms-auto text-truncate">${esc(a.page_name)}</small>` : ''}
    </label>`).join('');
}
function selectionChanged() { $('#selCount').textContent = `Vybráno ${selected.size}`; updateAll(); bestSoon(); }
$('#accList').addEventListener('change', (e) => {
  const id = e.target.dataset.acc; if (!id) return;
  e.target.checked ? selected.add(id) : selected.delete(id);
  selectionChanged();
});
$('#selAll').onclick = () => { visible().forEach(a => selected.add(String(a.id))); renderAccounts(); selectionChanged(); };
$('#selNone').onclick = () => { selected.clear(); renderAccounts(); selectionChanged(); };
['input', 'change'].forEach(ev => { $('#q').addEventListener(ev, renderAccounts); $('#fNet').addEventListener(ev, renderAccounts); });

/* ---------- formátování textu ---------- */
function onFmt(e) {
  const b = e.target.closest('[data-fmt]'); if (!b) return;
  $('#styleMenu').hidden = true;
  const ta = $('#message'), v = ta.value;
  let s = ta.selectionStart, en = ta.selectionEnd;
  const kind = b.dataset.fmt;
  if (kind === 'bullets') {
    s = v.lastIndexOf('\n', s - 1) + 1;
    const nl = v.indexOf('\n', en); en = nl === -1 ? v.length : nl;
    const lines = v.slice(s, en).split('\n');
    const all = lines.every(l => l.startsWith('• '));
    const out = lines.map(l => all ? l.slice(2) : (l.startsWith('• ') ? l : '• ' + l)).join('\n');
    ta.value = v.slice(0, s) + out + v.slice(en);
    ta.setSelectionRange(s, s + out.length);
  } else {
    if (s === en) { flash('Nejdřív označte text, který chcete formátovat.', 'warning'); return; }
    const sel = v.slice(s, en);
    const out = kind === 'plain' ? unstyle(sel) : applyStyle(sel, kind);
    ta.value = v.slice(0, s) + out + v.slice(en);
    ta.setSelectionRange(s, s + out.length);
  }
  ta.focus();
  updateAll();
}
$('#fmtBar').addEventListener('click', onFmt);
$('#styleMenu').addEventListener('click', onFmt);
$('#styleMenu').innerHTML = STYLE_LIST.map(st => `<button type="button" data-fmt="${st.key}"><span>${esc(App.applyStyle('Ahoj 123', st.key))}</span><small>${esc(st.label)}</small></button>`).join('')
  + '<div class="style-note">Použije se na označený text. Opětovné kliknutí styl odebere (kromě „Vzhůru nohama“). Znaky s diakritikou se u některých stylů zobrazí bez ní nebo s chybou.</div>';
$('#btnStyles').addEventListener('click', (e) => { e.stopPropagation(); $('#styleMenu').hidden = !$('#styleMenu').hidden; });
document.addEventListener('click', (e) => { if (!e.target.closest('#styleMenu, #btnStyles')) $('#styleMenu').hidden = true; });


/* ---------- emoji ---------- */
const EMOJI = {
  'Obličeje': '😀 😃 😄 😁 😆 😅 😂 🤣 🙂 😉 😊 😇 🥰 😍 🤩 😘 😋 😛 😜 🤪 🤗 🤔 🤭 😎 🤓 😏 😌 😴 😢 😭 😤 😡 🥳 🤯 😱 🥺 😬 🙄 😮',
  'Gesta': '👍 👎 👏 🙌 🙏 🤝 💪 👌 ✌️ 🤞 🤟 👋 ☝️ 👉 👈 👇 👆 ✋ 🖐️ 🤙 ✍️ 👀 🧠',
  'Srdce': '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 ✨ 🌟 ⭐ 💫 🔥 💥 💯',
  'Příroda': '🌞 🌤️ ⛅ 🌧️ ⛈️ ❄️ 🌈 🌸 🌹 🌻 🌷 🌿 🍀 🌳 🌴 🍂 🍁 🌊 🏔️ 🌍 🐶 🐱 🦊 🐻 🐼 🦁 🐝 🦋',
  'Jídlo': '🍎 🍊 🍋 🍌 🍉 🍓 🍒 🍑 🥑 🥕 🌽 🍕 🍔 🍟 🌭 🥪 🌮 🍣 🍜 🍰 🎂 🍩 🍪 🍫 🍿 ☕ 🍵 🍷 🍺 🥂',
  'Cesty': '🚗 🚕 🚙 🏎️ 🚌 🚲 🛵 ✈️ 🚀 🚂 ⛵ 🏖️ 🏝️ 🏕️ 🗺️ 📍 🏠 🏢 🏰 🎡',
  'Věci': '📱 💻 📷 🎥 🎧 🎤 🎬 📚 📝 📌 📎 🔗 💡 🔔 🎁 🛒 🛍️ 💰 💳 📦 🔑 ⏰ 📅 📣 📢',
  'Symboly': '✅ ❌ ⚠️ ❗ ❓ ➡️ ⬅️ ⬆️ ⬇️ ▶️ ✔️ ➕ ➖ 🔴 🟢 🔵 🟡 ⚪ ⚫ 🆕 🆓 🔝 🎉 🎊 🏆 🥇',
};
const EMOJI_KW = {
  '😀': 'úsměv smích radost veselý',
  '😃': 'úsměv radost veselý',
  '😄': 'úsměv smích radost',
  '😁': 'úsměv zuby smích',
  '😆': 'smích nahlas',
  '😅': 'pot úleva úsměv',
  '😂': 'smích slzy lol',
  '🤣': 'smích válet',
  '🙂': 'úsměv',
  '😉': 'mrknutí',
  '😊': 'úsměv červenat spokojený',
  '😇': 'anděl nevinný',
  '🥰': 'zamilovaný srdce láska',
  '😍': 'zamilovaný oči srdce',
  '🤩': 'hvězdy wow nadšení',
  '😘': 'pusa polibek',
  '😋': 'mňam dobrý jídlo',
  '😛': 'jazyk',
  '😜': 'jazyk mrknutí',
  '🤪': 'šílený bláznivý',
  '🤗': 'objetí',
  '🤔': 'přemýšlet hmm',
  '🤭': 'ups ruka',
  '😎': 'brýle cool',
  '🤓': 'chytrý šprt brýle',
  '😏': 'úšklebek',
  '😌': 'úleva klid',
  '😴': 'spánek spát',
  '😢': 'slza smutný pláč',
  '😭': 'pláč slzy',
  '😤': 'vztek pára',
  '😡': 'vztek zlý naštvaný',
  '🥳': 'oslava párty narozeniny',
  '🤯': 'šok výbuch hlava',
  '😱': 'křik strach šok',
  '🥺': 'prosím smutný',
  '😬': 'grimasa trapný',
  '🙄': 'oči v sloup',
  '😮': 'překvapení údiv',
  '👍': 'palec nahoru ano líbí',
  '👎': 'palec dolů ne',
  '👏': 'potlesk tleskat',
  '🙌': 'ruce slavit hurá',
  '🙏': 'prosím díky modlitba',
  '🤝': 'podání ruky dohoda',
  '💪': 'síla sval',
  '👌': 'ok dobře',
  '✌️': 'mír vítězství',
  '🤞': 'štěstí prsty',
  '🤟': 'rock',
  '👋': 'ahoj mávat',
  '☝️': 'nahoru prst',
  '👉': 'vpravo ukázat',
  '👈': 'vlevo ukázat',
  '👇': 'dolů ukázat',
  '👆': 'nahoru ukázat',
  '✋': 'stop ruka',
  '🖐️': 'ruka dlaň',
  '🤙': 'zavolej',
  '✍️': 'psát psaní',
  '👀': 'oči koukat',
  '🧠': 'mozek chytrý',
  '❤️': 'srdce láska červené',
  '🧡': 'srdce oranžové',
  '💛': 'srdce žluté',
  '💚': 'srdce zelené',
  '💙': 'srdce modré',
  '💜': 'srdce fialové',
  '🖤': 'srdce černé',
  '🤍': 'srdce bílé',
  '💔': 'zlomené srdce',
  '❣️': 'srdce vykřičník',
  '💕': 'srdce láska dvě',
  '💞': 'srdce kroužící',
  '💓': 'srdce tlukot',
  '💗': 'srdce rostoucí',
  '💖': 'srdce jiskry',
  '💘': 'srdce šíp amor',
  '💝': 'srdce dárek',
  '✨': 'jiskry třpyt kouzlo',
  '🌟': 'hvězda zářící',
  '⭐': 'hvězda',
  '💫': 'hvězda závrať',
  '🔥': 'oheň hit horké',
  '💥': 'výbuch bum',
  '💯': 'sto perfektní',
  '🌞': 'slunce léto',
  '🌤️': 'slunce mrak',
  '⛅': 'polojasno mrak',
  '🌧️': 'déšť',
  '⛈️': 'bouřka',
  '❄️': 'sníh zima vločka',
  '🌈': 'duha',
  '🌸': 'květ třešeň jaro',
  '🌹': 'růže',
  '🌻': 'slunečnice',
  '🌷': 'tulipán',
  '🌿': 'bylina list',
  '🍀': 'jetel štěstí',
  '🌳': 'strom',
  '🌴': 'palma',
  '🍂': 'listí podzim',
  '🍁': 'javor podzim',
  '🌊': 'vlna moře voda',
  '🏔️': 'hory',
  '🌍': 'země svět',
  '🐶': 'pes',
  '🐱': 'kočka',
  '🦊': 'liška',
  '🐻': 'medvěd',
  '🐼': 'panda',
  '🦁': 'lev',
  '🐝': 'včela med',
  '🦋': 'motýl',
  '🍎': 'jablko',
  '🍊': 'pomeranč',
  '🍋': 'citron',
  '🍌': 'banán',
  '🍉': 'meloun',
  '🍓': 'jahoda',
  '🍒': 'třešně',
  '🍑': 'broskev',
  '🥑': 'avokádo',
  '🥕': 'mrkev',
  '🌽': 'kukuřice',
  '🍕': 'pizza',
  '🍔': 'hamburger',
  '🍟': 'hranolky',
  '🌭': 'párek hot dog',
  '🥪': 'sendvič',
  '🌮': 'taco',
  '🍣': 'sushi',
  '🍜': 'nudle polévka',
  '🍰': 'dort',
  '🎂': 'dort narozeniny',
  '🍩': 'donut kobliha',
  '🍪': 'sušenka',
  '🍫': 'čokoláda',
  '🍿': 'popcorn',
  '☕': 'káva kafe',
  '🍵': 'čaj',
  '🍷': 'víno',
  '🍺': 'pivo',
  '🥂': 'přípitek šampaňské',
  '🚗': 'auto',
  '🚕': 'taxi',
  '🚙': 'auto suv',
  '🏎️': 'závodní auto',
  '🚌': 'autobus',
  '🚲': 'kolo',
  '🛵': 'skútr',
  '✈️': 'letadlo cestování',
  '🚀': 'raketa start',
  '🚂': 'vlak',
  '⛵': 'loď plachetnice',
  '🏖️': 'pláž dovolená',
  '🏝️': 'ostrov',
  '🏕️': 'kemp stan',
  '🗺️': 'mapa',
  '📍': 'špendlík místo poloha',
  '🏠': 'dům domov',
  '🏢': 'budova kancelář',
  '🏰': 'hrad zámek',
  '🎡': 'pouť kolotoč',
  '📱': 'telefon mobil',
  '💻': 'notebook počítač',
  '📷': 'foto fotoaparát',
  '🎥': 'kamera video',
  '🎧': 'sluchátka hudba',
  '🎤': 'mikrofon',
  '🎬': 'klapka film',
  '📚': 'knihy',
  '📝': 'poznámky zápis',
  '📌': 'připínáček',
  '📎': 'sponka příloha',
  '🔗': 'odkaz link',
  '💡': 'nápad žárovka',
  '🔔': 'zvonek upozornění',
  '🎁': 'dárek',
  '🛒': 'košík nákup',
  '🛍️': 'nákup tašky',
  '💰': 'peníze pytel',
  '💳': 'karta platba',
  '📦': 'balík zásilka',
  '🔑': 'klíč',
  '⏰': 'budík čas',
  '📅': 'kalendář datum',
  '📣': 'megafon oznámení',
  '📢': 'reproduktor oznámení',
  '✅': 'hotovo splněno fajfka',
  '❌': 'křížek ne zrušit',
  '⚠️': 'pozor varování',
  '❗': 'vykřičník důležité',
  '❓': 'otazník otázka',
  '➡️': 'šipka doprava',
  '⬅️': 'šipka doleva',
  '⬆️': 'šipka nahoru',
  '⬇️': 'šipka dolů',
  '▶️': 'přehrát play',
  '✔️': 'fajfka',
  '➕': 'plus přidat',
  '➖': 'mínus',
  '🔴': 'červený kruh',
  '🟢': 'zelený kruh',
  '🔵': 'modrý kruh',
  '🟡': 'žlutý kruh',
  '⚪': 'bílý kruh',
  '⚫': 'černý kruh',
  '🆕': 'nové novinka',
  '🆓': 'zdarma',
  '🔝': 'top nahoru',
  '🎉': 'oslava konfety party',
  '🎊': 'konfety oslava',
  '🏆': 'pohár trofej vítěz',
  '🥇': 'zlato první místo medaile'
};
const emojiList = (k) => EMOJI[k].split(' ');
const EMOJI_ALL = [...new Set(Object.keys(EMOJI).flatMap(emojiList))];
let emojiTarget = $('#message');
['message', 'firstComment'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('focus', () => { emojiTarget = el; }); });
function emojiRecent() { try { return JSON.parse(localStorage.getItem('sp_emoji') || '[]'); } catch (e) { return []; } }
function emojiRemember(em) { try { localStorage.setItem('sp_emoji', JSON.stringify([em, ...emojiRecent().filter(x => x !== em)].slice(0, 24))); } catch (e) {} }
function emojiShow(cat, q) {
  const list = q ? EMOJI_ALL.filter(e => norm(`${e} ${EMOJI_KW[e] || ''}`).includes(norm(q))) : cat === 'Naposledy' ? emojiRecent() : emojiList(cat);
  $('#emojiTabs').innerHTML = ['Naposledy', ...Object.keys(EMOJI)].map(k => `<button type="button" class="${k === cat ? 'active' : ''}" data-cat="${esc(k)}">${esc(k)}</button>`).join('');
  $('#emojiGrid').innerHTML = list.length ? list.map(e => `<button type="button" data-em="${e}">${e}</button>`).join('') : `<span class="text-muted small p-2">${q ? 'Nic nenalezeno.' : 'Zatím nic. Vyberte emoji z jiné záložky.'}</span>`;
}
function emojiOpen(anchor, target) {
  emojiTarget = target;
  const pop = $('#emojiPop');
  if (!pop.hidden && pop.dataset.for === anchor.id) { pop.hidden = true; return; }
  pop.dataset.for = anchor.id;
  $('#emojiQ').value = '';
  emojiShow(emojiRecent().length ? 'Naposledy' : 'Obličeje');
  const host = anchor.closest('.mb-3'); host.style.position = 'relative'; host.appendChild(pop);
  pop.hidden = false;
}
function emojiInsert(em) {
  const ta = emojiTarget, s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? s;
  ta.value = ta.value.slice(0, s) + em + ta.value.slice(e);
  ta.focus(); ta.setSelectionRange(s + em.length, s + em.length);
  emojiRemember(em); updateAll();
}
$('#btnEmoji').addEventListener('click', (e) => { e.stopPropagation(); $('#styleMenu').hidden = true; emojiOpen(e.currentTarget, $('#message')); });
const btnEmoji2 = $('#btnEmoji2');
if (btnEmoji2) btnEmoji2.addEventListener('click', (e) => { e.stopPropagation(); emojiOpen(e.currentTarget, $('#firstComment')); });
$('#emojiQ').addEventListener('input', (e) => { const q = e.target.value.trim(); emojiShow('Obličeje', q || ''); if (q) $$('#emojiTabs .active').forEach(b => b.classList.remove('active')); });
$('#emojiPop').addEventListener('click', (e) => {
  e.stopPropagation();
  const c = e.target.closest('[data-cat]'); if (c) { $('#emojiQ').value = ''; return emojiShow(c.dataset.cat); }
  const m = e.target.closest('[data-em]'); if (m) emojiInsert(m.dataset.em);
});
document.addEventListener('click', (e) => { if (!e.target.closest('#emojiPop, #btnEmoji, #btnEmoji2')) $('#emojiPop').hidden = true; });
$('#btnStyles').addEventListener('click', () => { $('#emojiPop').hidden = true; });

/* ---------- štítky ---------- */
function addTags(raw) {
  String(raw).split(/[\s,;]+/).map(cleanTag).filter(Boolean).forEach(t => {
    if (!tags.some(x => x.toLowerCase() === t.toLowerCase())) tags.push(t);
  });
  renderTags();
}
function renderTags() {
  $('#tagChips').innerHTML = tags.map((t, i) => `<span class="tag-chip">#${esc(t)}<button type="button" data-rm="${i}" aria-label="Odebrat">×</button></span>`).join('');
  $('#tagCount').textContent = tags.length ? `Zadáno ${tags.length}.` : '';
  updateAll();
}
$('#tagBox').addEventListener('click', (e) => {
  const rm = e.target.closest('[data-rm]');
  if (rm) { tags.splice(+rm.dataset.rm, 1); renderTags(); return; }
  $('#tagInput').focus();
});
$('#tagInput').addEventListener('keydown', (e) => {
  const v = e.target.value;
  if (['Enter', ',', ' ', ';'].includes(e.key) && v.trim()) { e.preventDefault(); addTags(v); e.target.value = ''; }
  else if (e.key === 'Enter') e.preventDefault();
  else if (e.key === 'Backspace' && !v && tags.length) { tags.pop(); renderTags(); }
});
$('#tagInput').addEventListener('input', (e) => { if (/[\s,;]$/.test(e.target.value)) { addTags(e.target.value); e.target.value = ''; } });
// vložení seznamu štítků najednou (#a #b #c) – přidá se hned
$('#tagInput').addEventListener('paste', (e) => {
  const t = (e.clipboardData || window.clipboardData)?.getData('text') || '';
  if (!t) return;
  e.preventDefault(); addTags(t); $('#tagInput').value = '';
});
// vložení textu, který končí řádkem s hashtagy → hashtagy se přesunou do štítků
$('#message').addEventListener('paste', (e) => {
  const t = (e.clipboardData || window.clipboardData)?.getData('text') || '';
  const re = /(?:^|\n)[ \t]*((?:#[\p{L}\p{N}_]+[ \t,;]*)+)\s*$/u;
  if (!re.test(t)) return;
  setTimeout(() => {
    const ta = $('#message'), m = ta.value.match(re);
    if (!m) return;
    ta.value = ta.value.slice(0, m.index).replace(/\s+$/, '');
    addTags(m[1]);
    flash(`Hashtagy z konce textu byly přesunuty do štítků (${m[1].trim().split(/[\s,;]+/).length}).`, 'info');
    updateAll();
  }, 0);
});
$('#tagInput').addEventListener('blur', (e) => { if (e.target.value.trim()) { addTags(e.target.value); e.target.value = ''; } });

/* ---------- média: drag & drop, více obrázků, převod na JPEG, ořez ---------- */
const MAX_IMG = 10;
// položka: {type, file (File | null u uloženého), name (název uloženého souboru | null), url, ratio, orig (Image pro ořez)}
let items = [];
const drop = $('#drop');
drop.addEventListener('click', () => $('#media').click());
drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#media').click(); } });
['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', (e) => { if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
$('#media').addEventListener('change', (e) => { if (e.target.files.length) addFiles(e.target.files); e.target.value = ''; });
// vložení obrázku ze schránky (Ctrl+V)
document.addEventListener('paste', (e) => {
  const fs = [...(e.clipboardData?.files || [])].filter(x => x.type.startsWith('image/'));
  if (fs.length) addFiles(fs);
});

function loadImage(src) {
  return new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('Obrázek se nepodařilo načíst.')); i.src = src; });
}
const toJpeg = (img, sx, sy, sw, sh, outW) => new Promise((res, rej) => {
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(outW)); c.height = Math.max(1, Math.round(outW * sh / sw));
  const g = c.getContext('2d');
  g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);      // průhlednost PNG → bílá
  g.drawImage(img, sx, sy, sw, sh, 0, 0, c.width, c.height);
  c.toBlob(b => b ? res(new File([b], 'obrazek.jpg', { type: 'image/jpeg' })) : rej(new Error('Převod obrázku selhal.')), 'image/jpeg', 0.92);
});
const freeItem = (it) => { if (it.url && it.url.startsWith('blob:')) URL.revokeObjectURL(it.url); if (it.origUrl) URL.revokeObjectURL(it.origUrl); };
function clearAll() { items.forEach(freeItem); items = []; }

async function imageItem(f) {
  const src = URL.createObjectURL(f);
  let img;
  try { img = await loadImage(src); } catch (e) { URL.revokeObjectURL(src); throw e; }
  const k = Math.min(1, 2048 / Math.max(img.naturalWidth, img.naturalHeight));
  const file = await toJpeg(img, 0, 0, img.naturalWidth, img.naturalHeight, img.naturalWidth * k);   // vždy JPEG (Instagram)
  return { type: 'image', file, name: null, url: URL.createObjectURL(file), ratio: img.naturalWidth / img.naturalHeight, orig: img, origUrl: src };
}
async function addFiles(list) {
  const files = [...list];
  try {
    const vids = files.filter(f => f.type.startsWith('video/')), imgs = files.filter(f => f.type.startsWith('image/'));
    if (!vids.length && !imgs.length) throw new Error('Podporovány jsou obrázky a videa MP4/MOV.');
    if (vids.length) {
      const v = vids[0];
      if (!['video/mp4', 'video/quicktime'].includes(v.type)) throw new Error('Video musí být MP4 nebo MOV.');
      if (vids.length > 1 || imgs.length) flash('Video nelze kombinovat s dalšími soubory, použilo se jen první video.', 'warning');
      clearAll(); items = [{ type: 'video', file: v, name: null, url: URL.createObjectURL(v), ratio: null, orig: null }];
    } else {
      if (items.some(i => i.type === 'video')) clearAll();
      for (const f of imgs) {
        if (items.length >= MAX_IMG) { flash(`Nejvýše ${MAX_IMG} obrázků v jednom příspěvku.`, 'warning'); break; }
        items.push(await imageItem(f));
      }
    }
    renderMedia(); updateAll();
  } catch (err) { flash(err.message, 'danger'); }
}
/* ---------- výběr z knihovny médií ---------- */
/* ---------- 1.8.0: autor a licence souborů z knihovny (obrázky z internetu) ---------- */
let libMeta = new Map();   // soubor → položka knihovny (credit_text, credit_needed …)
async function loadLibMeta() {
  const lib = await api('library');
  libMeta = new Map(lib.items.map(i => [i.file, i]));
  return lib;
}
function updateCreditHint() {
  const box = $('#creditHint'); if (!box) return;
  const need = items.map(i => libMeta.get(i.name)).filter(m => m && m.credit_needed && m.credit_text);
  const msg = $('#message').value, fc = $('#firstComment') ? $('#firstComment').value : '';
  box.innerHTML = need.map((m, k) => {
    const line = Stock.creditLine(m), done = msg.includes(m.credit_text.split(' · ')[0]) || fc.includes(m.credit_text.split(' · ')[0]);
    return `<div class="credit-hint mb-1">${done ? '✓ ' : ''}Obrázek „${esc(m.name)}“ má licenci, která vyžaduje <b>uvést autora</b>: <span class="text-muted">${esc(line)}</span>
      ${done ? '' : `<button type="button" class="btn btn-sm btn-link p-0 align-baseline ms-1" data-credit="${k}" data-to="message">Vložit do textu</button>${$('#firstComment') ? ` · <button type="button" class="btn btn-sm btn-link p-0 align-baseline" data-credit="${k}" data-to="firstComment">Do prvního komentáře</button>` : ''}`}</div>`;
  }).join('');
  box.onclick = (e) => {
    const b = e.target.closest('[data-credit]'); if (!b) return;
    const ta = document.getElementById(b.dataset.to), line = Stock.creditLine(need[+b.dataset.credit]);
    ta.value = ta.value.trimEnd() + (ta.value.trim() ? '\n\n' : '') + line;
    ta.dispatchEvent(new Event('input', { bubbles: true })); updateAll();
  };
}
function addLibraryItems(chosen) {
  const vid = chosen.find(i => i.type === 'video');
  if (vid) { clearAll(); items = [{ type: 'video', file: null, name: vid.file, url: vid.url, ratio: null, orig: null }]; }
  else {
    if (items.some(i => i.type === 'video')) clearAll();
    for (const i of chosen) {
      if (items.some(x => x.name === i.file)) continue;
      if (items.length >= MAX_IMG) { flash(`Nejvýše ${MAX_IMG} obrázků v jednom příspěvku.`, 'warning'); break; }
      items.push({ type: 'image', file: null, name: i.file, url: i.url, ratio: i.w && i.h ? i.w / i.h : null, orig: null });
    }
  }
  renderMedia(); updateAll();
}
$('#webPick').addEventListener('click', () => Stock.open({ title: 'Obrázek z internetu do příspěvku', onDone: async (ids) => {
  try { const lib = await loadLibMeta(); addLibraryItems(ids.map(id => lib.items.find(x => x.id === id)).filter(Boolean)); }
  catch (e) { flash(e.message, 'danger'); }
} }));
$('#message').addEventListener('input', () => updateCreditHint());

async function openLibrary() {
  let lib;
  try { lib = await loadLibMeta(); } catch (e) { return flash(e.message, 'danger'); }
  const pick = new Set();
  const T = window.Tags, tagSt = T.newState();
  const avail = lib.items.filter(i => !i.archived);   // archiv se ve výběru nenabízí
  const el = modal('Vybrat z knihovny', `
    <div class="row g-2 mb-2">
      <div class="col-sm-8"><input type="search" class="form-control" id="lpQ" placeholder="Hledat podle názvu nebo tagu…" autocomplete="off"></div>
      <div class="col-sm-4"><select class="form-select" id="lpType"><option value="">Vše</option><option value="image">Obrázky</option><option value="video">Videa</option></select></div>
    </div>
    <div class="tag-bar mb-2" id="lpTags"></div>
    <div class="lib-pick-grid"><div class="lib-grid" id="lpGrid"></div></div>
    <div class="d-flex justify-content-between align-items-center mt-3"><span class="text-muted" id="lpN"></span><button type="button" class="btn btn-primary" id="lpOk" disabled>Vložit vybrané</button></div>`, 'xl');
  const $e = (s) => el.querySelector(s);
  const list = () => {
    const q = norm($e('#lpQ').value.trim()), t = $e('#lpType').value;
    return avail.filter(i => (!q || norm(i.name).includes(q) || (i.tags || []).some(x => norm(x).includes(q))) && (!t || i.type === t) && T.ok(i, tagSt));
  };
  const draw = () => {
    T.bar($e('#lpTags'), avail, tagSt, draw);
    $e('#lpGrid').innerHTML = list().map(i => `<div class="lib-card${pick.has(i.id) ? ' sel' : ''}" data-id="${i.id}">
      <div class="lib-thumb">${i.type === 'video' ? `<video src="${esc(i.url)}#t=0.1" muted preload="metadata"></video><span class="lib-vid">▶</span>` : `<img src="${esc(i.url)}" alt="" loading="lazy">`}</div>
      <div class="lib-meta"><div class="lib-name text-truncate">${esc(i.name)}</div><div class="small text-muted">${i.w ? i.w + '×' + i.h : 'video'}</div>${T.chips(i)}</div></div>`).join('') || '<div class="text-muted">Nic nenalezeno. Soubory přidáte v nabídce Knihovna médií.</div>';
    $e('#lpN').textContent = pick.size ? `Vybráno ${pick.size}` : 'Klikněte na soubory, které chcete vložit.';
    $e('#lpOk').disabled = !pick.size;
  };
  $e('#lpGrid').addEventListener('click', (ev) => {
    const qt = ev.target.closest('[data-qtag]');
    if (qt) { tagSt.none = false; tagSt.sel.clear(); tagSt.sel.add(qt.dataset.qtag); draw(); return; }
    const c = ev.target.closest('.lib-card'); if (!c) return;
    const id = +c.dataset.id, it = lib.items.find(x => x.id === id);
    if (pick.has(id)) pick.delete(id);
    else { if (it.type === 'video') pick.clear(); else [...pick].forEach(p => { if (lib.items.find(x => x.id === p).type === 'video') pick.delete(p); }); pick.add(id); }
    draw();
  });
  ['#lpQ', '#lpType'].forEach(s => $e(s).addEventListener('input', draw));
  $e('#lpOk').onclick = () => { const chosen = lib.items.filter(i => pick.has(i.id)); modalHide(); addLibraryItems(chosen); };
  draw();
}
$('#libPick').addEventListener('click', openLibrary);
// 1.6.0: slide z editoru (nová karta) se po uložení sám vloží do příspěvku
$('#slideNew').addEventListener('click', () => {
  const fmt = ['story', 'reel'].includes(kindVal()) ? 'story' : 'square';
  window.open(`slide.php?for=novy&format=${fmt}`, '_blank');
});
window.addEventListener('message', async (ev) => {
  if (ev.origin !== location.origin || ev.data?.type !== 'sp-slide-saved') return;
  try {
    const lib = await loadLibMeta();
    const it = lib.items.find(x => x.id === ev.data.id); if (!it) return;
    if (items.some(i => i.type === 'video')) clearAll();
    const ex = items.find(x => x.name === it.file);
    if (ex) ex.url = it.url;   // znovu uložený slide: jen obnovit náhled
    else if (items.length >= MAX_IMG) { flash(`Nejvýše ${MAX_IMG} obrázků v jednom příspěvku.`, 'warning'); return; }
    else items.push({ type: 'image', file: null, name: it.file, url: it.url, ratio: it.w && it.h ? it.w / it.h : null, orig: null });
    renderMedia(); updateAll(); flash(`Slide „${it.name}“ je v příspěvku.`);
  } catch (e) { flash(e.message, 'danger'); }
});

function renderMedia() {
  $('#mediaStrip').innerHTML = items.map((it, i) => `
    <div class="media-item">
      ${it.type === 'video' ? `<video src="${esc(it.url)}" muted></video>` : `<img src="${esc(it.url)}" alt="">`}
      <span class="mi-num">${i + 1}</span>
      <div class="mi-bar">
        <button type="button" data-act="left" data-i="${i}" title="Posunout dopředu" ${i === 0 || it.type === 'video' ? 'disabled' : ''}>◀</button>
        <button type="button" data-act="crop" data-i="${i}" title="Oříznout" ${it.type === 'video' ? 'disabled' : ''}>✂</button>
        <button type="button" data-act="right" data-i="${i}" title="Posunout dozadu" ${i === items.length - 1 || it.type === 'video' ? 'disabled' : ''}>▶</button>
        <button type="button" class="mi-del" data-act="del" data-i="${i}" title="Odebrat">✕</button>
      </div>
    </div>`).join('');
  const n = items.length;
  $('#mediaInfo').textContent = !n ? '' : items[0].type === 'video' ? 'Video' :
    `${n} ${n === 1 ? 'obrázek' : n < 5 ? 'obrázky' : 'obrázků'}${n > 1 ? ' – Instagram je zveřejní jako carousel, Facebook jako galerii' : ''} · odesílá se jako JPEG`;
  updateCreditHint();
}
$('#mediaStrip').addEventListener('click', (e) => {
  const b = e.target.closest('[data-act]'); if (!b || b.disabled) return;
  const i = +b.dataset.i, act = b.dataset.act;
  if (act === 'del') { freeItem(items[i]); items.splice(i, 1); }
  else if (act === 'left' && i > 0) [items[i - 1], items[i]] = [items[i], items[i - 1]];
  else if (act === 'right' && i < items.length - 1) [items[i + 1], items[i]] = [items[i], items[i + 1]];
  else if (act === 'crop') return openCrop(items[i]);
  renderMedia(); updateAll();
});
const imageRatios = () => items.filter(i => i.type === 'image' && i.ratio).map(i => i.ratio);
const badRatio = () => imageRatios().some(r => r < 0.795 || r > 1.915);

const PRESETS = [
  { key: 'orig', label: 'Původní', a: null },
  { key: 'sq', label: '1:1 čtverec', hint: 'Instagram, Facebook', a: 1 },
  { key: 'por', label: '4:5 na výšku', hint: 'Instagram, Facebook', a: 0.8 },
  { key: 'land', label: '1,91:1 na šířku', hint: 'Facebook, Instagram', a: 1.91 },
];
async function openCrop(item) {
  // u uloženého příspěvku se původní obrázek načte ze serveru
  if (!item.orig) { try { item.orig = await loadImage(item.url); } catch (e) { return flash(e.message, 'danger'); } }
  const orig = item.orig, W = orig.naturalWidth, H = orig.naturalHeight;
  const k = Math.min(520 / W, 380 / H), sw = Math.round(W * k), sh = Math.round(H * k);
  let preset = PRESETS[1], zoom = 1, cx = W / 2, cy = H / 2;

  const el = modal('Oříznout obrázek', `
    <div class="mb-2 btn-group btn-group-sm flex-wrap" id="cropPresets">${PRESETS.map(p => `<button type="button" class="btn btn-outline-primary${p === preset ? ' active' : ''}" data-p="${p.key}" title="${esc(p.hint || '')}">${esc(p.label)}</button>`).join('')}</div>
    <div class="crop-stage" id="cropStage" style="width:${sw}px;height:${sh}px"><img src="${esc(orig.src)}" alt=""><div class="crop-rect" id="cropRect"></div></div>
    <div class="d-flex align-items-center gap-2 mt-3"><small class="text-muted">Zvětšení</small><input type="range" class="form-range" id="cropZoom" min="30" max="100" value="100"><small class="text-muted">Výřez můžete přetahovat.</small></div>
    <div class="text-end mt-3"><button type="button" class="btn btn-primary" id="cropOk">Použít</button></div>`, 'md');
  const rectEl = $('#cropRect', el);

  const rect = () => preset.a ? cropRect(W, H, preset.a, zoom, cx, cy) : { x: 0, y: 0, w: W, h: H };
  const draw = () => {
    const r = rect(); cx = r.x + r.w / 2; cy = r.y + r.h / 2;
    Object.assign(rectEl.style, { left: r.x * k + 'px', top: r.y * k + 'px', width: r.w * k + 'px', height: r.h * k + 'px', display: preset.a ? '' : 'none' });
    $('#cropZoom', el).disabled = !preset.a;
  };
  $('#cropPresets', el).addEventListener('click', (e) => {
    const b = e.target.closest('[data-p]'); if (!b) return;
    preset = PRESETS.find(p => p.key === b.dataset.p); zoom = 1; cx = W / 2; cy = H / 2;
    $$('#cropPresets button', el).forEach(x => x.classList.toggle('active', x === b));
    $('#cropZoom', el).value = 100; draw();
  });
  $('#cropZoom', el).addEventListener('input', (e) => { zoom = e.target.value / 100; draw(); });
  let drag = null;
  rectEl.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, y: e.clientY, cx, cy }; rectEl.setPointerCapture?.(e.pointerId); e.preventDefault(); });
  rectEl.addEventListener('pointermove', (e) => { if (!drag) return; cx = drag.cx + (e.clientX - drag.x) / k; cy = drag.cy + (e.clientY - drag.y) / k; draw(); });
  ['pointerup', 'pointercancel'].forEach(ev => rectEl.addEventListener(ev, () => { drag = null; }));
  draw();

  $('#cropOk', el).onclick = async () => {
    try {
      const r = rect();
      const file = await toJpeg(orig, r.x, r.y, r.w, r.h, Math.min(r.w, 1440));     // Instagram: max. šířka 1440 px
      if (item.url.startsWith('blob:')) URL.revokeObjectURL(item.url);
      item.file = file; item.name = null; item.url = URL.createObjectURL(file); item.ratio = r.w / r.h;
      modalHide(); renderMedia(); updateAll();
    } catch (err) { flash(err.message, 'danger'); }
  };
}

/* ---------- proměnné {{…}} (od 1.5.0 i data, zdroje a počasí; dosazuje server) ---------- */
let VARS = { user: [], builtin: {} };
const MONTHS_CZ = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
const DAYS_CZ = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
const whenTs = () => { const d = new Date($('#when').value || Date.now()); return isNaN(d) ? Math.floor(Date.now() / 1000) : Math.floor(d.getTime() / 1000); };
// náhled: text s dosazenými hodnotami ze serveru (podle času zveřejnění); než odpoví, dosadí se aspoň jednoduché proměnné
let pv = { key: '', text: '', unknown: [] }, pvTimer = null, pvWant = '';
function localVars(text) {
  const d = new Date(whenTs() * 1000);
  return text.replace(/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/gi, (m, n) => {
    const k = n.toLowerCase();
    const b = { datum: `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()}`, den: DAYS_CZ[d.getDay()], mesic: MONTHS_CZ[d.getMonth()], rok: String(d.getFullYear()), cas: `${d.getHours()}:${pad(d.getMinutes())}`, cislo: '1' };
    if (k in b) return n === 'Den' ? b.den[0].toUpperCase() + b.den.slice(1) : b[k];
    const u = VARS.user.find(x => x.name === k);
    if (!u) return m;
    return u.type === 'list' ? (u.value.split(/\r?\n/).map(x => x.trim()).filter(Boolean)[0] || '') : u.value;
  });
}
function expandVars(text) {
  if (!text.includes('{{')) return text;
  const key = whenTs() + '|' + text;
  if (pv.key === key) return pv.text;
  if (pvWant !== key) {
    pvWant = key;
    clearTimeout(pvTimer);
    pvTimer = setTimeout(async () => {
      try {
        const r = await api('vars_preview', form({ ts: whenTs(), 'texts[0]': text }));
        if (pvWant !== key) return;
        pv = { key, text: r.items[0].text, unknown: r.items[0].unknown };
        updateAll();
      } catch (e) { /* náhled není kritický */ }
    }, 350);
  }
  return localVars(text);
}
const knownUnknown = (text) => (pv.key === whenTs() + '|' + text ? pv.unknown : []);
function renderVarBar() {
  const chip = (v, t) => `<button type="button" class="btn btn-sm btn-outline-secondary py-0 me-1 mb-1" data-var="${esc(v)}" title="${esc(t)}">${esc(v)}</button>`;
  $('#varBar').innerHTML = '<span class="text-muted me-1">Vložit proměnnou:</span>' +
    ['datum', 'den', 'svatek', 'cas'].map(n => chip(`{{${n}}}`, VARS.builtin[n] || '')).join('') +
    VARS.user.map(v => chip(`{{${v.name}}}`, v.type === 'list' ? 'seznam hodnot' : v.value)).join('') +
    '<button type="button" class="btn btn-sm btn-primary py-0 me-1 mb-1" id="varMore" title="Kalendář, tabulka dat, vzdálené zdroje (Allwyn…), počasí a formáty čísel a dat">＋ Další (data, zdroje, počasí)…</button>' +
    ' <a href="promenne.php" class="ms-1">Spravovat</a>';
}
let varTarget = 'message';   // kam se vkládá: text příspěvku, nebo první komentář (pole, ve kterém byl naposledy kurzor)
['message', 'firstComment'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('focus', () => { varTarget = id; }); });
function insertVar(ins) {
  const ta = document.getElementById(varTarget) || $('#message');
  const s0 = ta.selectionStart ?? ta.value.length, e0 = ta.selectionEnd ?? ta.value.length;
  ta.value = ta.value.slice(0, s0) + ins + ta.value.slice(e0);
  ta.focus(); ta.setSelectionRange(s0 + ins.length, s0 + ins.length); updateAll();
}
$('#varBar').addEventListener('click', (e) => {
  if (e.target.closest('#varMore')) { VarPick.open(insertVar); return; }
  const b = e.target.closest('[data-var]'); if (!b) return;
  insertVar(b.dataset.var);
});

/* ---------- náhled odkazu (obrázek, titulek, popis z webu) ---------- */
function linkCard(link) {
  if (lm && lmFor === link) {
    return `<div class="pv-linkcard p-0 overflow-hidden">${lm.image ? `<img src="${esc(lm.image)}" alt="" style="width:100%;max-height:220px;object-fit:cover;display:block">` : ''}
      <div class="p-2"><div class="text-uppercase" style="font-size:.7rem">${esc(lm.site)}</div><strong class="text-dark">${esc(lm.title)}</strong>
      ${lm.description ? `<div>${esc(lm.description.slice(0, 120))}</div>` : ''}</div></div>`;
  }
  return `<div class="pv-linkcard">${esc(link)}</div>`;
}
async function loadLinkMeta() {
  const link = $('#link').value.trim(), box = $('#linkMeta');
  if (!/^https?:\/\/\S+$/i.test(link)) { lm = null; lmFor = ''; box.innerHTML = ''; renderPreview(); return; }
  if (lmFor === link && lm) return;
  box.innerHTML = '<small class="text-muted">Načítám náhled odkazu…</small>';
  try {
    lm = (await api('linkmeta', form({ url: link }))).meta; lmFor = link;
    box.innerHTML = `<div class="border rounded p-2 d-flex gap-2 align-items-start">
      ${lm.image ? `<img src="${esc(lm.image)}" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:6px;flex:none">` : ''}
      <div class="small flex-fill"><strong>${esc(lm.title) || '<em>(bez titulku)</em>'}</strong><div class="text-muted">${esc((lm.description || '').slice(0, 140))}</div>
        <div class="text-muted">${esc(lm.site)}</div>
        ${lm.image ? '<button type="button" class="btn btn-outline-primary btn-sm mt-1" id="btnLinkImg">Použít tento obrázek u příspěvku</button>' : ''}</div></div>
      <div class="form-text">Takto odkaz zobrazí Facebook (titulek a popis si bere ze stránky, kterou odkaz otevírá). Jedině u příspěvku <strong>bez</strong> nahraného obrázku; s obrázkem se odkaz vloží jen jako text.</div>`;
  } catch (e) { lm = null; lmFor = ''; box.innerHTML = `<small class="text-warning">Náhled se nepodařilo načíst: ${esc(e.message)}</small>`; }
  renderPreview();
}
$('#linkMeta').addEventListener('click', async (e) => {
  if (!e.target.closest('#btnLinkImg') || !lm) return;
  try {
    const r = await fetch('api.php?action=linkimage', { method: 'POST', body: form({ url: lm.image }), headers: { 'X-CSRF': window.CSRF } });
    if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || 'Obrázek se nepodařilo stáhnout.');
    const b = await r.blob();
    await addFiles([new File([b], 'z-odkazu', { type: b.type || 'image/jpeg' })]);
    flash('Obrázek z odkazu je přidán, můžete ho oříznout.');
  } catch (err) { flash(err.message, 'danger'); }
});
$('#link').addEventListener('change', loadLinkMeta);
$('#link').addEventListener('paste', () => setTimeout(loadLinkMeta, 50));

/* ---------- náhled ---------- */
function renderPreview() {
  const msg = expandVars($('#message').value), link = $('#link').value.trim();
  const fb = chosen().find(a => a.platform === 'facebook'), ig = chosen().find(a => a.platform === 'instagram');
  const first = items[0], url = first ? first.url : null, type = first ? first.type : null, ratio = first && first.ratio;
  const cnt = items.length > 1 && type === 'image' ? `<span class="pv-count">1/${items.length}</span>` : '';
  const media = (ratioClamp) => !url ? '' : type === 'video'
    ? `<div class="pv-media" style="aspect-ratio:${ratioClamp ? '4/5' : '16/9'}"><video src="${esc(url)}" muted></video></div>`
    : `<div class="pv-media" style="aspect-ratio:${ratioClamp ? Math.min(1.91, Math.max(0.8, ratio || 1)) : (ratio || 1)};position:relative"><img src="${esc(url)}" alt="">${cnt}</div>`;
  const cut = (s, n) => { const a = [...s]; return a.length > n ? { t: a.slice(0, n).join('') + '…', more: true } : { t: s, more: false }; };
  let html;
  if (pvNet === 'facebook') {
    const text = cut(compose(msg, link, tags, !!url), 480);                       // s médiem je odkaz součástí textu
    const textOnlyLink = !url && link;
    const body = textOnlyLink ? cut(compose(msg, '', tags), 480) : text;
    html = (!msg && !link && !url && !tags.length) ? '<div class="pv-empty">Začněte psát…</div>' : `
      <div class="pv pv-fb"><div class="pv-head"><span class="pv-avatar"></span><div><strong>${esc(fb ? fb.name : 'Název stránky')}</strong><div class="small text-muted">Právě teď · 🌐</div></div></div>
      ${body.t ? `<div class="pv-text">${esc(body.t)}${body.more ? ' <span class="pv-more">Zobrazit více</span>' : ''}</div>` : ''}
      ${textOnlyLink ? linkCard(link) : ''}
      ${media(false)}
      <div class="pv-actions"><span>👍 To se mi líbí</span><span>💬 Komentář</span><span>↪ Sdílet</span></div></div>`;
  } else if (pvNet === 'threads') {
    const th = chosen().find(a => a.platform === 'threads'), name = th ? th.name.replace(/^@/, '') : 'vas_ucet';
    const body = cut(url ? compose(msg, link, tags) : compose(msg, '', tags), 500);
    html = (!msg && !link && !url && !tags.length) ? '<div class="pv-empty">Začněte psát…</div>' : `
      <div class="pv pv-th"><div class="pv-head"><span class="pv-avatar"></span><strong>${esc(name)}</strong><span class="small text-muted ms-2">právě teď</span></div>
      ${body.t ? `<div class="pv-text">${esc(body.t)}</div>` : ''}
      ${!url && link ? linkCard(link) : ''}
      ${media(false)}
      <div class="pv-actions"><span>♡</span><span>💬</span><span>⟲</span><span>➤</span></div></div>`;
  } else {
    const name = ig ? ig.name.replace(/^@/, '') : 'vas_ucet';
    const cap = cut(compose(msg, link, tags), 125);
    html = (!url && !msg && !tags.length && !link) ? '<div class="pv-empty">Instagram vyžaduje obrázek nebo video.</div>' : `
      <div class="pv pv-ig"><div class="pv-head"><span class="pv-avatar"></span><strong>${esc(name)}</strong></div>
      ${url ? media(true) : '<div class="pv-empty bg-light">Chybí obrázek nebo video</div>'}
      <div class="pv-actions"><span>♡</span><span>💬</span><span>➤</span></div>
      <div class="pv-text pt-2"><strong>${esc(name)}</strong> ${esc(cap.t)}${cap.more ? ' <span class="pv-more">více</span>' : ''}</div></div>`;
  }
  $('#pv').innerHTML = html;
}
$$('[data-pv]').forEach(b => b.addEventListener('click', () => {
  pvNet = b.dataset.pv; $$('[data-pv]').forEach(x => x.classList.toggle('active', x === b)); renderPreview();
}));

/* ---------- společná aktualizace (počítadlo, varování, náhled) ---------- */
function igSelected() { return chosen().some(a => a.platform === 'instagram'); }
function thSelected() { return chosen().some(a => a.platform === 'threads'); }
function updateAll() {
  const text = compose(expandVars($('#message').value), $('#link').value.trim(), tags);
  const n = cpLen(text);
  $('#count').textContent = n;
  $('#count').parentElement.classList.toggle('text-danger', (igSelected() && n > 2200) || (thSelected() && n > 500));
  let warn = '';
  if (igSelected() && badRatio()) warn = 'Instagram přijímá poměr stran jen od 4:5 do 1,91:1 – obrázek ořízněte tlačítkem ✂.';
  if (igSelected() && tags.length > 30) warn = 'Instagram povoluje nejvýše 30 štítků.';
  if (thSelected() && n > 500) warn = `Threads povolují nejvýše 500 znaků (teď ${n}).`;
  $('#mediaWarn').textContent = warn;
  renderPreview();
}
['input', 'change'].forEach(ev => { $('#message').addEventListener(ev, updateAll); $('#link').addEventListener(ev, updateAll); });
$('#when').addEventListener('input', () => updateAll());
$('#repeat').addEventListener('change', (e) => { $('#untilWrap').hidden = e.target.value === 'none'; });

/* ---------- datum ---------- */
function toLocalInput(ts) { const d = new Date(ts * 1000); return `${ymd(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`; }
function defaultWhen() {
  const at = +params.get('at');
  if (at && at * 1000 > Date.now()) { $('#when').value = toLocalInput(at); return; }
  const param = params.get('date');
  const d = new Date(Date.now() + 60 * 60 * 1000);
  d.setMinutes(0, 0, 0);
  let day = ymd(d), time = `${pad(d.getHours())}:00`;
  if (param && /^\d{4}-\d{2}-\d{2}$/.test(param) && param > ymd(new Date())) { day = param; time = '10:00'; }
  else if (param && param === ymd(new Date())) { day = param; }
  $('#when').value = `${day}T${time}`;
}

/* ---------- doporučený čas (ze statistik) ---------- */
const BT_DOW = ['', 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];
let bestTimer = null, bestSeq = 0;
function bestSoon() { clearTimeout(bestTimer); bestTimer = setTimeout(() => loadBest().catch(() => { $('#bestHint').textContent = ''; }), 300); }
async function loadBest() {
  const seq = ++bestSeq;
  const r = await api('best_time&accounts=' + encodeURIComponent([...selected].join(',')));
  if (seq !== bestSeq) return;
  const el = $('#bestHint');
  if (!r.samples) { el.innerHTML = ''; return; }
  if (!r.enough) { el.innerHTML = `Doporučený čas: zatím málo dat (${r.samples} z ${r.need} změřených příspěvků).`; return; }
  if (!r.top.length) { el.innerHTML = 'Doporučený čas: u vybraných účtů žádný čas výrazně nevyčnívá.'; return; }
  el.innerHTML = 'Doporučeno' + (selected.size ? ' pro vybrané účty' : '') + ': ' + r.top.map(t =>
    `<a href="#" data-at="${t.next}" title="${t.lift > 0 ? '+' : ''}${t.lift} % oproti běžnému příspěvku, ${t.n}× vyšlo">${BT_DOW[t.d]} ${t.h}:00</a>`).join(' · ')
    + ' <a href="statistiky.php" class="text-muted ms-1" title="Podrobnosti ve Statistikách">ⓘ</a>';
}
$('#bestHint').addEventListener('click', (e) => {
  const a = e.target.closest('a[data-at]'); if (!a) return;
  e.preventDefault();
  $('#when').value = toLocalInput(+a.dataset.at);
  $('#when').dispatchEvent(new Event('input'));
});

/* ---------- odeslání ---------- */
function summary() {
  const names = chosen().map(a => a.name);
  return names.length > 10 ? names.slice(0, 10).join(', ') + ` a dalších ${names.length - 10}` : names.join(', ');
}
async function submit(now, draft = false) {
  const link = $('#link').value.trim();
  if (link && !/^https?:\/\/\S+$/i.test(link)) return flash('Odkaz musí začínat http:// nebo https://.', 'danger');
  if (draft) {
    if (!$('#message').value.trim() && !$('#link').value.trim() && !items.length && !tags.length) return flash('Koncept je prázdný.', 'danger');
  } else {
  if (!selected.size) return flash('Vyberte alespoň jeden účet.', 'danger');
  const hasMedia = items.length > 0;
  if (igSelected() && !hasMedia) return flash('Instagram vyžaduje obrázek nebo video.', 'danger');
  if (!hasMedia && !$('#message').value.trim() && !link) return flash('Napište text, přidejte odkaz nebo médium.', 'danger');
  if (igSelected() && cpLen(compose($('#message').value, link, tags)) > 2200) return flash('Text včetně odkazu a štítků je delší než 2 200 znaků (limit Instagramu).', 'danger');
  if (igSelected() && tags.length > 30) return flash('Instagram povoluje nejvýše 30 štítků.', 'danger');
  if (thSelected() && cpLen(compose($('#message').value, link, tags)) > 500) return flash('Text včetně odkazu a štítků je delší než 500 znaků (limit Threads).', 'danger');
  if (igSelected() && badRatio()) return flash('Poměr stran některého obrázku není pro Instagram povolený (4:5 až 1,91:1). Použijte ořez ✂.', 'danger');
  { const bad = knownUnknown($('#message').value); if (bad.length) return flash('Neznámá proměnná: ' + bad.join(', ') + '. Zkontrolujte zápis v nabídce Proměnné a data.', 'danger'); }
  if (now && !confirm(`Publikovat hned na ${selected.size} účtů?\n\n${summary()}`)) return;
  }

  const fd = new FormData();
  if (editId) fd.set('id', editId); else if (copyId) fd.set('copy_from', copyId);
  fd.set('message', $('#message').value);
  fd.set('link', link);
  fd.set('tags', tags.join(' '));
  fd.set('first_comment', $('#firstComment').value.trim());
  fd.set('kind', kindVal());
  const order = [];
  items.forEach(it => { if (it.name && !it.file) order.push('e:' + it.name); else { order.push('n:' + fd.getAll('media[]').length); fd.append('media[]', it.file, it.file.name || 'soubor'); } });
  fd.set('order', JSON.stringify(order));
  fd.set('scheduled_at', $('#when').value);
  fd.set('repeat', $('#repeat').value);
  if ($('#repeat').value !== 'none') fd.set('repeat_until', $('#until').value);
  selected.forEach(id => fd.append('accounts[]', id));
  if (now) fd.set('now', '1');
  if (draft) fd.set('draft', '1');

  const btns = [$('#btnSchedule'), $('#btnNow'), $('#btnDraft')];
  btns.forEach(b => b.disabled = true);
  flash(now ? 'Odesílám… (video může chvíli trvat)' : 'Ukládám…', 'info');
  try {
    await api(editId ? 'update' : 'create', fd);
    if (draft) { location.href = 'seznam.php?status=draft&saved=1'; return; }
    if (editId || copyId) { location.href = 'seznam.php?saved=1'; return; }
    flash(now ? 'Hotovo, výsledek je v seznamu příspěvků.' : `Příspěvek je naplánován na ${selected.size} účtů.`);
    resetForm();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (err) { flash(err.message, 'danger'); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  btns.forEach(b => b.disabled = false);
}
function resetForm() {
  $('#form').reset(); setKind('feed'); lm = null; lmFor = ''; $('#linkMeta').innerHTML = ''; clearAll(); tags.length = 0; renderTags(); renderMedia();
  $('#untilWrap').hidden = true; selected.clear(); defaultWhen(); renderAccounts(); updateAll();
}
$('#form').addEventListener('submit', (e) => { e.preventDefault(); submit(false); });
$('#btnNow').addEventListener('click', () => submit(true));
$('#btnDraft').addEventListener('click', () => submit(false, true));

/* ---------- typ publikace ---------- */
const KIND_HINT = {
  feed: '',
  reel: 'Reel: jedno svislé video (9:16), jen na Instagram. Facebook Reels aplikace zatím nepodporuje.',
  story: 'Story: jeden obrázek nebo video (9:16), zmizí po 24 hodinách. Text, štítky a odkaz se do story nepřenášejí. Na Facebooku jen obrázkové story.'
};
const kindVal = () => (document.querySelector('input[name=kind]:checked') || {}).value || 'feed';
function setKind(k) { const el = $('#k' + k[0].toUpperCase() + k.slice(1)); if (el) el.checked = true; $('#kindHint').textContent = KIND_HINT[kindVal()]; }
document.querySelectorAll('input[name=kind]').forEach(r => r.addEventListener('change', () => setKind(kindVal())));

/* ---------- skupiny účtů ---------- */
let groups = [];
function renderGroups() {
  const ok = (g) => g.account_ids.split(',').filter(id => accounts.some(a => String(a.id) === id));
  const list = groups.filter(g => ok(g).length);
  $('#grpBar').hidden = !list.length;
  $('#grpBar').innerHTML = list.length ? '<span class="small text-muted me-1">Skupiny:</span>' + list.map(g => `<button type="button" class="btn btn-sm btn-outline-secondary py-0 me-1 mb-1" data-grp="${g.id}" title="Přidat do výběru">${esc(g.name)} (${ok(g).length})</button>`).join('') + '<a class="small ms-1" href="skupiny.php">Spravovat</a>' : '';
}
$('#grpBar').addEventListener('click', (e) => {
  const b = e.target.closest('[data-grp]'); if (!b) return;
  const g = groups.find(x => String(x.id) === b.dataset.grp);
  g.account_ids.split(',').forEach(id => { if (accounts.some(a => String(a.id) === id)) selected.add(id); });
  renderAccounts(); selectionChanged();
});
async function loadGroups() { try { groups = (await api('groups')).groups; renderGroups(); } catch (e) { /* ignoruj */ } }

/* ---------- šablony ---------- */
let templates = [];
function renderTemplates(selId = '') {
  $('#tplSel').innerHTML = '<option value="">Šablona…</option>' + templates.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  $('#tplSel').value = selId;
}
async function loadTemplates(selId) { try { templates = (await api('templates')).templates; renderTemplates(selId); } catch (e) { /* ignoruj */ } }
$('#tplUse').onclick = () => {
  const t = templates.find(x => String(x.id) === $('#tplSel').value);
  if (!t) return flash('Vyberte šablonu.', 'warning');
  if ($('#message').value.trim() && !confirm('Přepsat text, odkaz, štítky a výběr účtů obsahem šablony?')) return;
  $('#message').value = t.message; $('#link').value = t.link || '';
  tags.length = 0; addTags(t.tags || '');
  selected.clear(); (t.account_ids || '').split(',').filter(Boolean).forEach(id => { if (accounts.some(a => String(a.id) === id)) selected.add(id); });
  renderAccounts(); selectionChanged(); loadLinkMeta(); updateAll();
  flash(`Použita šablona „${t.name}“.`, 'info');
};
$('#tplSave').onclick = async () => {
  const cur = templates.find(x => String(x.id) === $('#tplSel').value);
  const name = prompt('Název šablony:', cur ? cur.name : '');
  if (!name || !name.trim()) return;
  if (templates.some(t => t.name.toLowerCase() === name.trim().toLowerCase()) && !confirm('Šablona s tímto názvem už existuje. Přepsat?')) return;
  const fd = form({ name: name.trim(), message: $('#message').value, link: $('#link').value.trim(), tags: tags.join(' ') });
  selected.forEach(id => fd.append('accounts[]', id));
  try { await api('template_save', fd); await loadTemplates(); const t = templates.find(x => x.name.toLowerCase() === name.trim().toLowerCase()); if (t) $('#tplSel').value = t.id; flash('Šablona je uložena.'); }
  catch (err) { flash(err.message, 'danger'); }
};
$('#tplDel').onclick = async () => {
  const t = templates.find(x => String(x.id) === $('#tplSel').value);
  if (!t) return flash('Vyberte šablonu ke smazání.', 'warning');
  if (!confirm(`Smazat šablonu „${t.name}“?`)) return;
  try { await api('template_delete', form({ id: t.id })); await loadTemplates(); flash('Šablona je smazána.'); } catch (err) { flash(err.message, 'danger'); }
};


/* ---------- sady hashtagů ---------- */
let hSets = [];
async function loadHSets() {
  hSets = (await api('hashtag_sets')).sets;
  const cur = $('#hsSel').value;
  $('#hsSel').innerHTML = '<option value="">Vyberte uloženou sadu…</option>' + hSets.map(x => `<option value="${x.id}">${esc(x.name)} (${x.tags.split(' ').length})</option>`).join('');
  if (hSets.some(x => String(x.id) === cur)) $('#hsSel').value = cur;
}
$('#hsUse').onclick = () => {
  const x = hSets.find(h => String(h.id) === $('#hsSel').value);
  if (!x) return flash('Vyberte sadu hashtagů.', 'warning');
  const before = tags.length; addTags(x.tags);
  flash(`Přidáno ${tags.length - before} štítků ze sady „${x.name}“.`, 'info');
};
$('#hsSave').onclick = async () => {
  if (!tags.length) return flash('Nejdřív zadejte nějaké štítky.', 'warning');
  const cur = hSets.find(h => String(h.id) === $('#hsSel').value);
  const name = prompt('Název sady hashtagů:', cur ? cur.name : '');
  if (!name || !name.trim()) return;
  if (hSets.some(h => h.name.toLowerCase() === name.trim().toLowerCase()) && !confirm('Sada s tímto názvem už existuje. Přepsat?')) return;
  try { await api('hashtag_set_save', form({ name: name.trim(), tags: tags.join(' ') })); await loadHSets(); const x = hSets.find(h => h.name.toLowerCase() === name.trim().toLowerCase()); if (x) $('#hsSel').value = x.id; flash('Sada je uložena.'); }
  catch (err) { flash(err.message, 'danger'); }
};
$('#hsDel').onclick = async () => {
  const x = hSets.find(h => String(h.id) === $('#hsSel').value);
  if (!x) return flash('Vyberte sadu ke smazání.', 'warning');
  if (!confirm(`Smazat sadu „${x.name}“?`)) return;
  try { await api('hashtag_set_delete', form({ id: x.id })); await loadHSets(); flash('Sada je smazána.'); } catch (err) { flash(err.message, 'danger'); }
};

/* ---------- start ---------- */
async function start() {
  defaultWhen();
  try {
    accounts = (await api('accounts')).accounts.filter(a => +a.enabled);
    try { const v = await api('variables'); VARS = { user: v.variables, builtin: v.builtin }; } catch (e) { /* proměnné nejsou kritické */ }
    renderVarBar();
    if (editId || copyId) {
      const p = (await api('get&id=' + encodeURIComponent(editId || copyId))).post;
      if (editId && !['draft', 'scheduled', 'failed', 'partial'].includes(p.status)) { flash('Odeslaný příspěvek nelze upravit, použijte Duplikovat.', 'danger'); setTimeout(() => location.href = 'seznam.php', 1800); return; }
      if (editId && p.status === 'draft') {
        $('#pageTitle').textContent = 'Upravit koncept'; document.title = 'Upravit koncept | Plánovač příspěvků';
        $('#btnCancel').hidden = false;
      } else if (editId) {
        $('#pageTitle').textContent = 'Upravit příspěvek'; document.title = 'Upravit příspěvek | Plánovač příspěvků';
        $('#btnSchedule').textContent = 'Uložit změny'; $('#btnDraft').hidden = true; $('#btnCancel').hidden = false;
        if (p.status !== 'scheduled') flash('Příspěvek se po uložení znovu naplánuje. Účty, kam už byl odeslán, se nezopakují.', 'info');
      } else {
        $('#pageTitle').textContent = 'Duplikovat příspěvek'; document.title = 'Duplikovat příspěvek | Plánovač příspěvků';
        $('#btnCancel').hidden = false;
      }
      $('#message').value = p.message || ''; $('#link').value = p.link || ''; $('#firstComment').value = p.first_comment || ''; setKind(p.kind || 'feed');
      addTags(p.tags || '');
      if (editId) {
        if (p.status !== 'draft' || p.scheduled_at * 1000 > Date.now()) $('#when').value = toLocalInput(p.scheduled_at);
        $('#repeat').value = p.repeat_mode || 'none'; $('#untilWrap').hidden = $('#repeat').value === 'none';
        if (p.repeat_until) $('#until').value = ymd(new Date(p.repeat_until * 1000));
      }
      const enabledIds = new Set(accounts.map(a => String(a.id)));
      const lost = p.account_ids.filter(i => !enabledIds.has(String(i)));
      p.account_ids.forEach(i => { if (enabledIds.has(String(i))) selected.add(String(i)); });
      if (lost.length) flash(`${lost.length} původních účtů je zakázáno v nastavení a nebyly vybrány.`, 'warning');
      items = (p.media || []).map(m => ({ type: m.type, file: null, name: m.file, url: m.url, ratio: null, orig: null }));
      await Promise.all(items.filter(i => i.type === 'image').map(async i => { try { const im = await loadImage(i.url); i.ratio = im.naturalWidth / im.naturalHeight; i.orig = im; } catch (e) { /* ignoruj */ } }));
      renderMedia();
      loadLinkMeta();
    }
  } catch (e) { $('#accList').innerHTML = `<div class="p-3 text-danger">${esc(e.message)}</div>`; }
  renderAccounts(); updateAll(); renderGroups(); bestSoon();
}
start();
loadGroups();
loadTemplates();
loadHSets().catch(() => {});
icons();

// autor a licence u souborů upravovaného příspěvku
loadLibMeta().then(() => updateCreditHint()).catch(() => {});
