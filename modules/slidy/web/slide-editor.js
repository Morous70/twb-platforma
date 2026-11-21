// ================= Editor slidů (od 1.6.0, převzato z TWB Plánovače médií) =================
// Vykreslování na plátně používá stejný postup jako server (app/lib/slide.php), aby náhled odpovídal výsledku.
// Oproti Plánovači má slide formát plátna (SW × SH podle d.format). Pomocné funkce dodává slide-shim.js.
let SW = 1080, SH = 1080;
/** Nové prvky mají výchozí polohy pro plátno 1920 × 1080 → přepočet na aktuální formát. */
function sc(e) {
  const kx = SW / 1920, ky = SH / 1080, k = Math.min(kx, ky), r = Math.round;
  e.x = r(e.x * kx); e.w = r(e.w * kx); e.y = r(e.y * ky); e.h = r(e.h * ky);
  if (e.size) e.size = r(e.size * k);
  if (e.gap != null) e.gap = r(e.gap * k);
  if (e.extraGap != null) e.extraGap = r(e.extraGap * k);
  if (e.bg && e.bg.padding != null) { e.bg.padding = r(e.bg.padding * k); e.bg.radius = r(e.bg.radius * k); }
  return e;
}
/** Změna formátu: prvky se poměrně přesunou a zmenší / zvětší. */
function rescaleDesign(d, W, H) {
  const kx = W / SW, ky = H / SH, k = Math.min(kx, ky), r = Math.round;
  for (const e of d.elements) {
    e.x = r(e.x * kx); e.w = r(e.w * kx); e.y = r(e.y * ky); e.h = r(e.h * ky);
    if (e.size) e.size = Math.max(8, r(e.size * k));
    if (e.gap != null) e.gap = r(e.gap * k);
    if (e.extraGap != null) e.extraGap = r(e.extraGap * k);
    if (e.bg && e.bg.padding != null) { e.bg.padding = r(e.bg.padding * k); e.bg.radius = r(e.bg.radius * k); }
    if (e.shadow && e.shadow.dx != null) { e.shadow.dx = r(e.shadow.dx * k); e.shadow.dy = r(e.shadow.dy * k); }
    if (e.outline && e.outline.width) e.outline.width = Math.max(1, r(e.outline.width * k));
  }
}
let FONT_LIST = null;
const fontLoads = new Map();
const imgCache = new Map();

async function loadFontList() {
  FONT_LIST ??= await api('fonts');
  return FONT_LIST;
}
// po nahrání nebo přejmenování písma v Nastavení načíst seznam znovu
window.FONT_LIST_RESET = () => { FONT_LIST = null; };
const fontDef = (key) => FONT_LIST.find((f) => f.key === key) || FONT_LIST[0];
function nearestWeight(key, w) {
  return fontDef(key).weights.reduce((best, x) => (Math.abs(x - w) < Math.abs(best - w) ? x : best), fontDef(key).weights[0]);
}
function ensureFont(key, weight) {
  const f = fontDef(key), w = nearestWeight(key, weight), id = `${f.key}-${w}`;
  if (!fontLoads.has(id)) {
    const face = new FontFace(`mp-${f.key}`, `url(${f.files[w]})`, { weight: String(w) });
    fontLoads.set(id, face.load().then((ff) => { document.fonts.add(ff); return true; }).catch(() => false));
  }
  return fontLoads.get(id);
}
function loadImg(mediaId) {
  const m = mediaById(mediaId);
  if (!m?.url) return null;
  if (!imgCache.has(m.url)) {
    const im = new Image();
    im.src = m.url;
    imgCache.set(m.url, im);
  }
  const im = imgCache.get(m.url);
  return im.complete && im.naturalWidth ? im : null;
}

// ---------- vykreslení (zrcadlo lib/slide.php) ----------
function cssFont(e, size) { return `${nearestWeight(e.font, e.weight)} ${size}px "mp-${e.font}"`; }
function textW(ctx, s, sp = 0) {
  if (!s) return 0;
  if (sp) {
    // s rozestupem písmen: součet šířek znaků + rozestupy (stejně jako text_width v lib/slide.php)
    const ch = [...s];
    return ch.reduce((w, c) => w + charAdv(ctx, c), 0) + sp * (ch.length - 1);
  }
  const m = ctx.measureText(s);
  return m.actualBoundingBoxLeft + m.actualBoundingBoxRight;
}
/** Šířka znaku i s okraji: rozdíl šířek „HxH“ a „HH“ (funguje i pro mezeru) – stejně jako char_adv na serveru. */
const advCache = new Map();
function charAdv(ctx, c) {
  const k = ctx.font + '|' + c;
  if (!advCache.has(k)) advCache.set(k, textW(ctx, `H${c}H`) - textW(ctx, 'HH'));
  return advCache.get(k);
}
/** Napíše řádek (fillText/strokeText); s rozestupem po znacích. targetW = šířka ze serveru (dorovnání). */
function drawStr(ctx, method, text, x, y, sp, targetW) {
  if (!sp) { ctx[method](text, x, y); return; }
  const ch = [...text];
  const sum = ch.reduce((w, c) => w + charAdv(ctx, c), 0);
  const k = targetW && sum > 0 ? Math.max(0.8, Math.min(1.2, (targetW - sp * (ch.length - 1)) / sum)) : 1;
  for (const c of ch) { if (c !== ' ') ctx[method](c, x, y); x += charAdv(ctx, c) * k + sp; }
}
function wrapLines(ctx, text, maxW, sp = 0) {
  const lines = [];
  for (const para of text.split(/\r?\n/)) {
    let line = '';
    for (const word of para.split(/ +/)) {
      const tryL = line === '' ? word : `${line} ${word}`;
      if (line !== '' && textW(ctx, tryL, sp) > maxW) { lines.push(line); line = word; } else line = tryL;
      while (textW(ctx, line, sp) > maxW && [...line].length > 1) {
        const ch = [...line];
        let cut = ch.length - 1;
        while (cut > 1 && textW(ctx, ch.slice(0, cut).join(''), sp) > maxW) cut--;
        lines.push(ch.slice(0, cut).join(''));
        line = ch.slice(cut).join('');
      }
    }
    lines.push(line);
  }
  return lines;
}
const EMOJI_RE = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu;
function layoutText(ctx, e, text) {
  text = text.replace(EMOJI_RE, '');
  const pad = e.bg.on ? e.bg.padding : 0;
  const ix = e.x + pad, iy = e.y + pad, iw = Math.max(1, e.w - 2 * pad), ih = Math.max(1, e.h - 2 * pad);
  if (e.upper) text = text.toLocaleUpperCase('cs');
  let size = e.size;
  const spEm = e.spacing || 0;
  ctx.font = cssFont(e, size);
  let lines = wrapLines(ctx, text, iw, spEm * size);
  if (e.autoFit) {
    while (size > 8 && lines.length * size * e.lineHeight > ih) {
      size = Math.max(8, Math.floor(size * 0.94));
      ctx.font = cssFont(e, size);
      lines = wrapLines(ctx, text, iw, spEm * size);
    }
  }
  const lh = size * e.lineHeight, blockH = lines.length * lh;
  const top = e.valign === 'middle' ? iy + (ih - blockH) / 2 : e.valign === 'bottom' ? iy + ih - blockH : iy;
  return { size, lines: lines.map((t, i) => {
    const w = textW(ctx, t, spEm * size);
    const x = e.align === 'center' ? ix + (iw - w) / 2 : e.align === 'right' ? ix + iw - w : ix;
    return { text: t, x, w, y: top + i * lh + (lh - size) / 2 + size * 0.8 };
  }), overflow: blockH > ih + 0.5 };
}
const rgba = (hex, a = 1) => `rgba(${parseInt(hex.slice(1, 3), 16)},${parseInt(hex.slice(3, 5), 16)},${parseInt(hex.slice(5, 7), 16)},${a})`;
function rrectPath(ctx, x, y, w, h, r) {
  r = Math.max(0, Math.min(r, w / 2, h / 2));
  ctx.beginPath();
  ctx.roundRect ? ctx.roundRect(x, y, w, h, r) : ctx.rect(x, y, w, h);
}
function drawFit(ctx, im, x, y, w, h, fit, alpha = 1) {
  const sw = im.naturalWidth, sh = im.naturalHeight;
  ctx.save();
  ctx.globalAlpha = alpha;
  if (fit === 'cover') {
    const s = Math.max(w / sw, h / sh), cw = w / s, ch = h / s;
    ctx.drawImage(im, (sw - cw) / 2, (sh - ch) / 2, cw, ch, x, y, w, h);
  } else {
    const s = Math.min(w / sw, h / sh), dw = sw * s, dh = sh * s;
    ctx.drawImage(im, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
  }
  ctx.restore();
}
/** Podpis prvku pro rozvržení – když se shoduje, platí serverové rozvržení. */
const layoutSig = (e) => JSON.stringify([e.x, e.y, e.w, e.h, e.text, e.font, e.weight, e.size, e.align, e.valign, e.lineHeight, e.upper, e.autoFit, e.bg.on, e.bg.padding, e.variants, e.showIf, e.spacing || 0]);
function drawText(ctx, e, text, srv) {
  if (e.bg.on) { ctx.fillStyle = rgba(e.bg.color, e.bg.alpha * e.alpha); rrectPath(ctx, e.x, e.y, e.w, e.h, e.bg.radius); ctx.fill(); }
  const useSrv = srv && srv.sig === layoutSig(e);
  const L = useSrv ? srv.layout : layoutText(ctx, e, text);
  ctx.font = cssFont(e, L.size);
  ctx.textBaseline = 'alphabetic';
  const sp = (e.spacing || 0) * L.size;
  if (e.fx) { drawTextFx(ctx, e, L, sp, useSrv); return L; }
  for (const ln of L.lines) {
    if (!ln.text) continue;
    const x = Math.round(ln.x), y = Math.round(ln.y);
    // šířku řádku dorovnáme mezerami mezi písmeny, aby odpovídala serveru (jiné vyhlazování písma)
    ctx.letterSpacing = '0px';
    if (!sp && useSrv && 'letterSpacing' in ctx && [...ln.text].length > 1) {
      const diff = ln.w - textW(ctx, ln.text);
      ctx.letterSpacing = `${diff / ([...ln.text].length - 1)}px`;
    }
    const tw = useSrv ? ln.w : 0;
    if (e.shadow.on) { ctx.fillStyle = rgba(e.shadow.color, e.shadow.alpha * e.alpha); drawStr(ctx, 'fillText', ln.text, x + e.shadow.dx, y + e.shadow.dy, sp, tw); }
    if (e.outline.on) {
      ctx.strokeStyle = rgba(e.outline.color, e.alpha); ctx.lineWidth = e.outline.width * 2; ctx.lineJoin = 'round';
      drawStr(ctx, 'strokeText', ln.text, x, y, sp, tw);
    }
    ctx.fillStyle = rgba(e.color, e.alpha);
    drawStr(ctx, 'fillText', ln.text, x, y, sp, tw);
  }
  ctx.letterSpacing = '0px';
  return L;
}

// ---------- grafický text (zrcadlo draw_text_fx v lib/slide.php) ----------
let FX = { styles: {}, stops: [0, 0.38, 0.62, 1], ranges: {} };
const mixHex = (a, b, t) => { const A = hexRgb(a), B = hexRgb(b); return `rgb(${A.map((v, i) => Math.round(v + (B[i] - v) * t)).join(',')})`; };
function drawTextFx(ctx, e, L, sp, useSrv) {
  const fx = e.fx, size = L.size, al = e.alpha;
  const lines = L.lines.filter((ln) => ln.text);
  const sw = fx.stroke > 0 ? Math.max(1, size * fx.stroke) : 0, sw2 = fx.stroke2 > 0 ? Math.max(1, size * fx.stroke2) : 0;
  const depth = Math.round(size * fx.depth), glowR = size * 0.06;
  const shX = Math.round(size * 0.03), shY = depth + Math.round(size * 0.05);
  ctx.save();
  ctx.lineJoin = 'round'; ctx.lineCap = 'round';
  // „ztučněný“ text: obrys šířky 2r a výplň stejnou barvou
  const fat = (color, r, dx = 0, dy = 0) => {
    ctx.fillStyle = ctx.strokeStyle = color;
    for (const ln of lines) {
      const x = Math.round(ln.x) + dx, y = Math.round(ln.y) + dy, tw = useSrv ? ln.w : 0;
      if (r > 0) { ctx.lineWidth = 2 * r; drawStr(ctx, 'strokeText', ln.text, x, y, sp, tw); }
      drawStr(ctx, 'fillText', ln.text, x, y, sp, tw);
    }
  };
  if (fx.glow > 0) { ctx.save(); ctx.filter = `blur(${glowR * 0.9}px)`; ctx.globalAlpha = Math.min(1, fx.glow * al); fat(fx.glowColor, sw + glowR, 0, depth / 2); ctx.restore(); }
  if (fx.shadow > 0) { ctx.save(); ctx.filter = `blur(${size * 0.035}px)`; ctx.globalAlpha = Math.min(1, fx.shadow * al); fat('#000000', sw, shX, shY); ctx.restore(); }
  ctx.globalAlpha = al;
  const dark = mixHex(fx.depthColor, '#000000', 0.55).replace(/rgb\((\d+),(\d+),(\d+)\)/, (m, r, g, b) => '#' + [r, g, b].map((v) => (+v).toString(16).padStart(2, '0')).join(''));
  for (let k = depth; k >= 1; k--) fat(mixHex(fx.depthColor, dark, k / Math.max(1, depth)), sw, 0, k);
  if (sw > 0) fat(fx.strokeColor, sw);
  if (sw2 > 0) fat(fx.stroke2Color, sw2);
  for (const ln of lines) {
    const x = Math.round(ln.x), y = Math.round(ln.y), tw = useSrv ? ln.w : 0, top = y - size * 0.72;
    const g = ctx.createLinearGradient(0, top, 0, y);
    FX.stops.forEach((p, i) => g.addColorStop(p, fx.grad[i]));
    ctx.fillStyle = g; drawStr(ctx, 'fillText', ln.text, x, y, sp, tw);
    if (fx.gloss > 0) {
      const g2 = ctx.createLinearGradient(0, top, 0, y);
      g2.addColorStop(0, `rgba(255,255,245,${fx.gloss})`); g2.addColorStop(0.479, `rgba(255,255,245,${fx.gloss * 0.25})`); g2.addColorStop(0.48, 'rgba(255,255,245,0)');
      ctx.fillStyle = g2; drawStr(ctx, 'fillText', ln.text, x, y, sp, tw);
    }
  }
  ctx.restore();
}
// ---------- kuličky (zrcadlo layout_balls / gd_ball v lib/slide.php) ----------
function layoutBalls(e, nums, extras) {
  const items = [...nums.map((n) => [String(n), false]), ...extras.map((n) => [String(n), true])];
  let d = e.size, rows, g, totalH;
  for (;;) {
    g = e.gap * d / e.size;
    const eg = e.extraGap * d / e.size;
    rows = []; let row = [], rw = 0, prevExtra = null, maxW = 0;
    for (const [n, ex] of items) {
      let sp = !row.length ? 0 : (ex && prevExtra === false ? eg : g);
      if (row.length && rw + sp + d > e.w + 0.01) { rows.push([row, rw]); maxW = Math.max(maxW, rw); row = []; rw = 0; sp = 0; }
      row.push([n, ex, rw + sp]); rw += sp + d; prevExtra = ex;
    }
    if (row.length) { rows.push([row, rw]); maxW = Math.max(maxW, rw); }
    totalH = rows.length * d + Math.max(0, rows.length - 1) * g;
    if ((totalH <= e.h + 0.01 && maxW <= e.w + 0.01) || d <= 12) break;
    d = Math.max(12, Math.floor(d * 0.94));
  }
  const y0 = e.valign === 'top' ? e.y : e.valign === 'bottom' ? e.y + e.h - totalH : e.y + (e.h - totalH) / 2;
  const balls = [];
  rows.forEach(([r, w], ri) => {
    const x0 = e.align === 'left' ? e.x : e.align === 'right' ? e.x + e.w - w : e.x + (e.w - w) / 2;
    for (const [n, ex, off] of r) balls.push({ x: x0 + off + d / 2, y: y0 + ri * (d + g) + d / 2, n, extra: ex });
  });
  return { d, balls };
}
const LIGHT = (() => {
  const nrm = (v) => { const n = Math.hypot(...v); return v.map((x) => x / n); };
  const L = nrm([-0.5, -0.65, 0.58]);
  return { L, H: nrm([L[0], L[1], L[2] + 1]) };
})();
const hexRgb = (h) => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];
const ballCache = new Map();
/** Kulička jako obrázek – po pixelech stejně jako na serveru. Vrací [canvas, posun]. */
function ballSprite(r, fillHex, glossy, stroke) {
  const key = [r.toFixed(2), fillHex, glossy, stroke.on, stroke.color, stroke.width].join('|');
  if (ballCache.has(key)) return ballCache.get(key);
  const size = Math.ceil(2 * r) + 4, c = document.createElement('canvas');
  c.width = c.height = size;
  const cx = size / 2, cy = size / 2, x2 = c.getContext('2d'), img = x2.createImageData(size, size);
  const fill = hexRgb(fillHex), st = hexRgb(stroke.color), sw = stroke.on ? Math.min(stroke.width, r) : 0;
  for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
    const dx = x + 0.5 - cx, dy = y + 0.5 - cy, dist = Math.hypot(dx, dy);
    const cov = Math.max(0, Math.min(1, r - dist + 0.5));
    if (cov <= 0) continue;
    let col = fill;
    if (glossy) {
      const nx = dx / r, ny = dy / r, nz = Math.sqrt(1 - Math.min(1, nx * nx + ny * ny));
      const diff = Math.max(0, nx * LIGHT.L[0] + ny * LIGHT.L[1] + nz * LIGHT.L[2]);
      const spec = Math.max(0, nx * LIGHT.H[0] + ny * LIGHT.H[1] + nz * LIGHT.H[2]) ** 40, k = 0.38 + 0.72 * diff;
      col = fill.map((v) => Math.min(255, v * k + 178 * spec));
    }
    if (sw > 0) { const inn = Math.max(0, Math.min(1, (r - sw) - dist + 0.5)); col = col.map((v, i) => st[i] + (v - st[i]) * inn); }
    const o = (y * size + x) * 4;
    img.data[o] = col[0]; img.data[o + 1] = col[1]; img.data[o + 2] = col[2]; img.data[o + 3] = Math.round(cov * 255);
  }
  x2.putImageData(img, 0, 0);
  if (ballCache.size > 200) ballCache.clear();
  const res = [c, size / 2];
  ballCache.set(key, res);
  return res;
}
function drawBalls(ctx, e, r) {
  const L = layoutBalls(e, r?.nums || [], r?.extras || []);
  ctx.save();
  ctx.globalAlpha = e.alpha;
  ctx.font = cssFont(e, L.d * e.numScale);
  ctx.textBaseline = 'alphabetic';
  for (const b of L.balls) {
    const [sp, half] = ballSprite(L.d / 2, b.extra ? e.extraFill : e.fill, e.style === 'glossy', e.stroke);
    ctx.drawImage(sp, b.x - half, b.y - half);
    const m = ctx.measureText(b.n);
    ctx.fillStyle = b.extra ? e.extraColor : e.color;
    ctx.fillText(b.n, Math.round(b.x - (m.actualBoundingBoxRight - m.actualBoundingBoxLeft) / 2), Math.round(b.y + (m.actualBoundingBoxAscent - m.actualBoundingBoxDescent) / 2));
  }
  ctx.restore();
  return L;
}

