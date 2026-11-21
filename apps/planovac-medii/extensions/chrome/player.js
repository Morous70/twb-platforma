// Přehrávač: náhodné pořadí bez opakování, obrázky/PDF/texty/HTML dokumenty na nastavenou dobu, videa celá se zvukem, odkazy se přeskočí.
// Nová sestava (po obnovení seznamu) se projeví od dalšího média.
// Každých 15 s hlásí stav do aplikace a plní příkazy z ní (Obnovit teď, Restartovat přehrávač, Další soubor).
import { getState, cachedBlob, playable, esc, ping, VERSION } from './common.js';

const $ = (id) => document.getElementById(id);
const stage = $('stage');
if (window.pdfjsLib) pdfjsLib.GlobalWorkerOptions.workerSrc = 'lib/pdf.worker.min.js';
try { chrome.power.requestKeepAwake('display'); } catch (e) { /* bez oprávnění power */ }

let state = null;          // poslední stav z úložiště
let queue = [];            // klíče, které se v tomto kole ještě nehrály (v náhodném pořadí)
let played = new Set();    // klíče, které se v tomto kole už hrály
let lastKey = null;
let cur = null;            // {file, el, url, timer, start, remaining, video}
let paused = false;
let soundOk = false;       // uživatel povolil zvuk kliknutím
let waitTimer = null;

const files = () => (state?.files || []).filter(playable);
const byKey = () => new Map(files().map((f) => [f.key, f]));
const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; };

/** Sladí frontu s aktuálním seznamem: odebrané pryč, nové náhodně vložit. */
function reconcile() {
  const keys = new Set(files().map((f) => f.key));
  queue = queue.filter((k) => keys.has(k));
  played = new Set([...played].filter((k) => keys.has(k)));
  for (const k of keys) {
    if (queue.includes(k) || played.has(k)) continue;
    queue.splice(Math.floor(Math.random() * (queue.length + 1)), 0, k);
  }
}
function pickKey() {
  reconcile();
  if (!queue.length) {
    played = new Set();
    queue = shuffle(files().map((f) => f.key));
    if (queue.length > 1 && queue[0] === lastKey) [queue[0], queue[1]] = [queue[1], queue[0]];
  }
  const k = queue.shift();
  if (k) played.add(k);
  return k;
}

function message(html) {
  $('msg').innerHTML = html; $('msg').hidden = !html;
  const b = $('msg').querySelector('[data-opts]');
  if (b) b.onclick = () => chrome.runtime.openOptionsPage();
}

let loading = false;
async function next() {
  if (loading) return;
  loading = true;
  try { await nextInner(); } finally { loading = false; }
}
async function nextInner() {
  clearTimeout(waitTimer);
  if (!state) state = await getState();
  const list = files();
  if (!list.length) {
    stop();
    message(state?.notConfigured
      ? '<div><b>Plugin není nastavený</b>Zadejte adresu aplikace a klíč zařízení.<br><button class="ib" data-opts>Otevřít nastavení</button></div>'
      : !state?.ok && !state?.files ? `<div><b>Seznam se nepodařilo načíst</b>${esc(state?.error || '')}</div>`
      : '<div><b>Ve složce přehrávače teď nic není</b>Zkusím to znovu po obnovení seznamu.</div>');
    waitTimer = setTimeout(next, 5000);
    return;
  }
  // zkusit média v pořadí; nestažená přeskočit
  for (let tries = 0; tries < list.length; tries++) {
    const key = pickKey();
    const f = byKey().get(key);
    if (!f) continue;
    const blob = await cachedBlob(key);
    if (!blob) continue;
    message('');
    try { await show(f, blob); return; } catch (e) { console.warn('Médium nejde zobrazit', f.name, e); playError(f, e); }
  }
  stop();
  message(`<div><b>Stahuji média…</b>${state?.done ?? 0} z ${state?.total ?? list.length}</div>`);
  waitTimer = setTimeout(next, 3000);
}

function stop() {
  if (!cur) return;
  clearTimeout(cur.timer);
  const old = cur; cur = null;
  if (old.video) old.video.pause();
  old.el.classList.remove('on');
  setTimeout(() => { old.el.remove(); URL.revokeObjectURL(old.url); }, 600);
}

