// Nápověda: obsah, stránky (Markdown z help/), hledání a tlačítka „?“.
// Načítá se PŘED app.js; používá jeho pomocné funkce ($, esc, api, main, toast) až při volání.
(() => {
  let bundle = null;           // {sections, context, index}
  const pages = {};            // id → stránka z api.php?a=help&page=
  let current = null;          // id zobrazené stránky
  let query = '';

  // ---------- tlačítko „?“ ----------
  /** target: „@kontext“ (podle help/_obsah.json) nebo ID stránky. newTab = otevřít v nové záložce (editory, okna). */
  window.helpBtn = (target, newTab = false) => `<a class="help-q" href="${newTab ? './' : ''}#napoveda/${target}"${newTab ? ' target="_blank" rel="noopener"' : ''}
    title="Nápověda k této části" aria-label="Nápověda">?</a>`;

  // ---------- Markdown (jen to, co nápověda používá) ----------
  const inline = (s) => {
    const codes = [];
    let t = esc(s).replace(/`([^`]+)`/g, (_, c) => { codes.push(c); return `\u0000${codes.length - 1}\u0000`; });
    t = t.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, text, href) => {
      if (/^(mailto:|https?:\/\/)/i.test(href)) return `<a href="${href}" target="_blank" rel="noopener">${text}</a>`;
      return `<a href="#napoveda/${href}">${text}</a>`;
    });
    t = t.replace(/\[(OVĚŘIT[^\]]*)\]/g, '<span class="help-verify">[$1]</span>');
    return t.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${codes[+i]}</code>`);
  };
  const indentOf = (l) => l.match(/^\s*/)[0].length;
  const ITEM = /^(\s*)([-*]|\d+\.)\s+(.*)$/;

  function parseList(lines, i) {
    const first = lines[i].match(ITEM);
    const base = first[1].length;
    const ordered = /\d/.test(first[2]);
    const start = ordered ? parseInt(first[2], 10) : 1;
    let html = `<${ordered ? 'ol' : 'ul'}${ordered && start !== 1 ? ` start="${start}"` : ''}>`;
    while (i < lines.length) {
      let j = i;
      while (j < lines.length && !lines[j].trim()) j++;            // prázdné řádky mezi položkami
      const m = j < lines.length ? lines[j].match(ITEM) : null;
      if (!m || m[1].length !== base || /\d/.test(m[2]) !== ordered) break;
      i = j + 1;
      let text = m[3], sub = '';
      while (i < lines.length && lines[i].trim() && indentOf(lines[i]) > base) {
        if (ITEM.test(lines[i])) { const r = parseList(lines, i); sub += r[0]; i = r[1]; }
        else { text += ' ' + lines[i].trim(); i++; }
      }
      html += `<li>${inline(text)}${sub}</li>`;
    }
    return [html + `</${ordered ? 'ol' : 'ul'}>`, i];
  }

  const cells = (row) => row.trim().replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map((c) => c.trim().replace(/\\\|/g, '|'));

  function markdown(md) {
    const lines = md.replace(/\r/g, '').split('\n');
    let html = '', i = 0;
    while (i < lines.length) {
      const ln = lines[i];
      if (!ln.trim()) { i++; continue; }
      let m;
      if ((m = ln.match(/^(#{1,4})\s+(.*)$/))) { html += `<h${m[1].length}>${inline(m[2])}</h${m[1].length}>`; i++; continue; }
      if (/^>/.test(ln)) {
        const q = [];
        while (i < lines.length && /^>/.test(lines[i])) q.push(lines[i++].replace(/^>\s?/, ''));
        html += `<blockquote>${q.map(inline).join('<br>')}</blockquote>`;
        continue;
      }
      if (/^\|.*\|\s*$/.test(ln) && i + 1 < lines.length && /^\|[\s:|-]+\|\s*$/.test(lines[i + 1])) {
        const head = cells(ln);
        i += 2;
        const rows = [];
        while (i < lines.length && /^\|.*\|\s*$/.test(lines[i])) rows.push(cells(lines[i++]));
        html += `<div class="help-table"><table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>`
          + rows.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('') + '</tbody></table></div>';
        continue;
      }
      if (ITEM.test(ln)) { const r = parseList(lines, i); html += r[0]; i = r[1]; continue; }
      const p = [];
      while (i < lines.length && lines[i].trim() && !/^(#{1,4}\s|>|\|.*\|\s*$)/.test(lines[i]) && !ITEM.test(lines[i])) p.push(lines[i++].trim());
      const joined = p.join(' ');
      if ((m = joined.match(/^\[SNÍMEK:\s*(.+)\]$/))) html += `<div class="help-shot"><span>📷 Snímek obrazovky</span>${esc(m[1])}</div>`;
      else html += `<p>${p.map(inline).join('<br>')}</p>`;
    }
    return html;
  }
  window.helpMarkdown = markdown;   // pro testy

  // ---------- hledání ----------
  const norm = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const stem = (w) => (w.length >= 6 ? w.slice(0, -2) : w.length >= 5 ? w.slice(0, -1) : w);

  function search(q) {
    const toks = norm(q).split(/[^a-z0-9]+/).filter((t) => t.length >= 2).map(stem);
    if (!toks.length) return [];
    const res = [];
    for (const p of bundle.index) {
      const title = norm(p.title), keys = norm(p.keys), text = norm(p.text);
      let score = 0, ok = true, firstAt = -1;
      for (const t of toks) {
        const inT = title.includes(t), inK = keys.includes(t);
        const at = text.indexOf(t);
        if (!inT && !inK && at < 0) { ok = false; break; }
        score += (inT ? 10 : 0) + (inK ? 5 : 0) + (at >= 0 ? 1 + Math.min(5, text.split(t).length - 1) * 0.2 : 0);
        if (at >= 0 && (firstAt < 0 || at < firstAt)) firstAt = at;
      }
      if (!ok) continue;
      let snippet = '';
      if (firstAt >= 0) {
        const from = Math.max(0, firstAt - 50);
        snippet = (from ? '… ' : '') + p.text.slice(from, from + 170) + ' …';
      } else snippet = p.text.slice(0, 150) + ' …';
      res.push({ id: p.id, title: p.title, score, snippet });
    }
    return res.sort((a, b) => b.score - a.score).slice(0, 12);
  }
  const mark = (html, q) => {
    const toks = norm(q).split(/[^a-z0-9]+/).filter((t) => t.length >= 2).map(stem);
    if (!toks.length) return html;
    // zvýrazňuje se v původním textu: hledá se po slovech bez diakritiky
    return html.replace(/[\p{L}\p{N}]+/gu, (w) => (toks.some((t) => norm(w).includes(t)) ? `<mark>${w}</mark>` : w));
  };

  // ---------- zobrazení ----------
  const pageInfo = (id) => {
    for (const s of bundle.sections) for (const g of s.groups) for (const p of g.pages) if (p.id === id) return { ...p, section: s };
    return null;
  };

  function drawToc() {
    const cur = current && pageInfo(current);
    $('#hToc').innerHTML = bundle.sections.map((s) => `<details class="help-sec" ${cur && cur.section.id === s.id ? 'open' : ''}>
      <summary>${esc(s.title)}</summary>${s.groups.map((g) => `${g.title ? `<div class="help-grp">${esc(g.title)}</div>` : ''}
        <ul>${g.pages.map((p) => `<li><a href="#napoveda/${p.id}" class="${p.id === current ? 'on' : ''}">${esc(p.title)}${p.admin ? ' <span class="help-adm" title="Pro správce">🔧</span>' : ''}</a></li>`).join('')}</ul>`).join('')}</details>`).join('');
  }

  function drawResults() {
    const res = search(query);
    $('#hBody').innerHTML = `<h1>Výsledky hledání</h1><p class="muted small">„${esc(query)}“ – nalezeno ${res.length}</p>`
      + (res.length ? res.map((r) => `<a class="help-hit" href="#napoveda/${r.id}"><b>${mark(esc(r.title), query)}</b>
          <span class="help-path">${esc(pageInfo(r.id)?.section.title || '')}</span><span class="small muted">${mark(esc(r.snippet), query)}</span></a>`).join('')
        : '<div class="empty small">Nic nenalezeno. Zkuste jiné slovo, nebo projděte obsah vlevo.</div>');
  }

  function drawPage(p) {
    const rel = p.related.length ? `<div class="help-rel"><h3>Související stránky</h3><ul>${p.related.map((r) => `<li><a href="#napoveda/${r.id}">${esc(r.title)}</a></li>`).join('')}</ul></div>` : '';
    const nav = `<div class="help-nav">${p.prev ? `<a class="btn" href="#napoveda/${p.prev.id}">← ${esc(p.prev.title)}</a>` : '<span></span>'}
      ${p.next ? `<a class="btn" href="#napoveda/${p.next.id}">${esc(p.next.title)} →</a>` : ''}</div>`;
    $('#hBody').innerHTML = `${p.admin ? '<div class="help-admin">🔧 Pro správce</div>' : ''}${markdown(p.md)}${rel}${nav}`;
    document.title = `${p.title} – Nápověda`;
  }

  async function show() {
    if (query.trim()) { drawResults(); return; }
    $('#hBody').innerHTML = '<div class="muted">Načítám…</div>';
    const id = current;
    try {
      pages[id] ??= await api('help', undefined, `&page=${encodeURIComponent(id)}`);
    } catch (e) {
      if (id !== current || !$('#hBody')) return;
      $('#hBody').innerHTML = `<h1>Stránka nenalezena</h1><p>Tato stránka nápovědy neexistuje. Vyberte ji z obsahu vlevo.</p>`;
      return;
    }
    if (id === current && $('#hBody')) { drawPage(pages[id]); drawToc(); window.scrollTo(0, 0); }
  }

  window.viewHelp = async function viewHelp() {
    try { bundle ??= await api('help'); } catch (e) { toast(e.message, 'error'); return; }
    let target = decodeURIComponent(location.hash.slice(1).split('/')[1] || '').split('?')[0];
    if (target.startsWith('@')) target = bundle.context[target.slice(1)] || '';
    if (!target || !pageInfo(target)) target = bundle.sections[0].groups[0].pages[0].id;
    current = target;
    query = '';
    main.innerHTML = `
      <div class="head"><div><h1>Nápověda</h1><p class="sub">Návody a vysvětlení k aplikaci.</p></div>
        <input type="search" id="hQ" placeholder="Hledat v nápovědě…" style="max-width:300px" aria-label="Hledat v nápovědě"></div>
      <button class="btn help-tocbtn" id="hTocBtn">☰ Obsah nápovědy</button>
      <div class="help-layout"><nav class="help-toc panel" id="hToc" aria-label="Obsah nápovědy"></nav><article class="panel help-body" id="hBody"></article></div>`;
    document.title = 'Nápověda – TWB Plánovač médií';
    drawToc();
    $('#hTocBtn').onclick = () => $('#hToc').classList.toggle('open');
    let t = null;
    $('#hQ').oninput = (e) => {
      clearTimeout(t);
      t = setTimeout(() => { query = e.target.value; show(); }, 150);
    };
    $('#hToc').addEventListener('click', (e) => { if (e.target.closest('a')) $('#hToc').classList.remove('open'); });
    await show();
  };
})();