// ---------- počasí (rozvržení počítá server: weather_layout v lib/weather.php) ----------
const wIcons = new Map();
let WEATHER_REDRAW = null;   // překreslení po načtení ikony
function weatherIcon(code) {
  if (!/^[0-9a-z]{2,4}$/.test(code || '')) return null;
  if (!wIcons.has(code)) {
    const im = new Image();
    im.onload = () => WEATHER_REDRAW?.();
    im.src = `api.php?a=weather_icon&code=${code}`;
    wIcons.set(code, im);
  }
  const im = wIcons.get(code);
  return im.complete && im.naturalWidth ? im : null;
}
/** Prvek Počasí podle částí ze serveru; po posunu nebo změně velikosti (než přijde nové rozvržení) se části přepočítají z původního rámečku. */
function drawWeather(ctx, e, r) {
  const parts = r?.parts || [];
  if (!parts.length) return;
  const b = r.box || [e.x, e.y, e.w, e.h], sx = e.w / b[2], sy = e.h / b[3], k = Math.min(sx, sy);
  const X = (x) => e.x + (x - b[0]) * sx, Y = (y) => e.y + (y - b[1]) * sy;
  const al = e.alpha * (r.sample ? 0.45 : 1);
  ctx.save();
  if (e.bg.on) { ctx.fillStyle = rgba(e.bg.color, e.bg.alpha * al); rrectPath(ctx, e.x, e.y, e.w, e.h, e.bg.radius); ctx.fill(); }
  ctx.textBaseline = 'alphabetic';
  for (const p of parts) {
    if (p.k === 'icon') {
      const im = weatherIcon(p.code);
      if (!im) continue;
      ctx.globalAlpha = al;
      ctx.drawImage(im, X(p.x), Y(p.y), p.s * k, p.s * k);
      ctx.globalAlpha = 1;
    } else {
      const size = p.size * k;
      ctx.font = cssFont(e, size);
      ctx.letterSpacing = '0px';
      const n = [...p.text].length;
      if ('letterSpacing' in ctx && n > 1 && p.w) ctx.letterSpacing = `${(p.w * k - textW(ctx, p.text)) / (n - 1)}px`;
      if (e.shadow.on) { ctx.fillStyle = rgba(e.shadow.color, e.shadow.alpha * al); ctx.fillText(p.text, X(p.x) + e.shadow.dx, Y(p.y) + e.shadow.dy); }
      ctx.fillStyle = rgba(e.color, al);
      ctx.fillText(p.text, X(p.x), Y(p.y));
    }
  }
  ctx.letterSpacing = '0px';
  ctx.restore();
}

function renderDesign(ctx, d, texts) {
  ctx.clearRect(0, 0, SW, SH);
  const bg = d.bg;
  ctx.fillStyle = bg.color; ctx.fillRect(0, 0, SW, SH);
  if (bg.type === 'gradient') {
    const g = bg.dir === 'h' ? ctx.createLinearGradient(0, 0, SW, 0) : ctx.createLinearGradient(0, 0, 0, SH);
    g.addColorStop(0, bg.color); g.addColorStop(1, bg.color2);
    ctx.fillStyle = g; ctx.fillRect(0, 0, SW, SH);
  } else if (bg.type === 'image' && bg.mediaId) {
    const im = loadImg(bg.mediaId);
    if (im) drawFit(ctx, im, 0, 0, SW, SH, bg.fit);
  }
  if (bg.dim > 0) { ctx.fillStyle = rgba('#000000', bg.dim); ctx.fillRect(0, 0, SW, SH); }
  const info = {};
  for (const e of d.elements) {
    if (e.hidden || texts[e.id]?.visible === false) continue;
    if (e.type === 'rect') { ctx.fillStyle = rgba(e.color, e.alpha); rrectPath(ctx, e.x, e.y, e.w, e.h, e.radius); ctx.fill(); }
    else if (e.type === 'image') { const im = e.mediaId && loadImg(e.mediaId); if (im) drawFit(ctx, im, e.x, e.y, e.w, e.h, e.fit, e.alpha); }
    else if (e.type === 'balls') info[e.id] = drawBalls(ctx, e, texts[e.id]);
    else if (e.type === 'weather') drawWeather(ctx, e, texts[e.id]);
    else info[e.id] = drawText(ctx, e, texts[e.id]?.text ?? e.text, texts[e.id]);
  }
  return info;
}

// ---------- výchozí hodnoty ----------
const uid = () => Math.random().toString(36).slice(2, 10);
function newText(over = {}) {
  return { id: uid(), type: 'text', name: '', x: 360, y: 440, w: 1200, h: 200, alpha: 1, hidden: false,
    text: 'Nový text', variants: [], showIf: [], font: 'montserrat', weight: 700, size: 96, color: '#ffffff', align: 'center', valign: 'middle',
    lineHeight: 1.15, upper: false, autoFit: true, spacing: 0, fx: null,
    shadow: { on: false, color: '#000000', alpha: 0.5, dx: 4, dy: 4 },
    outline: { on: false, color: '#000000', width: 3 },
    bg: { on: false, color: '#000000', alpha: 0.5, padding: 24, radius: 16 }, ...over };
}
const newRect = () => ({ id: uid(), type: 'rect', name: '', showIf: [], x: 260, y: 340, w: 1400, h: 400, alpha: 0.6, hidden: false, color: '#000000', radius: 24 });
const newBalls = (over = {}) => ({ id: uid(), type: 'balls', name: '', showIf: [], x: 260, y: 440, w: 1400, h: 200, alpha: 1, hidden: false,
  src: '1 2 3 4 5 6', extra: '', style: 'glossy', size: 140, gap: 20, extraGap: 48, fill: '#d2502c', color: '#ffffff', extraFill: '#f2b705', extraColor: '#1d1c1a',
  stroke: { on: false, color: '#ffffff', width: 4 }, font: 'montserrat', weight: 700, numScale: 0.46, align: 'center', valign: 'middle', ...over });
const newWeather = (over = {}) => ({ id: uid(), type: 'weather', name: '', showIf: [], x: 1240, y: 60, w: 620, h: 220, alpha: 1, hidden: false,
  look: 'mini', place: '', font: 'montserrat', weight: 700, color: '#ffffff', align: 'center',
  shadow: { on: false, color: '#000000', alpha: 0.5, dx: 4, dy: 4 }, bg: { on: false, color: '#000000', alpha: 0.35, padding: 32, radius: 24 }, ...over });
/** Výchozí rozměry a podklad pro vzhled počasí. */
const WEATHER_LOOKS = [
  ['mini', 'Ikona + teplota', { w: 620, h: 220, bg: false }],
  ['card', 'Karta s detaily', { w: 900, h: 420, bg: true }],
  ['days', 'Předpověď na 3 dny', { w: 1200, h: 440, bg: true }],
];
const newImage = (mediaId) => ({ id: uid(), type: 'image', name: '', showIf: [], x: 660, y: 290, w: 600, h: 500, alpha: 1, hidden: false, mediaId, fit: 'contain' });
function normDesign(d) {
  d = d ? JSON.parse(JSON.stringify(d)) : {};
  d.bg = { type: 'color', color: '#1d1c1a', color2: '#d2502c', dir: 'v', mediaId: null, fit: 'cover', dim: 0, ...(d.bg || {}) };
  d.elements = (d.elements || []).map((e) => (e.type === 'text' ? newText(e) : e.type === 'rect' ? { ...newRect(), ...e }
    : e.type === 'balls' ? newBalls({ ...e, stroke: { ...newBalls().stroke, ...(e.stroke || {}) } })
    : e.type === 'weather' ? newWeather({ ...e, shadow: { ...newWeather().shadow, ...(e.shadow || {}) }, bg: { ...newWeather().bg, ...(e.bg || {}) } }) : { ...newImage(null), ...e }));
  return d;
}

let DATA_ROWS = [];
function dataVarOptions() {
  const groups = [...new Set(DATA_ROWS.map((r) => r.grp))];
  return groups.map((g) => {
    const rows = DATA_ROWS.filter((r) => r.grp === g);
    const dated = rows.some((r) => r.date);
    const opts = dated ? [
      [`{${g}.nejbližší}`, 'nejbližší hodnota'], [`{${g}.nejbližší|mil}`, 'nejbližší v milionech (125 mil.)'],
      [`{${g}.nejbližší|cislo}`, 'nejbližší s mezerami (125 000 000)'], [`@num:${g}.nejbližší`, 'nejbližší – vybrat formát čísla…'],
      [`@date:${g}.nejbližší.parametr`, 'datum nejbližšího – vybrat formát…'], [`{${g}.nejbližší.parametr|odpocet}`, 'odpočet do nejbližšího (za 5 dní)'],
      [`@date:${g}.další.parametr`, 'datum následujícího – vybrat formát…'],
      [`{${g}.další|mil}`, 'následující po nejbližším'], [`{${g}.poslední|mil}`, 'poslední prošlý'],
    ] : rows.slice(0, 15).map((r) => [`{${g}:${r.param}}`, r.param]);
    return `<optgroup label="Data: ${esc(g)}">${opts.map(([v, l]) => `<option value="${esc(v)}">${esc(g)} – ${esc(l)}</option>`).join('')}</optgroup>`;
  }).join('');
}
let SOURCES = [];
const srcItems = (s) => s.groups.flatMap((g) => g.items.map((i) => ({ ...i, group: g.title })));
const COND_VARS = () => ['den', 'hodina', 'datum', 'svatek', 'cislodne',
  ...SOURCES.flatMap((s) => srcItems(s).filter((i) => !i.tech && i.kind !== 'list').map((i) => `${s.name}:${i.path}`)),
  ...[...new Set(DATA_ROWS.filter((r) => r.date).map((r) => r.grp))].flatMap((g) => [`${g}.nejbližší`, `${g}.nejbližší.parametr`]),
  ...DATA_ROWS.filter((r) => !r.date).map((r) => `${r.grp}:${r.param}`)];