async function show(f, blob) {
  const url = URL.createObjectURL(blob);
  const dur = (state?.device?.duration || 20) * 1000;
  let el, video = null;
  if (f.kind === 'image') {
    el = new Image(); el.src = url; await el.decode();
  } else if (f.kind === 'video') {
    el = video = document.createElement('video');
    el.src = url; el.playsInline = true;
    el.onended = () => next();
    el.onerror = () => { playError(f, new Error('video nejde přehrát')); next(); };
  } else if (f.kind === 'pdf') {
    el = await renderPdf(blob);
  } else if (f.kind === 'text') {
    el = document.createElement('div'); el.className = 'text';
    const t = document.createElement('div'); t.textContent = (await blob.text()).trim(); el.append(t);
  } else if (f.kind === 'html') {
    el = await htmlEl(await blob.text());
  } else {
    URL.revokeObjectURL(url); throw new Error('nepodporovaný typ');
  }
  if (el.parentNode !== stage) stage.append(el); // HTML rám už na ploše je – přesunutí by ho načetlo znovu
  if (f.kind === 'text') fitText(el.firstChild);
  stop();
  cur = { file: f, el, url, video, timer: null, start: 0, remaining: dur, total: dur, at: Date.now() };
  lastKey = f.key;
  requestAnimationFrame(() => el.classList.add('on'));
  $('name').textContent = `${f.name} · ${files().findIndex((x) => x.key === f.key) + 1}/${files().length}`;
  $('info').textContent = { image: 'obrázek', video: 'video', pdf: 'PDF – první stránka', text: 'text', html: 'HTML dokument' }[f.kind] + (video ? '' : ` · ${dur / 1000} s`);
  chrome.storage.local.set({ nowPlaying: { key: f.key, name: f.name, t: Date.now() } });
  if (video) {
    video.muted = false;
    try { await video.play(); $('sound').hidden = true; } catch (e) {
      // Chrome nepustil zvuk bez kliknutí: přehrát ztlumeně a nabídnout zapnutí zvuku
      video.muted = true; $('sound').hidden = false;
      await video.play();   // ani ztlumeně nejde → chyba, přehrávač zkusí další médium
    }
    if (paused) video.pause();
  } else if (!paused) {
    startTimer();
  }
}

/**
 * HTML dokument: izolovaná stránka frame.html (sandbox v manifestu – bez přístupu k pluginu a klíči) dostane dokument
 * zprávou a vykreslí ho v ploše 1920×1080, která se zmenší na obrazovku.
 */
async function htmlEl(html) {
  const box = document.createElement('div');
  box.className = 'html';
  const fr = document.createElement('iframe');
  fr.width = 1920; fr.height = 1080; fr.tabIndex = -1;
  fr.src = 'frame.html';
  box.append(fr);
  let onMsg;
  const ready = new Promise((res, rej) => {
    onMsg = (e) => { if (e.source === fr.contentWindow && e.data?.twbFrameReady) res(); };
    addEventListener('message', onMsg);
    setTimeout(() => rej(new Error('HTML dokument se nenačetl')), 10000);
  });
  stage.append(box);
  try { await ready; } catch (e) { box.remove(); throw e; } finally { removeEventListener('message', onMsg); }
  fr.contentWindow.postMessage({ html }, '*');
  fitHtml(box);
  return box;
}
function fitHtml(box) {
  const k = Math.min(innerWidth / 1920, innerHeight / 1080);
  box.firstChild.style.transform = `translate(-50%, -50%) scale(${k})`;
}
addEventListener('resize', () => document.querySelectorAll('#stage .html').forEach(fitHtml));

function startTimer() {
  if (!cur || cur.video) return;
  cur.start = performance.now();
  cur.timer = setTimeout(next, cur.remaining);
}

async function renderPdf(blob) {
  if (!window.pdfjsLib) throw new Error('pdf.js chybí');
  const pdf = await pdfjsLib.getDocument({ data: await blob.arrayBuffer(), isEvalSupported: false }).promise;
  const page = await pdf.getPage(1);
  const vp1 = page.getViewport({ scale: 1 });
  const dpr = window.devicePixelRatio || 1;
  const scale = Math.min(innerWidth / vp1.width, innerHeight / vp1.height);
  const vp = page.getViewport({ scale: scale * dpr });
  const c = document.createElement('canvas');
  c.width = Math.floor(vp.width); c.height = Math.floor(vp.height);
  c.style.width = Math.floor(vp.width / dpr) + 'px'; c.style.height = Math.floor(vp.height / dpr) + 'px';
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
  await page.render({ canvasContext: ctx, viewport: vp }).promise;
  pdf.destroy();
  return c;
}

/** Největší písmo, při kterém se text vejde do 90 % šířky a 80 % výšky okna.
 *  Nejdřív bez zalamování řádků; vyšlo by drobné písmo, tak se zalamovat smí. */
function fitText(t) {
  const fit = (wrap) => {
    t.style.whiteSpace = wrap ? 'pre-wrap' : 'pre';
    let lo = 12, hi = 240;
    while (hi - lo > 1) {
      const mid = (lo + hi) >> 1;
      t.style.fontSize = mid + 'px';
      if (t.scrollWidth <= innerWidth * 0.9 && t.scrollHeight <= innerHeight * 0.8) lo = mid; else hi = mid;
    }
    t.style.fontSize = lo + 'px';
    return lo;
  };
  if (fit(false) < 48) fit(true);
}