function filterHelp() {
  openModal(`<div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">Proměnné a formátování</h2><button class="btn sm" data-close>Zavřít</button></div>
    <div class="help-cols"><div>
    <h4>Proměnné</h4><table class="help">
      <tr><td><code>{datum}</code></td><td>16. 10. 2026 (<code>{datum:+1}</code> zítra)</td></tr>
      <tr><td><code>{den}</code> / <code>{Den}</code></td><td>pátek / Pátek</td></tr>
      <tr><td><code>{svatek}</code></td><td>kdo má svátek (<code>{svatek:+1}</code> zítra)</td></tr>
      <tr><td><code>{odpocet:24.12.2026}</code></td><td>za 69 dní / zítra / dnes</td></tr>
      <tr><td><code>{Skupina.nejbližší}</code></td><td>hodnota s nejbližším datem (dnes a dál)</td></tr>
      <tr><td><code>{Skupina.nejbližší.parametr}</code></td><td>jeho datum</td></tr>
      <tr><td><code>{Skupina.další}</code></td><td>následující po nejbližším</td></tr>
      <tr><td><code>{Skupina.poslední}</code></td><td>poslední s datem v minulosti</td></tr>
      <tr><td><code>{Skupina:26.11.2026}</code></td><td>hodnota s přesně tímto parametrem</td></tr>
      <tr><td><code>{Ceník:Káva}</code></td><td>totéž pro textový parametr</td></tr>
      <tr><td><code>{Allwyn:sportka.superjackpot}</code></td><td>hodnota ze vzdáleného zdroje (tlačítko <b>Ze zdroje…</b>)</td></tr>
      <tr><td><code>{Allwyn:sportka.tah1.2}</code></td><td>2. číslo ze seznamu</td></tr>
      <tr><td><code>{Ceník:Káva.Cena}</code></td><td>zdroj CSV: řádek podle 1. sloupce, sloupec podle záhlaví</td></tr>
      <tr><td><code>{Počasí:teplota}</code></td><td>teplota ve výchozím místě (také <code>pocitová</code>, <code>popis</code>, <code>vlhkost</code>, <code>vítr</code>, <code>místo</code>)</td></tr>
      <tr><td><code>{Počasí:zítra.max}</code></td><td>nejvyšší teplota zítra (<code>dnes</code>, <code>zítra</code>, <code>pozítří</code>; <code>.max</code>, <code>.min</code>, <code>.popis</code>, <code>.den</code>)</td></tr>
      <tr><td><code>{Počasí:Brno.teplota}</code></td><td>počasí pro jiné místo</td></tr>
    </table></div><div>
    <h4>Formátování (za svislou čáru)</h4><table class="help">
      <tr><td><code>|mil</code></td><td>125 mil.</td></tr><tr><td><code>|mld</code></td><td>1,26 mld.</td></tr>
      <tr><td><code>|cislo</code></td><td>125 000 000 (<code>:0</code> celé, <code>:0,00</code> vždy 2 místa)</td></tr><tr><td><code>|kc</code></td><td>125 000 000 Kč (<code>:0,00</code> 45,50 Kč)</td></tr>
      <tr><td><code>|tis</code> <code>|milionu</code> <code>|miliard</code></td><td>125 000 tis. / 125 milionů / 1,26 miliardy</td></tr>
      <tr><td><code>|bezmezer</code> <code>|pripona: Kč</code></td><td>bez mezer mezi tisíci / text na konec – tlačítko <b>Formát čísla…</b></td></tr>
      <tr><td><code>|datum</code></td><td>26. 11. 2026 (<code>:kratke</code> 26. 11., <code>:den</code> čtvrtek 26. 11., <code>:dlouhe</code> 26. listopadu 2026 a další – tlačítko <b>Formát data…</b>)</td></tr>
      <tr><td><code>|datum:DN D. M.</code></td><td>vlastní formát: čt 26. 11. (${esc(DATE_TOKENS_HELP)})</td></tr>
      <tr><td><code>|odpocet</code></td><td>u data: za 41 dní / zítra / dnes</td></tr>
      <tr><td><code>|velka</code> <code>|mala</code> <code>|prvni</code></td><td>velikost písmen</td></tr>
      <tr><td><code>|vychozi:—</code></td><td>co ukázat, když hodnota chybí</td></tr>
      <tr><td><code>|cisla:, </code></td><td>seznam čísel oddělený čárkou (22, 14, 27)</td></tr>
    </table>
    <p class="hint small">Lze řetězit: <code>{Sportka.nejbližší.parametr|datum:den|velka}</code></p></div></div>`);
}