function togglePause() {
  paused = !paused;
  $('pause').textContent = paused ? '▶' : '❚❚';
  if (!cur) return;
  if (cur.video) { paused ? cur.video.pause() : cur.video.play().catch(() => {}); return; }
  if (paused) { clearTimeout(cur.timer); cur.remaining -= performance.now() - cur.start; }
  else startTimer();
}

// ukazatel průběhu
(function tick() {
  let p = 0;
  if (cur?.video && cur.video.duration) p = cur.video.currentTime / cur.video.duration;
  else if (cur && !cur.video) p = 1 - (paused ? cur.remaining : cur.remaining - (performance.now() - cur.start)) / cur.total;
  $('prog').style.width = Math.max(0, Math.min(1, p)) * 100 + '%';
  requestAnimationFrame(tick);
})();

// ovládací pruh se schová po 3 s bez pohybu myši
let idleT;
function wake() { document.body.classList.remove('idle'); clearTimeout(idleT); idleT = setTimeout(() => document.body.classList.add('idle'), 3000); }
addEventListener('mousemove', wake); wake();

const fullscreen = () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()).catch(() => {});
$('fs').onclick = fullscreen;
stage.ondblclick = fullscreen;
$('pause').onclick = togglePause;
$('next').onclick = () => next();
$('refresh').onclick = async () => {
  $('refresh').disabled = true; $('refresh').textContent = '⟳ Obnovuji…';
  try { await chrome.runtime.sendMessage({ type: 'refresh' }); } finally { $('refresh').disabled = false; $('refresh').textContent = '⟳ Obnovit'; }
};
$('sound').onclick = () => { soundOk = true; $('sound').hidden = true; if (cur?.video) cur.video.muted = false; };
addEventListener('click', () => { soundOk = true; }, { capture: true });
addEventListener('keydown', (e) => {
  if (e.key === ' ') { e.preventDefault(); togglePause(); }
  else if (e.key === 'ArrowRight') next();
  else if (e.key === 'f' || e.key === 'F') fullscreen();
  else if (e.key === 'r' || e.key === 'R') $('refresh').click();
  wake();
});

chrome.storage.onChanged.addListener((ch) => {
  if (!ch.state) return;
  state = ch.state.newValue;
  // nic nehraje (prázdno, čeká na stažení) → zkusit hned
  if (!cur) next();
});

// ---------- hlášení stavu a příkazy z aplikace ----------
let playErr = null;          // {msg, at} poslední chyba přehrání (zmizí po 10 minutách bez další chyby)
let lastAuto = 0;            // kdy se naposledy samo obnovilo po změně složky
function playError(f, e) { playErr = { msg: `Soubor nejde přehrát – ${f.name}: ${e?.message || e}`, at: Date.now() }; }
function currentError() {
  if (state && state.ok === false && state.error) return { msg: state.error, at: Date.parse(state.errorAt) || Date.now() };
  if (state?.failed && state.failMsg) return { msg: state.failMsg, at: Date.parse(state.failAt) || Date.now() };
  if (playErr && Date.now() - playErr.at < 10 * 60000) return playErr;
  return null;
}
function statusReport() {
  const e = currentError();
  return {
    version: VERSION, files: state?.total ?? 0, done: state?.done ?? 0,
    playing: cur ? { name: cur.file.name, kind: cur.file.kind, age: Math.round((Date.now() - cur.at) / 1000), paused } : null,
    error: e ? { msg: e.msg, age: Math.round((Date.now() - e.at) / 1000) } : null,
  };
}
const refreshList = () => chrome.runtime.sendMessage({ type: 'refresh' }).catch(() => {});
let doneCmd = null;
async function pingOnce() {
  let r;
  try { r = await ping(statusReport()); } catch (e) { return null; }
  if (!r) return null;
  if (r.cmd && r.cmd.id !== doneCmd) {
    doneCmd = r.cmd.id;
    if (r.cmd.name === 'refresh') refreshList();
    else if (r.cmd.name === 'next') next();
    else if (r.cmd.name === 'restart') { setTimeout(() => location.reload(), 300); return r; }
  } else if (r.change && state?.ok && r.change > (state.change || 0) && Date.now() - lastAuto > 30000) {
    // obsah složky přehrávače se změnil → obnovit seznam hned, ne až za 5 minut
    lastAuto = Date.now(); refreshList();
  }
  return r;
}
let pingTimer = null;
function schedulePing(sec) { clearTimeout(pingTimer); pingTimer = setTimeout(async () => { const r = await pingOnce(); schedulePing(r?.ping_seconds || 15); }, sec * 1000); }
window.twbPing = pingOnce;   // pro testy

state = await getState();
next();
schedulePing(2);
// po otevření přehrávače obnovit seznam (projeví se od dalšího média)
chrome.runtime.sendMessage({ type: 'refresh' }).catch(() => {});