// ---------- výběr hodnoty ze vzdáleného zdroje ----------
const SRC_FORMATS = {
  number: [['', 'jak je'], ['|mil', 'v mil.'], ['|cislo', 's mezerami'], ['@num', 'jiný formát…']],
  date: [['', 'jak je'], ['|datum', 'datum'], ['@date', 'jiný formát…'], ['|odpocet', 'odpočet']],
  list: [['', 'mezery'], ['|cisla:, ', 'čárky']],
  text: [['', 'vložit']],
};
const fmtSrcValue = (i) => (i.kind === 'number' && !/id/.test(i.key) && Math.abs(+i.value) >= 1e6 ? `${i.value} (${fmtDataValue(i.value)})` : i.value);
/** mode 'text' = vložení do textu s formátem, 'balls' = jen seznamy a čísla, 'browse' = kopírování. */
function pickSource(mode, cb, onlyId = null) {
  let q = '', tech = false, sid = onlyId ?? SOURCES.find((s) => s.groups.length)?.id ?? SOURCES[0]?.id;
  openModal(`<div class="row" style="margin-bottom:10px;gap:8px"><h2 class="grow" style="margin:0">${mode === 'browse' ? 'Data zdroje' : 'Hodnota ze zdroje'}</h2>
      ${SOURCES.length > 1 && onlyId === null ? `<select id="psS" style="max-width:180px">${SOURCES.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select>` : ''}
      <input type="search" id="psQ" placeholder="Hledat…" style="max-width:180px"><button class="btn sm" data-close>Zavřít</button></div>
    <div id="psInfo"></div>
    <label class="check-row small" style="margin-bottom:6px"><input type="checkbox" id="psT"><span>Zobrazit i technické údaje</span></label>
    <div id="psBody" class="ps-body"></div>`);
  if ($('#psS')) { $('#psS').value = sid; $('#psS').onchange = (e) => { sid = +e.target.value; draw(); }; }
  const draw = () => {
    const s = SOURCES.find((x) => x.id === sid);
    if (!s) { $('#psBody').innerHTML = '<div class="empty small">Žádný zdroj. Přidejte ho v sekci Data → Vzdálené zdroje.</div>'; return; }
    $('#psInfo').innerHTML = s.error ? `<div class="warnbox small" style="margin-bottom:8px">Poslední stahování selhalo: ${esc(s.error)}${s.ok_at ? ` Zobrazená data jsou z ${esc(fmtTime(s.ok_at))}.` : ''}</div>`
      : s.ok_at ? `<p class="hint small" style="margin:0 0 6px">Staženo ${esc(fmtTime(s.ok_at))}. ${mode === 'browse' ? 'Kliknutím zkopírujete zápis pro slide.' : mode === 'balls' ? 'Vyberte seznam čísel.' : 'Kliknutím na formát vložíte proměnnou.'}</p>` : '';
    if (!s.groups.length) { $('#psBody').innerHTML = '<div class="empty small">Zdroj zatím nemá stažená data.</div>'; return; }
    const html = s.groups.map((g) => {
      const items = g.items.filter((i) => (tech || !i.tech) && (mode !== 'balls' || ['list', 'number'].includes(i.kind))
        && `${g.title} ${i.label} ${i.path} ${i.value}`.toLowerCase().includes(q));
      if (!items.length) return '';
      return `<div class="ps-group"><h4>${esc(g.title)}</h4>${items.map((i) => {
        const v = `${s.name}:${i.path}`;
        const btns = mode === 'balls' ? [['', 'Použít']] : mode === 'browse' ? [['', 'Kopírovat']] : SRC_FORMATS[i.kind];
        return `<div class="ps-item"><div class="grow"><b>${esc(i.label)}</b> <code class="small">${esc(i.path)}</code><div class="ps-val">${esc(fmtSrcValue(i))}</div></div>
          <div class="ps-btns">${btns.map(([f, l]) => `<button class="btn sm ${f === '' ? '' : 'ghost'}" data-v="${esc(f.startsWith('@') ? `${f}:${v}` : `{${v}${f}}`)}">${esc(l)}</button>`).join('')}</div></div>`;
      }).join('')}</div>`;
    }).join('');
    $('#psBody').innerHTML = html || '<div class="empty small">Nic nenalezeno.</div>';
    $$('#psBody [data-v]').forEach((b) => b.onclick = () => {
      const v = b.dataset.v;
      if (v.startsWith('@date:')) { pickDateFormat(v.slice(6), '', (f) => cb(`{${v.slice(6)}${f}}`)); return; }
      if (v.startsWith('@num:')) { pickNumFormat(v.slice(5), [], (f) => cb(`{${v.slice(5)}${f}}`)); return; }
      if (mode !== 'browse') closeModal();
      cb(v);
    });
  };
  $('#psQ').oninput = (e) => { q = e.target.value.toLowerCase(); draw(); };
  $('#psT').onchange = (e) => { tech = e.target.checked; draw(); };
  draw();
}

const WEATHER_VARS = [
  ['{Počasí:teplota}', 'Teplota (číslo)'], ['{Počasí:pocitová}', 'Pocitová teplota'], ['{Počasí:popis}', 'Popis (Zataženo)'],
  ['{Počasí:vlhkost}', 'Vlhkost v %'], ['{Počasí:vítr}', 'Vítr v m/s'], ['{Počasí:dnes.max}', 'Dnes nejvyšší teplota'], ['{Počasí:dnes.min}', 'Dnes nejnižší teplota'],
  ['{Počasí:zítra.max}', 'Zítra nejvyšší teplota'], ['{Počasí:zítra.min}', 'Zítra nejnižší teplota'], ['{Počasí:zítra.popis}', 'Zítra popis'],
  ['{Počasí:pozítří.den}', 'Den pozítří (Sobota)'], ['{Počasí:pozítří.max}', 'Pozítří nejvyšší teplota'],
];
const WEATHER_PREV = {
  mini: '<span class="w-prev"><span class="w-s">☀</span>12°</span>',
  card: '<span class="w-prev w-card"><span><span class="w-s">☀</span> 12°</span><i>Jasno · 62 %</i></span>',
  days: '<span class="w-prev w-days"><b>Dnes<span class="w-s">☀</span>18°</b><b>Zítra<span class="w-s">☁</span>15°</b><b>So<span class="w-s">❄</span>3°</b></span>',
};
let WEATHER = null;   // nastavení počasí (výchozí místo, klíč) pro editor
const VARS = [
  ['@date:datum', 'Dnešní datum – vybrat formát…'], ['@date:datum:+1', 'Zítřejší datum – vybrat formát…'], ['{den}', 'Den v týdnu (pátek)'], ['{Den}', 'Den s velkým (Pátek)'],
  ['{svatek}', 'Kdo má dnes svátek'], ['{svatek:+1}', 'Kdo má svátek zítra'], ['{mesic}', 'Měsíc (říjen)'], ['{rok}', 'Rok'],
  ['{tyden}', 'Číslo týdne'], ['{odpocet:24.12.2026}', 'Odpočet do data (za 5 dní / zítra / dnes)'], ['{dnu:24.12.2026}', 'Počet dní do data (číslo)'],
];

// ---------- formát data ----------
const DATE_FORMATS = [
  ['|datum', 'číslem'], ['|datum:kratke', 'bez roku'], ['|datum:bezmezer', 'bez mezer'], ['|datum:nuly', 's nulami (05.03.2026)'],
  ['|datum:slovem', 'měsíc slovem, bez roku'], ['|datum:dlouhe', 'měsíc slovem'],
  ['|datum:den', 's dnem, bez roku'], ['|datum:den-plne', 's dnem'],
  ['|datum:den-slovem', 's dnem a měsícem slovem, bez roku'], ['|datum:den-dlouhe', 's dnem a měsícem slovem'],
  ['|datum:dentydne', 'jen den v týdnu'], ['|datum:mesic-rok', 'měsíc a rok'], ['|odpocet', 'odpočet (za 5 dní / zítra / dnes)'],
];
const DATE_TOKENS_HELP = 'D 5 · DD 05 · M 3 · MM 03 · MĚSÍC březen · MĚSÍCE března · RR 26 · RRRR 2026 · DEN čtvrtek · DN čt';
/** Filtr formátu data z textu filtrů (|datum:… nebo |odpocet), jinak ''. */
const dateFilterOf = (filters) => filters.find((f) => /^(datum|odpocet|odpočet)(:|$)/i.test(f)) || '';
/**
 * Dialog „Formát data“. expr = proměnná bez závorek a filtrů (např. „datum:+1“ nebo „Sportka.nejbližší.parametr“),
 * cur = současný filtr (např. „|datum:den“), cb(filtr) dostane vybraný filtr.
 */
function pickDateFormat(expr, cur, cb) {
  const customCur = cur.startsWith('|datum:') && !DATE_FORMATS.some(([f]) => f === cur) ? cur.slice(7) : '';
  openModal(`<div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">Formát data</h2><button class="btn sm" data-close>Zavřít</button></div>
    <p class="hint small" style="margin:0 0 6px">Proměnná <code>{${esc(expr)}}</code>. Ukázky jsou s dnešními hodnotami. Kliknutím formát použijete.</p>
    <div id="dfList" class="ps-body" style="max-height:46vh"></div>
    <h4 style="margin:12px 0 4px">Vlastní formát</h4>
    <div class="row" style="gap:6px"><input type="text" id="dfCustom" class="grow" maxlength="60" placeholder="např. DEN D. MĚSÍCE RRRR" value="${esc(customCur)}">
      <button class="btn sm" id="dfUse">Použít</button></div>
    <div class="ps-val" id="dfPrev" style="margin-top:4px">&nbsp;</div>
    <p class="hint small">Zástupné znaky (velkými písmeny): ${esc(DATE_TOKENS_HELP)}. Ostatní znaky zůstanou, jak jsou.</p>`);
  const fetchVals = (formats) => api('datefmt', { expr, formats }).then((r) => r.values).catch(() => formats.map(() => null));
  $('#dfList').innerHTML = DATE_FORMATS.map(([f, l]) => `<div class="ps-item df-item${f === cur ? ' on' : ''}" data-f="${esc(f)}" role="button" tabindex="0">
    <div class="grow"><b class="df-ex">…</b><div class="ps-val">${esc(l)} <code class="small">${esc(f)}</code></div></div></div>`).join('');
  fetchVals(DATE_FORMATS.map(([f]) => f)).then((vals) => {
    $$('#dfList .df-item').forEach((el, i) => { const ex = el.querySelector('.df-ex'); if (ex) ex.textContent = vals[i] ?? '(nelze zobrazit – hodnota teď chybí)'; });
  });
  const done = (f) => { closeModal(); cb(f); };
  $$('#dfList .df-item').forEach((el) => {
    el.onclick = () => done(el.dataset.f);
    el.onkeydown = (ev) => { if (ev.key === 'Enter') done(el.dataset.f); };
  });
  const inp = $('#dfCustom');
  const clean = () => inp.value.replace(/[{}|]/g, '').trim();
  let pt = null;
  const prev = () => {
    clearTimeout(pt);
    const c = clean();
    if (!c) { $('#dfPrev').innerHTML = '&nbsp;'; return; }
    pt = setTimeout(async () => {
      const [v] = await fetchVals([`|datum:${c}`]);
      if ($('#dfPrev') && clean() === c) $('#dfPrev').textContent = v != null ? `Ukázka: ${v}` : 'Ukázka: hodnota teď chybí';
    }, 250);
  };
  inp.oninput = prev;
  inp.onkeydown = (ev) => { if (ev.key === 'Enter') $('#dfUse').click(); };
  $('#dfUse').onclick = () => { const c = clean(); if (!c) { inp.focus(); return; } done(`|datum:${c}`); };
  prev();
}

// ---------- formát čísla ----------
const NUM_FORMATS = [
  ['', 'jak je'], ['|cislo', 's mezerami (nejvýš 2 desetinná místa)'], ['|cislo:0', 'zaokrouhleno na celé'], ['|cislo:0,00', 'vždy 2 desetinná místa'],
  ['|kc', 'v Kč'], ['|kc:0,00', 'v Kč s haléři (45,50 Kč)'], ['|tis', 'v tisících'], ['|mil', 'v milionech'], ['|mil|pripona: Kč', 'v milionech Kč'],
  ['|milionu', 'v milionech slovem'], ['|milionu|pripona: Kč', 'v milionech slovem s Kč'], ['|mld', 'v miliardách'], ['|miliard', 'v miliardách slovem'],
  ['|cislo:0|bezmezer', 'celé bez mezer'],
];
const NUM_FILTERS = ['cislo', 'kc', 'tis', 'mil', 'mld', 'milionu', 'miliard', 'bezmezer', 'pripona'];
const isNumFilter = (f) => NUM_FILTERS.includes(f.split(':')[0].trim().toLowerCase());
/** Filtry z vlastního nastavení: jednotka, desetinná místa, Kč, mezery. */
function numFilterFrom(o) {
  const dec = o.dec === '' ? '' : o.fixed && +o.dec > 0 ? `:0,${'0'.repeat(+o.dec)}` : `:${o.dec}`;
  let f = o.unit ? `|${o.unit}${dec}` : `|${o.kc ? 'kc' : 'cislo'}${dec}`;
  if (o.nosp) f += '|bezmezer';
  if (o.unit && o.kc) f += '|pripona: Kč';
  return f;
}
/** Opačně: nastavení z filtrů (pro předvyplnění vlastního formátu). */
function numOptsFrom(filters) {
  const o = { unit: '', dec: '', fixed: false, kc: false, nosp: false };
  filters.forEach((f) => {
    const [n, a = ''] = f.split(/:(.*)/s).map((x) => x ?? ''), k = n.trim().toLowerCase();
    if (['tis', 'mil', 'mld', 'milionu', 'miliard'].includes(k)) o.unit = k;
    if (['tis', 'mil', 'mld', 'milionu', 'miliard', 'cislo', 'kc'].includes(k)) {
      const m = /^0[,.](0+)$/.exec(a.trim());
      if (m) { o.dec = String(Math.min(3, m[1].length)); o.fixed = true; } else if (/^\d$/.test(a.trim())) o.dec = String(Math.min(3, +a.trim()));
    }
    if (k === 'kc' || (k === 'pripona' && /kč/i.test(a))) o.kc = true;
    if (k === 'bezmezer') o.nosp = true;
  });
  return o;
}
/**
 * Dialog „Formát čísla“. expr = proměnná bez závorek a filtrů, cur = současné číselné filtry (pole),
 * cb(filtr) dostane vybrané filtry jako text (např. „|mil:1|pripona: Kč“).
 */
function pickNumFormat(expr, cur, cb) {
  const curStr = cur.map((f) => `|${f}`).join('');
  const o = numOptsFrom(cur);
  const opt = (v, l, sel) => `<option value="${v}" ${v === sel ? 'selected' : ''}>${l}</option>`;
  openModal(`<div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">Formát čísla</h2><button class="btn sm" data-close>Zavřít</button></div>
    <p class="hint small" style="margin:0 0 6px">Proměnná <code>{${esc(expr)}}</code>. Ukázky jsou s aktuální hodnotou. Kliknutím formát použijete.</p>
    <div id="nfList" class="ps-body" style="max-height:40vh"></div>
    <h4 style="margin:12px 0 4px">Vlastní formát</h4>
    <div class="cols2"><label class="field fsm">Jednotka<select id="nfUnit">${opt('', 'bez převodu', o.unit)}${opt('tis', 'tisíce (tis.)', o.unit)}${opt('mil', 'miliony (mil.)', o.unit)}${opt('milionu', 'miliony slovem (milionů)', o.unit)}${opt('mld', 'miliardy (mld.)', o.unit)}${opt('miliard', 'miliardy slovem (miliard)', o.unit)}</select></label>
      <label class="field fsm">Desetinná místa<select id="nfDec">${opt('', 'automaticky', o.dec)}${opt('0', '0', o.dec)}${opt('1', '1', o.dec)}${opt('2', '2', o.dec)}${opt('3', '3', o.dec)}</select></label></div>
    <label class="check-row small"><input type="checkbox" id="nfFixed" ${o.fixed ? 'checked' : ''}><span>Vždy zobrazit všechna desetinná místa (125,0 místo 125)</span></label>
    <label class="check-row small"><input type="checkbox" id="nfKc" ${o.kc ? 'checked' : ''}><span>Přidat „Kč“</span></label>
    <label class="check-row small"><input type="checkbox" id="nfNosp" ${o.nosp ? 'checked' : ''}><span>Bez mezer mezi tisíci (125000000)</span></label>
    <div class="row" style="gap:8px;margin-top:6px"><div class="ps-val grow" id="nfPrev">&nbsp;</div><button class="btn sm" id="nfUse">Použít</button></div>`);
  const fetchVals = (formats) => api('datefmt', { expr, formats }).then((r) => r.values).catch(() => formats.map(() => null));
  $('#nfList').innerHTML = NUM_FORMATS.map(([f, l]) => `<div class="ps-item df-item${f === curStr ? ' on' : ''}" data-f="${esc(f)}" role="button" tabindex="0">
    <div class="grow"><b class="df-ex">…</b><div class="ps-val">${esc(l)}${f ? ` <code class="small">${esc(f)}</code>` : ''}</div></div></div>`).join('');
  fetchVals(NUM_FORMATS.map(([f]) => f)).then((vals) => {
    $$('#nfList .df-item').forEach((el, i) => { const ex = el.querySelector('.df-ex'); if (ex) ex.textContent = vals[i] ?? '(nelze zobrazit – hodnota teď chybí)'; });
  });
  const done = (f) => { closeModal(); cb(f); };
  $$('#nfList .df-item').forEach((el) => {
    el.onclick = () => done(el.dataset.f);
    el.onkeydown = (ev) => { if (ev.key === 'Enter') done(el.dataset.f); };
  });
  const custom = () => numFilterFrom({ unit: $('#nfUnit').value, dec: $('#nfDec').value, fixed: $('#nfFixed').checked, kc: $('#nfKc').checked, nosp: $('#nfNosp').checked });
  let pt = null;
  const prev = () => {
    clearTimeout(pt);
    const f = custom();
    pt = setTimeout(async () => {
      const [v] = await fetchVals([f]);
      if ($('#nfPrev') && custom() === f) $('#nfPrev').innerHTML = `Ukázka: <b>${esc(v ?? 'hodnota teď chybí')}</b> <code class="small">${esc(f)}</code>`;
    }, 200);
  };
  ['#nfUnit', '#nfDec', '#nfFixed', '#nfKc', '#nfNosp'].forEach((id) => { $(id).onchange = prev; });
  $('#nfUse').onclick = () => done(custom());
  prev();
}

// ---------- výběr obrázku z galerie ----------
function pickImage(title, cb) {
  let q = '';
  const tSt = newTagState();
  openModal(`<div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">${esc(title)}</h2>
    <input type="search" id="piQ" placeholder="Hledat…" style="max-width:200px"><button class="btn sm" data-close>Zavřít</button></div>
    <div class="chips folder-bar tag-filter" id="piT" style="margin-bottom:8px"></div>
    <div class="grid small-grid" id="piGrid" style="max-height:60vh"></div>`);
  const draw = () => {
    const cand = store.media.filter((m) => ['image', 'slide'].includes(m.kind));
    tagBar($('#piT'), cand, tSt, draw);
    const items = cand.filter((m) => tagOk(m, tSt) && `${m.original_name} ${tagText(m)}`.toLowerCase().includes(q));
    $('#piGrid').innerHTML = items.map((m) => `<div class="card pick" data-id="${m.id}"><div class="thumb">${thumb(m)}</div>
      <div class="meta"><span class="name">${esc(m.original_name)}</span></div></div>`).join('') || '<div class="empty small" style="grid-column:1/-1">V galerii nejsou obrázky.</div>';
    $$('#piGrid .card').forEach((c) => c.onclick = () => { closeModal(); cb(+c.dataset.id); });
  };
  $('#piQ').oninput = (e) => { q = e.target.value.toLowerCase(); draw(); };
  draw();
}

// ---------- editor ----------
async function editSlide(m) {
  clearInterval(timer);
  await loadFontList();
  try { DATA_ROWS = (await api('data_list')).rows; } catch { DATA_ROWS = []; }
  try { SOURCES = (await api('source_tree')).sources; } catch { SOURCES = []; }
  try { FX = await api('fx_styles'); } catch { /* bez grafických stylů */ }
  try { WEATHER = await api('weather_settings'); } catch { WEATHER = null; }
  try { FORMATS = await api('slide_formats'); } catch { /* výchozí */ }
  ensureFont('titan', 900); // náhledy grafických stylů
  const fmt0 = FORMATS.find((f) => f.key === (m?.design?.format || NEW_FORMAT)) || FORMATS[0];
  SW = fmt0.w; SH = fmt0.h;
  let d = normDesign(m?.design || { format: fmt0.key, elements: [sc(newText({ text: 'Nový slide', y: 390, h: 300, size: 140 }))] });
  d.format = fmt0.key; d.w = SW; d.h = SH;
  let name = m ? m.original_name.replace(/\.jpg$/i, '') : '';
  let tags = [...(m ? m.tags || [] : currentTags())];
  let selId = null, texts = {}, dirty = false, info = {};
  const hist = [JSON.stringify(d)]; let hPos = 0;
  const mobile = isTouch || innerWidth < 900;

  main.innerHTML = `
    <div class="head slide-head">
      <div class="row grow" style="gap:12px">
        <button class="btn" id="seBack">← Zpět</button>
        <input type="text" id="seName" value="${esc(name)}" placeholder="Název slidu" style="max-width:260px">
        <select id="seFmt" title="Formát plátna – při změně se prvky poměrně přesunou" style="max-width:240px">${FORMATS.map((f) => `<option value="${f.key}"${f.key === d.format ? ' selected' : ''}>${esc(f.name)} · ${f.w} × ${f.h}</option>`).join('')}</select>
        <button class="btn" id="seTags" title="Tagy pro třídění a hledání">🏷 Tagy <span class="cnt" id="seTagCnt">${tags.length || ''}</span></button>
        <button class="btn ghost" id="seFonts" title="Nahrát vlastní písmo (TTF, OTF)">Písma…</button>
      </div>
      <div class="row save-bar">
        ${helpBtn('@editor-slidu', true)}
        ${mobile ? '' : `<button class="btn ghost" id="seUndo" title="Zpět (Ctrl+Z)">↶</button><button class="btn ghost" id="seRedo" title="Znovu (Ctrl+Y)">↷</button>`}
        <input type="datetime-local" id="seWhen" title="Náhled pro jiný čas zveřejnění (datum, svátek, data, předpověď počasí)" style="max-width:200px">
        <button class="btn" id="sePreview">Skutečný náhled</button>
        <button class="btn primary" id="seSave">Uložit</button>
      </div>
    </div>
    <div class="errbox" id="seErr" hidden style="margin-bottom:12px"></div>
    <div class="slide-editor ${mobile ? 'mobile' : ''}">
      ${mobile ? '' : `<div class="panel se-layers">
        <div class="row" style="gap:6px;margin-bottom:10px">
          <button class="btn sm" id="addText">+ Text</button><button class="btn sm" id="addImg">+ Obrázek</button><button class="btn sm" id="addRect">+ Obdélník</button><button class="btn sm" id="addBalls">+ Kuličky</button><button class="btn sm" id="addWeather">+ Počasí</button>
        </div>
        <div id="layerList"></div>
      </div>`}
      <div class="se-center">
        <div class="stage-wrap" id="stageWrap"><div class="stage" id="stage"><canvas id="seCanvas" width="${SW}" height="${SH}"></canvas><div id="overlay"></div></div></div>
        <p class="hint small" style="margin:8px 0 0">${mobile ? 'Na mobilu lze upravit texty. Rozložení slidu změníte na počítači.'
          : 'Klik = vybrat, tažení = posun, rohy = velikost. Šipky posouvají (Shift = o 10 px), Delete maže, Ctrl+D duplikuje. Prvky se přichytávají ke středu a okrajům.'}</p>
      </div>
      <div class="panel se-props" id="props"></div>
    </div>`;

  const cv = $('#seCanvas'), ctx = cv.getContext('2d');
  const stage = $('#stage'), overlay = $('#overlay');
  let scale = 1;
  let lastW = 0, fitRaf = 0;
  const fit = () => {
    const wrap = $('#stageWrap');
    if (!wrap) return;
    // šířka podle sloupce, ale plátno se vejde i na výšku okna (story 9 : 16)
    const w = Math.floor(Math.min(wrap.clientWidth, Math.max(240, innerHeight - 230) * SW / SH));
    if (Math.abs(w - lastW) < 2) return; // změna o pixel (posuvník) nepřekresluje – jinak by se velikost přepočítávala dokola
    lastW = w;
    scale = w / SW;
    stage.style.width = `${w}px`; stage.style.height = `${Math.round(SH * scale)}px`;
    drawOverlay();
  };
  // přepočet až v dalším snímku, ne přímo v ResizeObserveru
  const ro = new ResizeObserver(() => { cancelAnimationFrame(fitRaf); fitRaf = requestAnimationFrame(fit); });
  ro.observe($('#stageWrap'));
  addEventListener('resize', () => { lastW = 0; cancelAnimationFrame(fitRaf); fitRaf = requestAnimationFrame(fit); });

  // načíst písma a obrázky, pak překreslit
  const preload = async () => {
    await Promise.all(d.elements.filter((e) => ['text', 'balls', 'weather'].includes(e.type)).map((e) => ensureFont(e.font, e.weight)));
    redraw();
  };
  const sel = () => d.elements.find((e) => e.id === selId);
  function redraw() {
    info = renderDesign(ctx, d, texts);
    drawOverlay();
    resolveTexts(); // po změně si vyžádá přesné rozvržení ze serveru (jen když se něco změnilo)
  }
  // obrázky se načítají asynchronně → po načtení překreslit
  const imgWatch = () => {
    const ids = [d.bg.type === 'image' && d.bg.mediaId, ...d.elements.filter((e) => e.type === 'image').map((e) => e.mediaId)].filter(Boolean);
    ids.forEach((id) => { const mm = mediaById(id); if (mm?.url) { loadImg(id); const im = imgCache.get(mm.url); if (im && !im.complete) im.onload = redraw; } });
  };

  // dosazení proměnných přes server (stejná logika jako výsledný obrázek)
  let rt = null, lastReq = null;
  const resolveTexts = () => {
    const reqSig = JSON.stringify(d.elements.map((e) => (e.type === 'text' ? layoutSig(e) : e.type === 'weather' ? JSON.stringify(e) : JSON.stringify([e.id, e.showIf, e.src, e.extra]))));
    if (reqSig === lastReq) return;
    clearTimeout(rt);
    rt = setTimeout(async () => {
      lastReq = reqSig;
      const sigs = {}, boxes = {};
      d.elements.filter((e) => e.type === 'text').forEach((e) => { sigs[e.id] = layoutSig(e); });
      d.elements.filter((e) => e.type === 'weather').forEach((e) => { boxes[e.id] = [e.x, e.y, e.w, e.h]; });
      try {
        const r = await api('resolve', { design: d, ts: whenTs() });
        Object.keys(r).forEach((id) => { r[id].sig = sigs[id]; if (boxes[id]) r[id].box = boxes[id]; });
        texts = r;
      } catch { /* zůstane místní rozvržení */ }
      redraw();
      if (['text', 'balls'].includes(sel()?.type)) showUnknown();
      showBallNums();
      showWeatherState();
      showCondState();
    }, 250);
  };
  const showUnknown = () => {
    const box = $('#unk');
    if (!box) return;
    const u = texts[selId]?.unknown || [];
    const emoji = EMOJI_RE.test(sel()?.text || ''); EMOJI_RE.lastIndex = 0;
    box.hidden = !u.length && !emoji;
    box.textContent = [u.length ? `Neznámá proměnná: ${u.join(', ')} – zůstane v textu, jak je.` : '',
      emoji ? 'Emoji (např. 🎉) písma neobsahují – ve slidu se nezobrazí.' : ''].filter(Boolean).join(' ');
  };

  const showBallNums = () => {
    const box = $('#bNums'), r = texts[selId];
    if (!box || !r?.nums) return;
    box.textContent = r.nums.length || r.extras.length ? `Teď: ${r.nums.join(' ')}${r.extras.length ? ` + ${r.extras.join(' ')}` : ''}` : 'Teď žádná čísla – zkontrolujte zdroj.';
  };

  // stav počasí u vybraného prvku: odkud a kdy jsou data, nebo proč chybí
  const showWeatherState = () => {
    const box = $('#wState'), r = texts[selId];
    if (!box || !r || sel()?.type !== 'weather') return;
    const at = r.at ? new Date(r.at).toLocaleTimeString('cs-CZ', { hour: 'numeric', minute: '2-digit' }) : '';
    box.className = `small ${r.sample || r.error ? 'warnbox' : 'hint'}`;
    box.innerHTML = r.sample
      ? `${esc(r.error || 'Počasí zatím není stažené.')} Zobrazená ukázka se na obrazovce neukáže. <a href="#nastaveni/pocasi">Nastavení → Počasí</a>`
      : `Počasí pro <b>${esc(r.place)}</b>, staženo v ${esc(at)}. Obnovuje se každých 15 minut.${r.error ? ` <br>Poslední stažení se nepovedlo: ${esc(r.error)}` : ''}`;
  };
  WEATHER_REDRAW = () => redraw();

  function change(commitNow = true) {
    dirty = true;
    if (commitNow) commit();
    redraw();
  }
  let ct = null;
  function commit(delay = 0) {
    clearTimeout(ct);
    const doIt = () => {
      const snap = JSON.stringify(d);
      if (snap === hist[hPos]) return;
      hist.splice(hPos + 1); hist.push(snap); if (hist.length > 80) hist.shift(); hPos = hist.length - 1;
    };
    delay ? (ct = setTimeout(doIt, delay)) : doIt();
  }
  const undo = (dir) => {
    commit();
    const n = hPos + dir;
    if (n < 0 || n >= hist.length) return;
    hPos = n; d = JSON.parse(hist[n]);
    if (!sel()) selId = null;
    dirty = true; resolveTexts(); drawLayers(); drawProps(); redraw();
  };

  // ---------- překryv: výběr, posun, změna velikosti, vodítka ----------
  function drawOverlay() {
    if (mobile) { overlay.innerHTML = ''; return; }
    overlay.innerHTML = d.elements.map((e) => e.hidden ? '' : `<div class="el-box ${e.id === selId ? 'sel' : ''} ${e.type === 'text' && info[e.id]?.overflow ? 'overflow' : ''} ${texts[e.id]?.visible === false ? 'cond-off' : ''}"
      data-id="${e.id}" style="left:${e.x * scale}px;top:${e.y * scale}px;width:${e.w * scale}px;height:${e.h * scale}px">
      ${e.id === selId ? ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].map((h) => `<i class="se-h se-h-${h}" data-h="${h}"></i>`).join('') : ''}</div>`).join('')
      + '<div class="guide gv" hidden></div><div class="guide gh" hidden></div>';
  }
  const SNAP = 10;
  function snapMove(e, nx, ny) {
    const xs = [0, SW / 2, SW], ys = [0, SH / 2, SH];
    d.elements.forEach((o) => { if (o.id !== e.id && !o.hidden) { xs.push(o.x, o.x + o.w / 2, o.x + o.w); ys.push(o.y, o.y + o.h / 2, o.y + o.h); } });
    let gx = null, gy = null;
    const tryAxis = (pos, size, targets) => {
      let best = null;
      for (const [off] of [[0], [size / 2], [size]]) for (const t of targets) {
        const dd = t - (pos + off);
        if (Math.abs(dd) <= SNAP / scale && (!best || Math.abs(dd) < Math.abs(best.dd))) best = { dd, t };
      }
      return best;
    };
    const bx = tryAxis(nx, e.w, xs), by = tryAxis(ny, e.h, ys);
    if (bx) { nx += bx.dd; gx = bx.t; }
    if (by) { ny += by.dd; gy = by.t; }
    const gv = overlay.querySelector('.gv'), gh = overlay.querySelector('.gh');
    if (gv) { gv.hidden = gx === null; if (gx !== null) gv.style.left = `${gx * scale}px`; }
    if (gh) { gh.hidden = gy === null; if (gy !== null) gh.style.top = `${gy * scale}px`; }
    return [Math.round(nx), Math.round(ny)];
  }
  overlay.onpointerdown = (ev) => {
    const box = ev.target.closest('.el-box');
    if (!box) { selId = null; drawLayers(); drawProps(); drawOverlay(); return; }
    ev.preventDefault();
    const e = d.elements.find((x) => x.id === box.dataset.id);
    if (selId !== e.id) { selId = e.id; drawLayers(); drawProps(); drawOverlay(); }
    const h = ev.target.dataset.h, sx = ev.clientX, sy = ev.clientY, o = { x: e.x, y: e.y, w: e.w, h: e.h };
    const move = (mv) => {
      const dx = (mv.clientX - sx) / scale, dy = (mv.clientY - sy) / scale;
      if (!h) { [e.x, e.y] = snapMove(e, o.x + dx, o.y + dy); }
      else {
        let { x, y, w, h: hh } = o;
        if (h.includes('e')) w = Math.max(20, o.w + dx);
        if (h.includes('s')) hh = Math.max(20, o.h + dy);
        if (h.includes('w')) { w = Math.max(20, o.w - dx); x = o.x + o.w - w; }
        if (h.includes('n')) { hh = Math.max(20, o.h - dy); y = o.y + o.h - hh; }
        if (mv.shiftKey && e.type === 'image') { const r = o.w / o.h; if (h.length === 2) hh = w / r; }
        Object.assign(e, { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(hh) });
      }
      dirty = true;
      info = renderDesign(ctx, d, texts);
      const b = overlay.querySelector(`.el-box[data-id="${e.id}"]`);
      if (b) Object.assign(b.style, { left: `${e.x * scale}px`, top: `${e.y * scale}px`, width: `${e.w * scale}px`, height: `${e.h * scale}px` });
      syncPos();
    };
    const up = () => {
      removeEventListener('pointermove', move); removeEventListener('pointerup', up);
      overlay.querySelectorAll('.guide').forEach((g) => { g.hidden = true; });
      commit(); drawOverlay();
    };
    addEventListener('pointermove', move); addEventListener('pointerup', up);
  };
  overlay.ondblclick = (ev) => {
    const box = ev.target.closest('.el-box');
    const e = box && d.elements.find((x) => x.id === box.dataset.id);
    if (e?.type === 'text') { $('#pText')?.focus(); $('#pText')?.select(); }
  };

  // ---------- vrstvy ----------
  const elLabel = (e) => e.name || (e.type === 'text' ? (e.text.split('\n')[0].slice(0, 28) || 'Text') : e.type === 'rect' ? 'Obdélník'
    : e.type === 'balls' ? `Kuličky ${(e.src.match(/:([^|}]+)/)?.[1] || e.src).slice(0, 20)}`
    : e.type === 'weather' ? `Počasí ${e.place || WEATHER?.place || ''}`.trim() : (mediaById(e.mediaId)?.original_name || 'Obrázek'));
  const elIcon = { text: 'T', rect: '▭', image: '🖼', balls: '◉', weather: '☀' };
  function drawLayers() {
    const box = $('#layerList');
    if (!box) return;
    const list = [...d.elements].reverse();
    box.innerHTML = `<div class="layer ${selId === null ? 'sel' : ''}" data-bg="1"><span class="li-ic">▦</span><span class="grow">Pozadí</span></div>`
      + list.map((e) => `<div class="layer ${e.id === selId ? 'sel' : ''} ${e.hidden ? 'off' : ''}" data-id="${e.id}">
        <span class="li-ic">${elIcon[e.type]}</span><span class="grow nm">${esc(elLabel(e))}</span>${(e.showIf?.length || e.variants?.length) ? '<span class="li-cond" title="Má podmínky">⚑</span>' : ''}
        <button class="lb" data-up="${e.id}" title="Výš">↑</button><button class="lb" data-down="${e.id}" title="Níž">↓</button>
        <button class="lb" data-eye="${e.id}" title="${e.hidden ? 'Zobrazit' : 'Skrýt'}">${e.hidden ? '◌' : '●'}</button></div>`).join('');
    $$('#layerList .layer').forEach((l) => l.onclick = (ev) => {
      if (ev.target.closest('.lb')) return;
      selId = l.dataset.bg ? null : l.dataset.id; drawLayers(); drawProps(); drawOverlay();
    });
    const idx = (id) => d.elements.findIndex((e) => e.id === id);
    $$('[data-up]').forEach((b) => b.onclick = () => { const i = idx(b.dataset.up); if (i < d.elements.length - 1) { [d.elements[i], d.elements[i + 1]] = [d.elements[i + 1], d.elements[i]]; change(); drawLayers(); } });
    $$('[data-down]').forEach((b) => b.onclick = () => { const i = idx(b.dataset.down); if (i > 0) { [d.elements[i], d.elements[i - 1]] = [d.elements[i - 1], d.elements[i]]; change(); drawLayers(); } });
    $$('[data-eye]').forEach((b) => b.onclick = () => { const e = d.elements[idx(b.dataset.eye)]; e.hidden = !e.hidden; change(); drawLayers(); });
  }

  // ---------- vlastnosti ----------
  const num = (id, label, v, min, max, step = 1) => `<label class="field fsm">${label}<input type="number" id="${id}" value="${v}" min="${min}" max="${max}" step="${step}"></label>`;
  const col = (id, label, v) => `<label class="field fsm">${label}<input type="color" id="${id}" value="${v}"></label>`;
  const rng = (id, label, v, min, max, step) => `<label class="field fsm">${label} <span class="hint" id="${id}V">${Math.round(v * 100)} %</span><input type="range" id="${id}" value="${v}" min="${min}" max="${max}" step="${step}"></label>`;
  const seg = (id, opts, v) => `<div class="seg" id="${id}">${opts.map(([k, l]) => `<button data-v="${k}" class="${String(v) === String(k) ? 'on' : ''}">${l}</button>`).join('')}</div>`;
  const chk = (id, label, v) => `<label class="check-row small"><input type="checkbox" id="${id}" ${v ? 'checked' : ''}><span>${label}</span></label>`;
  const posFields = (e) => `<div class="cols4">${num('pX', 'X', e.x, -SW, 2 * SW)}${num('pY', 'Y', e.y, -SH, 2 * SH)}${num('pW', 'Šířka', e.w, 20, 4 * SW)}${num('pH', 'Výška', e.h, 20, 4 * SH)}</div>`;
  function syncPos() {
    const e = sel(); if (!e) return;
    [['pX', 'x'], ['pY', 'y'], ['pW', 'w'], ['pH', 'h']].forEach(([id, k]) => { const i = $('#' + id); if (i && document.activeElement !== i) i.value = e[k]; });
  }
  // ---------- grafický styl a rozestup písmen ----------
  const SPACINGS = [[-0.08, 'Velmi úzký'], [-0.04, 'Užší'], [0, 'Normální'], [0.05, 'Širší'], [0.12, 'Široký']];
  const spacingSel = (e) => {
    const cur = SPACINGS.reduce((b, x) => (Math.abs(x[0] - (e.spacing || 0)) < Math.abs(b[0] - (e.spacing || 0)) ? x : b))[0];
    return `<select id="pSpacing">${SPACINGS.map(([v, l]) => `<option value="${v}" ${v === cur ? 'selected' : ''}>${l}</option>`).join('')}</select>`;
  };
  const fxTile = (key, st) => {
    const on = (e) => (e.fx?.style || '') === key;
    const prev = st ? `<span class="fx-prev" style="background:linear-gradient(${st.grad.join(',')});-webkit-text-stroke:${st.stroke2 ? 2 : 1.5}px ${st.stroke2 ? st.stroke2Color : st.strokeColor}">137</span>` : '<span class="fx-prev none">Aa</span>';
    return (e) => `<button type="button" data-fx="${key}" class="fx-tile ${st?.light ? 'light' : ''} ${on(e) ? 'on' : ''}" title="${esc(st ? st.name + (st.light ? ' (pro světlé pozadí)' : '') : 'Bez grafického stylu')}">${prev}<small>${esc(st ? st.name : 'Žádný')}${st?.light ? ' ☀' : ''}</small></button>`;
  };
  const fxTiles = (e) => [fxTile('', null)(e), ...Object.entries(FX.styles).map(([k, st]) => fxTile(k, st)(e))].join('');
  let fxTuneOpen = false;
  const fxTune = (e) => {
    const f = e.fx, st = FX.styles[f.style] || {};
    const r = (k, label, max, colorKey) => `<div class="fx-row">${rng('fxR_' + k, label, f[k], 0, max, max / 100)}${colorKey ? col('fxC_' + colorKey, '&nbsp;', f[colorKey]) : '<span></span>'}</div>`;
    return `<details id="fxTune" ${fxTuneOpen ? 'open' : ''}><summary>Doladit styl ${esc(st.name || '')}</summary>
      <div class="field fsm">Přechod (shora dolů)<div class="fx-grad">${f.grad.map((c, i) => `<input type="color" id="fxG${i}" value="${c}" title="${['Nahoře', 'Výš uprostřed', 'Níž uprostřed', 'Dole'][i]}">`).join('')}</div></div>
      ${r('stroke', 'Obrys', FX.ranges.stroke?.[1] ?? 0.15, 'strokeColor')}${r('stroke2', 'Druhý obrys', FX.ranges.stroke2?.[1] ?? 0.08, 'stroke2Color')}
      ${r('depth', '3D hloubka', FX.ranges.depth?.[1] ?? 0.25, 'depthColor')}${r('glow', 'Záře', 1, 'glowColor')}${r('shadow', 'Stín', 1)}${r('gloss', 'Lesk', 1)}
      <button class="btn sm" id="fxReset" type="button">Vrátit styl ${esc(st.name || '')}</button></details>`;
  };
  const setFx = (e, key) => { e.fx = key && FX.styles[key] ? { style: key, ...JSON.parse(JSON.stringify(FX.styles[key])) } : null; if (e.fx) { delete e.fx.name; delete e.fx.light; } };

  // připojení vstupu k vlastnosti (cesta „shadow.on“ apod.)
  function bind(id, path, conv = (v) => v, ev = 'input') {
    const el = $('#' + id); if (!el) return;
    el.addEventListener(ev, () => {
      const target = path.startsWith('bg.') && !sel() ? d : sel() || d;
      const keys = path.split('.'); let o = target; keys.slice(0, -1).forEach((k) => { o = o[k]; });
      o[keys.at(-1)] = conv(el.type === 'checkbox' ? el.checked : el.value);
      const vv = $('#' + id + 'V'); if (vv) vv.textContent = `${Math.round(el.value * 100)} %`;
      if (path === 'text') resolveTexts();
      if (path === 'font' || path === 'weight') { const e = sel(); ensureFont(e.font, e.weight).then(redraw); if (path === 'font') drawProps(); }
      dirty = true; commit(400); redraw(); if (path === 'text' || path === 'name') drawLayers();
    });
  }
  function bindSeg(id, path, conv = (v) => v, after) {
    $$(`#${id} button`).forEach((b) => b.onclick = () => {
      const target = sel() || d;
      const keys = path.split('.'); let o = target; keys.slice(0, -1).forEach((k) => { o = o[k]; });
      o[keys.at(-1)] = conv(b.dataset.v);
      $$(`#${id} button`).forEach((x) => x.classList.toggle('on', x === b));
      change(); after?.();
    });
  }
  const N = (v) => +v;

  function drawProps() {
    const P = $('#props');
    if (mobile) return drawMobileProps(P);
    const e = sel();
    if (!e) {
      const bg = d.bg;
      P.innerHTML = `<h3>Pozadí</h3>
        ${seg('bgType', [['color', 'Barva'], ['gradient', 'Přechod'], ['image', 'Obrázek']], bg.type)}
        <div class="cols2">${col('bgC1', bg.type === 'gradient' ? 'Barva 1' : 'Barva', bg.color)}${bg.type === 'gradient' ? col('bgC2', 'Barva 2', bg.color2) : ''}</div>
        ${bg.type === 'gradient' ? `<div class="field fsm">Směr ${seg('bgDir', [['v', 'Shora dolů'], ['h', 'Zleva doprava']], bg.dir)}</div>` : ''}
        ${bg.type === 'image' ? `<div class="bg-pick">${bg.mediaId && mediaById(bg.mediaId) ? `<div class="mini">${thumb(mediaById(bg.mediaId))}</div>` : ''}
            <button class="btn sm" id="bgPick">${bg.mediaId ? 'Změnit obrázek' : 'Vybrat obrázek'}</button></div>
          <div class="field fsm">Umístění ${seg('bgFit', [['cover', 'Vyplnit'], ['contain', 'Celý obrázek']], bg.fit)}</div>` : ''}
        ${rng('bgDim', 'Ztmavení', bg.dim, 0, 0.9, 0.05)}
        <p class="hint small">Tip: ztmavení pozadí zlepší čitelnost textu na fotce.</p>`;
      bindSeg('bgType', 'type', String, drawProps);
      bindSeg('bgDir', 'dir'); bindSeg('bgFit', 'fit');
      bind('bgC1', 'bg.color'); bind('bgC2', 'bg.color2'); bind('bgDim', 'bg.dim', N);
      // pozadí není „sel()“, takže cesty bez předpony bg. míří do d.bg
      ['bgType', 'bgDir', 'bgFit'].forEach((id) => $$(`#${id} button`).forEach((b) => {
        const k = { bgType: 'type', bgDir: 'dir', bgFit: 'fit' }[id];
        b.onclick = () => { d.bg[k] = b.dataset.v; change(); drawProps(); imgWatch(); };
      }));
      $('#bgPick')?.addEventListener('click', () => pickImage('Obrázek pozadí', (id) => { d.bg.mediaId = id; imgWatch(); change(); drawProps(); }));
      return;
    }
    const condBox = `<details class="cond-sec" ${(e.showIf.length || e.variants?.length) ? 'open' : ''}><summary>Podmínky ${(e.showIf.length || e.variants?.length) ? '⚑' : ''}</summary>
        <div class="cond-state" id="condState"></div>
        <div class="field fsm">Zobrazit jen když <span class="hint">– všechny podmínky musí platit; prázdné = vždy</span><div id="showIfBox"></div></div>
        ${e.type === 'text' ? `<div class="field fsm">Varianty textu <span class="hint">– použije se první, jejíž podmínky platí; jinak text nahoře</span><div id="variantsBox"></div>
          <button class="btn sm" id="addVariant">+ Varianta textu</button></div>` : ''}
      </details>`;
    const common = `${condBox}<h4>Umístění</h4>${posFields(e)}${rng('pA', 'Krytí', e.alpha, 0, 1, 0.05)}
      <div class="row" style="margin-top:8px"><button class="btn sm" id="pDup">Duplikovat</button><button class="btn sm danger" id="pDel">Smazat</button>
      ${e.type !== 'rect' ? '' : ''}<span class="grow"></span>
      <button class="btn sm ghost" id="pCH" title="Vycentrovat vodorovně">↔ střed</button><button class="btn sm ghost" id="pCV" title="Vycentrovat svisle">↕ střed</button></div>`;
    if (e.type === 'text') {
      const f = fontDef(e.font);
      P.innerHTML = `<h3>Text</h3>
        <label class="field fsm">Obsah <textarea id="pText" rows="3">${esc(e.text)}</textarea></label>
        <div class="warnbox small" id="unk" hidden></div>
        <div class="row" style="gap:6px;flex-wrap:wrap"><select id="pVar" style="flex:1 1 100%"><option value="">+ Vložit proměnnou…</option><optgroup label="Kalendář">${VARS.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</optgroup>${WEATHER?.key_set ? `<optgroup label="Počasí${WEATHER.place ? ` (${esc(WEATHER.place)})` : ''}">${WEATHER_VARS.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</optgroup>` : ''}${dataVarOptions()}</select>
          ${SOURCES.length ? '<button class="btn sm" id="pSrc" title="Hodnota ze vzdáleného zdroje (např. Allwyn)">Ze zdroje…</button>' : ''}
          <button class="btn sm" id="pDate" title="Formát data: změní proměnnou, ve které je kurzor, jinak vloží dnešní datum">Formát data…</button>
          <button class="btn sm" id="pNum" title="Formát čísla: změní proměnnou, ve které je kurzor">Formát čísla…</button>
          <span class="grow"></span><button class="btn sm ghost" id="pHelp" title="Nápověda k proměnným a formátování">?</button></div>
        <div class="cols2"><label class="field fsm">Písmo<select id="pFont">${FONT_LIST.map((x) => `<option value="${x.key}" ${x.key === e.font ? 'selected' : ''} style="font-family:'mp-${x.key}'">${esc(x.name)}</option>`).join('')}</select></label>
          <label class="field fsm">Tloušťka<select id="pWeight">${f.weights.map((w) => `<option value="${w}" ${w === nearestWeight(e.font, e.weight) ? 'selected' : ''}>${{ 400: 'Normální', 700: 'Tučné', 900: 'Extra tučné' }[w]}</option>`).join('')}</select></label></div>
        <div class="cols2">${num('pSize', 'Velikost (px)', e.size, 8, 600)}${e.fx ? `<label class="field fsm">Rozestup písmen${spacingSel(e)}</label>` : col('pColor', 'Barva', e.color)}</div>
        ${e.fx ? '' : `<div class="cols2"><label class="field fsm">Rozestup písmen${spacingSel(e)}</label><div></div></div>`}
        <div class="field fsm">Grafický styl <span class="hint">– ☀ = pro světlé pozadí</span><div class="fx-tiles" id="pFx">${fxTiles(e)}</div></div>
        ${e.fx ? fxTune(e) : ''}
        <div class="field fsm">Zarovnání ${seg('pAlign', [['left', '⇤ Vlevo'], ['center', '↔ Na střed'], ['right', 'Vpravo ⇥']], e.align)}</div>
        <div class="field fsm">Svisle ${seg('pVAlign', [['top', 'Nahoru'], ['middle', 'Na střed'], ['bottom', 'Dolů']], e.valign)}</div>
        <div class="cols2">${num('pLH', 'Řádkování', e.lineHeight, 0.7, 3, 0.05)}<div></div></div>
        ${chk('pUpper', 'VELKÁ PÍSMENA', e.upper)}
        ${chk('pFit', 'Zmenšit text, aby se vešel do pole', e.autoFit)}
        ${e.fx ? '' : `<details ${e.shadow.on ? 'open' : ''}><summary>Stín</summary>${chk('pShOn', 'Zapnout stín', e.shadow.on)}
          <div class="cols4">${col('pShC', 'Barva', e.shadow.color)}${num('pShX', 'Posun X', e.shadow.dx, -60, 60)}${num('pShY', 'Posun Y', e.shadow.dy, -60, 60)}<div></div></div>${rng('pShA', 'Krytí', e.shadow.alpha, 0, 1, 0.05)}</details>
        <details ${e.outline.on ? 'open' : ''}><summary>Obrys</summary>${chk('pOlOn', 'Zapnout obrys', e.outline.on)}
          <div class="cols2">${col('pOlC', 'Barva', e.outline.color)}${num('pOlW', 'Šířka', e.outline.width, 1, 30)}</div></details>`}
        <details ${e.bg.on ? 'open' : ''}><summary>Podklad pod textem</summary>${chk('pBgOn', 'Zapnout podklad', e.bg.on)}
          <div class="cols4">${col('pBgC', 'Barva', e.bg.color)}${num('pBgP', 'Odsazení', e.bg.padding, 0, 300)}${num('pBgR', 'Zaoblení', e.bg.radius, 0, 500)}<div></div></div>${rng('pBgA', 'Krytí', e.bg.alpha, 0, 1, 0.05)}</details>
        ${common}`;
      bind('pText', 'text'); bind('pFont', 'font', String, 'change'); bind('pWeight', 'weight', N, 'change');
      bind('pSize', 'size', N); bind('pColor', 'color'); bind('pLH', 'lineHeight', N);
      bind('pUpper', 'upper', Boolean, 'change'); bind('pFit', 'autoFit', Boolean, 'change');
      bind('pShOn', 'shadow.on', Boolean, 'change'); bind('pShC', 'shadow.color'); bind('pShX', 'shadow.dx', N); bind('pShY', 'shadow.dy', N); bind('pShA', 'shadow.alpha', N);
      bind('pOlOn', 'outline.on', Boolean, 'change'); bind('pOlC', 'outline.color'); bind('pOlW', 'outline.width', N);
      bind('pBgOn', 'bg.on', Boolean, 'change'); bind('pBgC', 'bg.color'); bind('pBgP', 'bg.padding', N); bind('pBgR', 'bg.radius', N); bind('pBgA', 'bg.alpha', N);
      bindSeg('pAlign', 'align'); bindSeg('pVAlign', 'valign');
      bind('pSpacing', 'spacing', N, 'change');
      $$('#pFx [data-fx]').forEach((b) => { b.onclick = () => { setFx(e, b.dataset.fx); change(); drawProps(); }; });
      if (e.fx) {
        [0, 1, 2, 3].forEach((i) => bind('fxG' + i, `fx.grad.${i}`));
        ['stroke', 'stroke2', 'depth', 'glow', 'shadow', 'gloss'].forEach((k) => bind('fxR_' + k, 'fx.' + k, N));
        ['strokeColor', 'stroke2Color', 'depthColor', 'glowColor'].forEach((k) => bind('fxC_' + k, 'fx.' + k));
        $('#fxTune').ontoggle = (ev) => { fxTuneOpen = ev.target.open; };
        $('#fxReset').onclick = () => { setFx(e, e.fx.style); change(); drawProps(); };
      }
      const ta = $('#pText');
      let caret = [ta.value.length, ta.value.length];
      ta.addEventListener('blur', () => { caret = [ta.selectionStart ?? ta.value.length, ta.selectionEnd ?? ta.value.length]; });
      const insertVar = (v) => {
        const [s0, s1] = document.activeElement === ta ? [ta.selectionStart, ta.selectionEnd] : caret;
        ta.value = ta.value.slice(0, s0) + v + ta.value.slice(s1);
        ta.dispatchEvent(new Event('input'));
        ta.focus(); ta.selectionStart = ta.selectionEnd = s0 + v.length;
      };
      const insertAny = (v) => {
        if (v.startsWith('@date:')) { const x = v.slice(6); pickDateFormat(x, '', (f) => insertVar(`{${x}${f}}`)); }
        else if (v.startsWith('@num:')) { const x = v.slice(5); pickNumFormat(x, [], (f) => insertVar(`{${x}${f}}`)); }
        else insertVar(v);
      };
      $('#pVar').onchange = (ev) => { const v = ev.target.value; ev.target.value = ''; if (v) insertAny(v); };
      $('#pSrc')?.addEventListener('click', () => pickSource('text', insertAny));
      // proměnná, ve které je kurzor (nebo hned za její „}“): {open, close, base, filters}, jinak null
      const varAtCaret = () => {
        const pos = document.activeElement === ta ? ta.selectionStart : caret[0];
        const val = ta.value, open = pos > 0 ? val.lastIndexOf('{', pos - 1) : -1, close = open >= 0 ? val.indexOf('}', open) : -1;
        if (!(open >= 0 && close >= 0 && close + 1 >= pos && !val.slice(open + 1, close).includes('{'))) return null;
        const parts = val.slice(open + 1, close).split('|');
        return { open, close, base: parts[0].trim(), filters: parts.slice(1).filter(Boolean) };
      };
      // nahradí filtry proměnné: odstraní ty, pro které platí drop(), a nové vloží hned za název
      const replaceVar = (v, drop, f) => {
        const rest = v.filters.filter((x) => !drop(x));
        const nv = `{${v.base}${f}${rest.map((x) => `|${x}`).join('')}}`;
        ta.value = ta.value.slice(0, v.open) + nv + ta.value.slice(v.close + 1);
        ta.dispatchEvent(new Event('input'));
        ta.focus(); ta.selectionStart = ta.selectionEnd = v.open + nv.length;
      };
      // „Formát data…“: kurzor uvnitř {proměnné} → změní její formát, jinak vloží dnešní datum
      $('#pDate').onclick = () => {
        const v = varAtCaret();
        if (!v) { pickDateFormat('datum', '', (f) => insertVar(`{datum${f}}`)); return; }
        const cur = dateFilterOf(v.filters);
        pickDateFormat(v.base, cur ? `|${cur}` : '', (f) => replaceVar(v, (x) => x === cur, f));
      };
      // „Formát čísla…“: jen pro proměnnou, ve které je kurzor
      $('#pNum').onclick = () => {
        const v = varAtCaret();
        if (!v) { toast('Klikněte do proměnné s číslem, např. {Sportka.nejbližší}, nebo ji vložte přes „Vložit proměnnou…“.', 'warn'); return; }
        pickNumFormat(v.base, v.filters.filter(isNumFilter), (f) => replaceVar(v, isNumFilter, f));
      };
      showUnknown();
    } else if (e.type === 'balls') {
      const f = fontDef(e.font);
      const srcRow = (id, label, hint, v) => `<label class="field fsm">${label} <span class="hint">${hint}</span>
        <div class="row" style="gap:6px"><input type="text" id="${id}" class="grow" value="${esc(v)}" placeholder="např. 3 15 27 nebo {Zdroj:hra.cisla}">
        ${SOURCES.length ? `<button class="btn sm" data-pick="${id}">Ze zdroje…</button>` : ''}</div></label>`;
      P.innerHTML = `<h3>Kuličky</h3>
        ${srcRow('pBSrc', 'Čísla', '', e.src)}
        ${srcRow('pBExtra', 'Dodatková čísla', '– jiná barva, za mezerou; nepovinné', e.extra)}
        <div class="hint small" id="bNums"></div>
        <div class="warnbox small" id="unk" hidden></div>
        <div class="field fsm">Vzhled ${seg('pStyle', [['glossy', 'Plastické'], ['flat', 'Ploché']], e.style)}</div>
        <div class="cols4">${col('pFill', 'Kulička', e.fill)}${col('pColor', 'Číslo', e.color)}${col('pXFill', 'Dodatková', e.extraFill)}${col('pXColor', 'Její číslo', e.extraColor)}</div>
        <div class="cols4">${num('pBSize', 'Průměr', e.size, 16, 400)}${num('pBGap', 'Mezera', e.gap, 0, 300)}${num('pBXGap', 'Před dodatk.', e.extraGap, 0, 600)}${num('pNum', 'Číslo %', Math.round(e.numScale * 100), 20, 90)}</div>
        <div class="cols2"><label class="field fsm">Písmo<select id="pFont">${FONT_LIST.map((x) => `<option value="${x.key}" ${x.key === e.font ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label>
          <label class="field fsm">Tloušťka<select id="pWeight">${f.weights.map((w) => `<option value="${w}" ${w === nearestWeight(e.font, e.weight) ? 'selected' : ''}>${{ 400: 'Normální', 700: 'Tučné', 900: 'Extra tučné' }[w]}</option>`).join('')}</select></label></div>
        <div class="field fsm">Zarovnání ${seg('pAlign', [['left', '⇤ Vlevo'], ['center', '↔ Na střed'], ['right', 'Vpravo ⇥']], e.align)}</div>
        <div class="field fsm">Svisle ${seg('pVAlign', [['top', 'Nahoru'], ['middle', 'Na střed'], ['bottom', 'Dolů']], e.valign)}</div>
        <details ${e.stroke.on ? 'open' : ''}><summary>Obrys kuliček</summary>${chk('pStOn', 'Zapnout obrys', e.stroke.on)}
          <div class="cols2">${col('pStC', 'Barva', e.stroke.color)}${num('pStW', 'Šířka', e.stroke.width, 1, 40)}</div></details>
        <p class="hint small">Čísla se řadí zleva doprava a podle šířky rámečku se zalomí do dalších řádků. Když se nevejdou, kuličky se zmenší.</p>
        ${common}`;
      bind('pBSrc', 'src'); bind('pBExtra', 'extra');
      bind('pFill', 'fill'); bind('pColor', 'color'); bind('pXFill', 'extraFill'); bind('pXColor', 'extraColor');
      bind('pBSize', 'size', N); bind('pBGap', 'gap', N); bind('pBXGap', 'extraGap', N); bind('pNum', 'numScale', (v) => v / 100);
      bind('pFont', 'font', String, 'change'); bind('pWeight', 'weight', N, 'change');
      bind('pStOn', 'stroke.on', Boolean, 'change'); bind('pStC', 'stroke.color'); bind('pStW', 'stroke.width', N);
      bindSeg('pStyle', 'style'); bindSeg('pAlign', 'align'); bindSeg('pVAlign', 'valign');
      $$('[data-pick]').forEach((b) => b.onclick = () => pickSource('balls', (v) => {
        const inp = $('#' + b.dataset.pick); inp.value = v; inp.dispatchEvent(new Event('input'));
      }));
      showUnknown(); showBallNums();
    } else if (e.type === 'weather') {
      const f = fontDef(e.font);
      P.innerHTML = `<h3>Počasí</h3>
        <div class="field fsm">Vzhled<div class="w-looks" id="pLook">${WEATHER_LOOKS.map(([k, l]) => `<button type="button" data-look="${k}" class="w-look ${e.look === k ? 'on' : ''}">${WEATHER_PREV[k]}<small>${l}</small></button>`).join('')}</div></div>
        <label class="field fsm">Místo <span class="hint">– prázdné = výchozí${WEATHER?.place ? ` (${esc(WEATHER.place)})` : ''}</span>
          <input type="text" id="pPlace" value="${esc(e.place)}" placeholder="${esc(WEATHER?.place || 'např. Praha nebo Brno,CZ')}" maxlength="100"></label>
        <div id="wState" class="small hint"></div>
        <div class="cols2"><label class="field fsm">Písmo<select id="pFont">${FONT_LIST.map((x) => `<option value="${x.key}" ${x.key === e.font ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label>
          <label class="field fsm">Tloušťka<select id="pWeight">${f.weights.map((w) => `<option value="${w}" ${w === nearestWeight(e.font, e.weight) ? 'selected' : ''}>${{ 400: 'Normální', 700: 'Tučné', 900: 'Extra tučné' }[w]}</option>`).join('')}</select></label></div>
        <div class="cols2">${col('pColor', 'Barva textu', e.color)}<div></div></div>
        ${e.look === 'days' ? '' : `<div class="field fsm">Zarovnání ${seg('pAlign', [['left', '⇤ Vlevo'], ['center', '↔ Na střed'], ['right', 'Vpravo ⇥']], e.align)}</div>`}
        <details ${e.bg.on ? 'open' : ''}><summary>Podklad</summary>${chk('pBgOn', 'Zapnout podklad', e.bg.on)}
          <div class="cols4">${col('pBgC', 'Barva', e.bg.color)}${num('pBgP', 'Odsazení', e.bg.padding, 0, 300)}${num('pBgR', 'Zaoblení', e.bg.radius, 0, 500)}<div></div></div>${rng('pBgA', 'Krytí', e.bg.alpha, 0, 1, 0.05)}</details>
        <details ${e.shadow.on ? 'open' : ''}><summary>Stín textu</summary>${chk('pShOn', 'Zapnout stín', e.shadow.on)}
          <div class="cols4">${col('pShC', 'Barva', e.shadow.color)}${num('pShX', 'Posun X', e.shadow.dx, -60, 60)}${num('pShY', 'Posun Y', e.shadow.dy, -60, 60)}<div></div></div>${rng('pShA', 'Krytí', e.shadow.alpha, 0, 1, 0.05)}</details>
        <p class="hint small">Velikost ikony a písma se řídí velikostí rámečku. Jednotlivé údaje můžete vložit i do textu přes <b>+ Vložit proměnnou…</b> → Počasí.</p>
        ${common}`;
      $$('#pLook [data-look]').forEach((b) => b.onclick = () => {
        const [, , def] = WEATHER_LOOKS.find(([k]) => k === b.dataset.look);
        if (e.look === b.dataset.look) return;
        e.look = b.dataset.look; e.w = def.w; e.h = def.h; e.bg.on = def.bg;
        e.x = Math.max(0, Math.min(SW - e.w, e.x)); e.y = Math.max(0, Math.min(SH - e.h, e.y));
        change(); drawProps(); drawOverlay();
      });
      // místo se uloží až po dopsání (Enter nebo opuštění pole), ne po každém písmenu
      bind('pPlace', 'place', (v) => v.trim(), 'change');
      $('#pPlace').addEventListener('change', drawLayers);
      $('#pPlace').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') ev.target.blur(); });
      bind('pFont', 'font', String, 'change'); bind('pWeight', 'weight', N, 'change'); bind('pColor', 'color');
      bindSeg('pAlign', 'align');
      bind('pBgOn', 'bg.on', Boolean, 'change'); bind('pBgC', 'bg.color'); bind('pBgP', 'bg.padding', N); bind('pBgR', 'bg.radius', N); bind('pBgA', 'bg.alpha', N);
      bind('pShOn', 'shadow.on', Boolean, 'change'); bind('pShC', 'shadow.color'); bind('pShX', 'shadow.dx', N); bind('pShY', 'shadow.dy', N); bind('pShA', 'shadow.alpha', N);
      showWeatherState();
    } else if (e.type === 'rect') {
      P.innerHTML = `<h3>Obdélník</h3><div class="cols2">${col('pColor', 'Barva', e.color)}${num('pR', 'Zaoblení', e.radius, 0, 1000)}</div>${common}`;
      bind('pColor', 'color'); bind('pR', 'radius', N);
    } else {
      const mm = mediaById(e.mediaId);
      P.innerHTML = `<h3>Obrázek</h3>
        <div class="bg-pick">${mm ? `<div class="mini">${thumb(mm)}</div>` : '<span class="hint">Není vybrán</span>'}<button class="btn sm" id="pPick">${mm ? 'Změnit' : 'Vybrat'}</button></div>
        <div class="field fsm">Umístění ${seg('pFitI', [['contain', 'Celý obrázek'], ['cover', 'Vyplnit pole']], e.fit)}</div>
        <button class="btn sm ghost" id="pRatio">Výška podle poměru stran obrázku</button>
        ${common}`;
      bindSeg('pFitI', 'fit');
      $('#pPick').onclick = () => pickImage('Obrázek', (id) => { e.mediaId = id; imgWatch(); change(); drawProps(); drawLayers(); });
      $('#pRatio').onclick = () => { const im = loadImg(e.mediaId); if (im) { e.h = Math.round(e.w * im.naturalHeight / im.naturalWidth); change(); syncPos(); } };
    }
    bind('pX', 'x', N); bind('pY', 'y', N); bind('pW', 'w', N); bind('pH', 'h', N); bind('pA', 'alpha', N);
    $('#pHelp')?.addEventListener('click', filterHelp);
    clauseEditor($('#showIfBox'), e.showIf);
    if (e.type === 'text') drawVariants(e);
    $('#addVariant')?.addEventListener('click', () => { e.variants.push({ when: [{ l: '', op: '>', r: '' }], text: e.text }); condChanged(); drawVariants(e); });
    showCondState();
    $('#pDup').onclick = () => duplicate();
    $('#pDel').onclick = () => removeSel();
    $('#pCH').onclick = () => { e.x = Math.round((SW - e.w) / 2); change(); syncPos(); };
    $('#pCV').onclick = () => { e.y = Math.round((SH - e.h) / 2); change(); syncPos(); };
  }

  // ---------- podmínky ----------
  const OPS = [['=', '='], ['!=', '≠'], ['>', '>'], ['<', '<'], ['>=', '≥'], ['<=', '≤'], ['contains', 'obsahuje'], ['empty', 'je prázdné'], ['notempty', 'není prázdné']];
  function condChanged() { dirty = true; commit(400); redraw(); drawLayers(); }
  function clauseEditor(box, list) {
    if (!box) return;
    const dl = `condVars${Math.random().toString(36).slice(2, 7)}`;
    box.innerHTML = `<datalist id="${dl}">${COND_VARS().map((v) => `<option value="${esc(v)}">`).join('')}</datalist>
      ${list.map((c, i) => `<div class="clause" data-i="${i}">
        <input type="text" data-k="l" value="${esc(c.l)}" list="${dl}" placeholder="např. Sportka.nejbližší">
        <select data-k="op">${OPS.map(([k, l]) => `<option value="${k}" ${c.op === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <input type="text" data-k="r" value="${esc(c.r)}" placeholder="hodnota" ${['empty', 'notempty'].includes(c.op) ? 'hidden' : ''}>
        <button class="x" data-rmc="${i}" title="Odebrat podmínku">×</button></div>`).join('')}
      <button class="btn sm ghost" data-addc="1">+ podmínka</button>`;
    box.querySelectorAll('.clause').forEach((row) => row.querySelectorAll('[data-k]').forEach((inp) => {
      inp.addEventListener(inp.tagName === 'SELECT' ? 'change' : 'input', () => {
        list[+row.dataset.i][inp.dataset.k] = inp.value;
        if (inp.dataset.k === 'op') row.querySelector('[data-k="r"]').hidden = ['empty', 'notempty'].includes(inp.value);
        condChanged();
      });
    }));
    box.querySelectorAll('[data-rmc]').forEach((b) => b.onclick = () => { list.splice(+b.dataset.rmc, 1); condChanged(); clauseEditor(box, list); });
    box.querySelector('[data-addc]').onclick = () => { list.push({ l: '', op: '>', r: '' }); clauseEditor(box, list); };
  }
  function drawVariants(e) {
    const box = $('#variantsBox');
    if (!box) return;
    box.innerHTML = e.variants.map((v, i) => `<div class="variant" data-v="${i}">
      <div class="row" style="gap:6px"><b class="grow small">Varianta ${i + 1}</b>
        <button class="lb" data-vup="${i}" title="Výš">↑</button><button class="lb" data-vdown="${i}" title="Níž">↓</button>
        <button class="x" data-vrm="${i}" title="Odebrat variantu">×</button></div>
      <div class="hint small">Když platí:</div><div class="vclauses"></div>
      <div class="hint small">Text:</div><textarea rows="2" data-vtext="${i}">${esc(v.text)}</textarea></div>`).join('');
    box.querySelectorAll('.variant').forEach((el) => clauseEditor(el.querySelector('.vclauses'), e.variants[+el.dataset.v].when));
    box.querySelectorAll('[data-vtext]').forEach((ta) => ta.oninput = () => { e.variants[+ta.dataset.vtext].text = ta.value; condChanged(); });
    box.querySelectorAll('[data-vrm]').forEach((b) => b.onclick = () => { e.variants.splice(+b.dataset.vrm, 1); condChanged(); drawVariants(e); });
    const mv = (i, d2) => { const j = i + d2; if (j < 0 || j >= e.variants.length) return; [e.variants[i], e.variants[j]] = [e.variants[j], e.variants[i]]; condChanged(); drawVariants(e); };
    box.querySelectorAll('[data-vup]').forEach((b) => b.onclick = () => mv(+b.dataset.vup, -1));
    box.querySelectorAll('[data-vdown]').forEach((b) => b.onclick = () => mv(+b.dataset.vdown, 1));
  }
  function showCondState() {
    const box = $('#condState'), e = sel();
    if (!box || !e) return;
    const r = texts[e.id];
    if (!r || (!e.showIf.length && !e.variants?.length)) { box.innerHTML = ''; return; }
    const parts = [r.visible === false ? '<b class="c-off">Teď skryto</b> – podmínka zobrazení neplatí' : '<b class="c-on">Teď zobrazeno</b>'];
    if (e.type === 'text' && e.variants?.length) parts.push(r.variant >= 0 ? `platí <b>varianta ${r.variant + 1}</b>` : 'žádná varianta neplatí – použije se text nahoře');
    box.innerHTML = parts.join(' · ');
    $$('#variantsBox .variant').forEach((el) => el.classList.toggle('active', +el.dataset.v === r.variant));
  }

  // mobil: jen úprava textů
  function drawMobileProps(P) {
    const tx = d.elements.filter((e) => e.type === 'text');
    P.innerHTML = tx.length ? `<h3>Texty</h3>${tx.map((e) => `<label class="field fsm">${esc(e.name || 'Text')}
      <textarea data-tid="${e.id}" rows="2">${esc(e.text)}</textarea></label>`).join('')}` : '<p class="hint">Slide nemá žádné texty.</p>';
    $$('[data-tid]').forEach((ta) => ta.oninput = () => {
      d.elements.find((e) => e.id === ta.dataset.tid).text = ta.value; dirty = true; resolveTexts(); redraw();
      const w = ta.parentElement.querySelector('.emoji-w'); const has = EMOJI_RE.test(ta.value); EMOJI_RE.lastIndex = 0;
      if (has && !w) ta.insertAdjacentHTML('afterend', '<span class="hint emoji-w" style="color:var(--warn)">Emoji se ve slidu nezobrazí.</span>');
      if (!has && w) w.remove();
    });
  }

  // ---------- přidání / odebrání ----------
  function add(e) {
    d.elements.push(e); selId = e.id;
    if (['text', 'balls', 'weather'].includes(e.type)) ensureFont(e.font, e.weight).then(redraw);
    change(); resolveTexts(); drawLayers(); drawProps();
  }
  function duplicate() {
    const e = sel(); if (!e) return;
    const c = JSON.parse(JSON.stringify(e)); c.id = uid(); c.x += 30; c.y += 30;
    d.elements.splice(d.elements.indexOf(e) + 1, 0, c); selId = c.id;
    change(); resolveTexts(); drawLayers(); drawProps();
  }
  function removeSel() {
    const e = sel(); if (!e) return;
    d.elements = d.elements.filter((x) => x !== e); selId = null;
    change(); drawLayers(); drawProps();
  }
  $('#addText')?.addEventListener('click', () => add(sc(newText())));
  $('#addBalls')?.addEventListener('click', () => {
    // výchozí čísla: první seznam ze zdroje (a k němu dodatkové číslo, je-li)
    const over = {};
    for (const s of SOURCES) {
      const items = srcItems(s), li = items.find((i) => i.kind === 'list' && !i.tech);
      if (!li) continue;
      over.src = `{${s.name}:${li.path}}`;
      const ex = items.find((i) => i.group === li.group && (i.key === `${li.key}_d` || i.key === 'dodatkove_cislo'));
      if (ex) over.extra = `{${s.name}:${ex.path}}`;
      break;
    }
    add(sc(newBalls(over)));
  });
  $('#addWeather')?.addEventListener('click', () => add(sc(newWeather())));
  $('#addRect')?.addEventListener('click', () => {
    const r = sc(newRect()); d.elements.splice(0, 0, r); selId = r.id; // obdélník jako podklad – dospod
    change(); drawLayers(); drawProps();
  });
  $('#addImg')?.addEventListener('click', () => pickImage('Vložit obrázek', (id) => {
    const e = sc(newImage(id)), mm = mediaById(id);
    add(e); imgWatch();
    const im = loadImg(id);
    const setRatio = (img) => { e.h = Math.round(e.w * img.naturalHeight / img.naturalWidth); e.y = Math.round((SH - e.h) / 2); change(); syncPos(); };
    if (im) setRatio(im); else if (mm?.url) imgCache.get(mm.url).addEventListener('load', (ev) => setRatio(ev.target), { once: true });
  }));

  // ---------- klávesnice ----------
  const onKey = (ev) => {
    if (!document.body.contains(stage)) { removeEventListener('keydown', onKey); return; }
    const tag = document.activeElement?.tagName;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || !$('#modal').hidden) return;
    const k = ev.key.toLowerCase();
    if ((ev.ctrlKey || ev.metaKey) && k === 'z') { ev.preventDefault(); undo(ev.shiftKey ? 1 : -1); return; }
    if ((ev.ctrlKey || ev.metaKey) && k === 'y') { ev.preventDefault(); undo(1); return; }
    const e = sel(); if (!e) return;
    if ((ev.ctrlKey || ev.metaKey) && k === 'd') { ev.preventDefault(); duplicate(); return; }
    if (k === 'delete' || k === 'backspace') { ev.preventDefault(); removeSel(); return; }
    const step = ev.shiftKey ? 10 : 1;
    const mv = { arrowleft: [-step, 0], arrowright: [step, 0], arrowup: [0, -step], arrowdown: [0, step] }[k];
    if (mv) { ev.preventDefault(); e.x += mv[0]; e.y += mv[1]; dirty = true; commit(500); redraw(); syncPos(); }
  };
  addEventListener('keydown', onKey);
  $('#seUndo')?.addEventListener('click', () => undo(-1));
  $('#seRedo')?.addEventListener('click', () => undo(1));

  // ---------- uložení, náhled, odchod ----------
  const leave = () => { ro.disconnect(); removeEventListener('keydown', onKey); removeEventListener('beforeunload', guard); };
  const guard = (ev) => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } };
  addEventListener('beforeunload', guard);
  window.slideEditorDirty = () => dirty;
  $('#seName').oninput = (ev) => { name = ev.target.value; dirty = true; };
    $('#seTags').onclick = () => editTagsModal(tags, (t) => { if (JSON.stringify(t) !== JSON.stringify(tags)) dirty = true; tags = t; $('#seTagCnt').textContent = tags.length || ''; });
  $('#seBack').onclick = () => {
    if (dirty && !confirm('Slide má neuložené změny. Opravdu odejít?')) return;
    leave(); route();
  };
  $('#sePreview').onclick = (ev) => busy(ev.currentTarget, 'Vykresluji…', async () => {
    try {
      const r = await api('slide_preview', { design: d, ts: whenTs() });
      openModal(`<div class="row" style="margin-bottom:12px"><b class="grow">Skutečný náhled – takhle obrázek půjde na sítě</b><button class="btn sm" data-close>Zavřít</button></div>
        <img src="${r.image}" alt="" style="width:auto;height:auto;max-width:100%;max-height:75vh;display:block;margin:0 auto;border-radius:8px">`);
    } catch (e) { toast(e.message, 'error'); }
  });
  $('#seSave').onclick = (ev) => {
    $('#seErr').hidden = true;
    if (!$('#seName').value.trim()) { $('#seName').focus(); $('#seErr').textContent = 'Zadejte název slidu.'; $('#seErr').hidden = false; return; }
    busy(ev.currentTarget, 'Ukládám…', async () => {
      try {
        const r = await api('slide_save', { id: m?.id || 0, name: $('#seName').value, design: d, tags });
        dirty = false;
        if (!m) { m = { id: r.media.id }; history.replaceState(null, '', `slide.php?id=${r.media.id}${location.search.includes('for=novy') ? '&for=novy' : ''}`); }
        toast(`Slide „${r.media.name}“ uložen.`);
        slideSaved(r.media);
      } catch (e) { $('#seErr').textContent = 'Slide se nepodařilo uložit: ' + e.message; $('#seErr').hidden = false; toast('Slide se nepodařilo uložit.', 'error'); }
    });
  };

  // ---------- formát plátna, čas náhledu, písma (Social Planner) ----------
  $('#seFmt').onchange = (ev) => {
    const f = FORMATS.find((x) => x.key === ev.target.value); if (!f) return;
    rescaleDesign(d, f.w, f.h);
    SW = f.w; SH = f.h; d.format = f.key; d.w = SW; d.h = SH;
    cv.width = SW; cv.height = SH; lastW = 0; fit();
    dirty = true; commit(); lastReq = null; redraw(); drawProps();
  };
  $('#seWhen').onchange = () => { lastReq = null; resolveTexts(); };
  $('#seFonts').onclick = () => manageFonts(async () => { FONT_LIST = null; await loadFontList(); drawProps(); preload(); });

  drawLayers(); drawProps(); imgWatch(); fit();
  resolveTexts();
  preload();
}
