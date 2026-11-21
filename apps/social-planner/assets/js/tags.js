'use strict';
// Tagy v knihovně médií (od 1.4.0, převzato z TWB Plánovače médií): řada tagů s filtrem, štítky na kartě a pole pro úpravu.
// Používá Knihovna médií (knihovna.js) i výběr z knihovny v Novém příspěvku (novy.js) → window.Tags.
(function () {
  const { esc, flash } = App;
  const tagKey = (t) => String(t).toLocaleLowerCase('cs');
  const isTouch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

  /** Seznam tagů položek (bez opakování), seřazený česky. */
  function allTags(list) {
    const m = new Map();
    for (const it of list || []) for (const t of it.tags || []) if (!m.has(tagKey(t))) m.set(tagKey(t), t);
    return [...m.values()].sort((a, b) => a.localeCompare(b, 'cs'));
  }
  const newState = () => ({ sel: new Set(), mode: 'and', none: false });
  /** Odpovídá položka filtru tagů? */
  function ok(it, st) {
    if (st.none) return !(it.tags || []).length;
    if (!st.sel.size) return true;
    const k = new Set((it.tags || []).map(tagKey));
    return st.mode === 'or' ? [...st.sel].some((x) => k.has(x)) : [...st.sel].every((x) => k.has(x));
  }
  /** Vybrané tagy jako názvy (pro nově nahrané soubory): jen při volbě „všechny“ nebo jednom tagu. */
  function current(st, list) {
    if (st.none || !st.sel.size || (st.mode === 'or' && st.sel.size > 1)) return [];
    const names = allTags(list);
    return [...st.sel].map((k) => names.find((t) => tagKey(t) === k)).filter(Boolean);
  }
  /** Štítky tagů na kartě; klik (data-qtag) = rychlý filtr. */
  function chips(it, max = 3) {
    const t = it.tags || [];
    if (!t.length) return '';
    return `<div class="lib-tags">${t.slice(0, max).map((x) => `<button type="button" class="tg" data-qtag="${esc(tagKey(x))}" title="Zobrazit jen soubory s tagem ${esc(x)}">${esc(x)}</button>`).join('')}${
      t.length > max ? `<span class="tg more" title="${esc(t.slice(max).join(', '))}">+${t.length - max}</span>` : ''}</div>`;
  }

  /**
   * Řada tagů: Vše, Bez tagu, tagy s počty, přepínač všechny / kterýkoli.
   * o.extra = HTML na konec (např. Archiv), o.onDropTag(name, ids) = na tag jde přetáhnout soubory (desktop).
   */
  function bar(box, list, st, onChange, o = {}) {
    if (!box) return;
    const tags = allTags(list);
    [...st.sel].forEach((k) => { if (!tags.some((t) => tagKey(t) === k)) st.sel.delete(k); });
    const has = (it, k) => (it.tags || []).some((t) => tagKey(t) === k);
    const noTag = list.filter((it) => !(it.tags || []).length).length;
    const chip = (cls, attrs, label, n) => `<button type="button" class="btn btn-sm tag-chip ${cls}" ${attrs}>${label} <span class="cnt">${n}</span></button>`;
    box.innerHTML = chip(!st.sel.size && !st.none ? 'on' : '', 'data-all', 'Vše', list.length) +
      chip(st.none ? 'on' : '', 'data-none title="Soubory, které nemají žádný tag"', 'Bez tagu', noTag) +
      tags.map((t) => { const k = tagKey(t); return chip(st.sel.has(k) ? 'on' : '', `data-tag="${esc(k)}" data-name="${esc(t)}"${o.onDropTag && !isTouch ? ' title="Kliknutím vyberete, přetažením souborů sem tag přidáte"' : ''}`, '🏷 ' + esc(t), list.filter((it) => has(it, k)).length); }).join('') +
      (tags.length > 1 ? `<span class="btn-group btn-group-sm tag-mode" title="Jak se kombinuje víc vybraných tagů">
        <button type="button" class="btn ${st.mode === 'and' ? 'btn-secondary' : 'btn-outline-secondary'}" data-mode="and" title="Soubor musí mít všechny vybrané tagy">všechny</button>
        <button type="button" class="btn ${st.mode === 'or' ? 'btn-secondary' : 'btn-outline-secondary'}" data-mode="or" title="Stačí kterýkoli z vybraných tagů">kterýkoli</button></span>` : '') +
      (o.extra || '');
    box.querySelector('[data-all]').onclick = () => { st.sel.clear(); st.none = false; o.onAll?.(); onChange(); };
    box.querySelector('[data-none]').onclick = () => { st.none = !st.none; st.sel.clear(); onChange(); };
    box.querySelectorAll('[data-tag]').forEach((b) => {
      const k = b.dataset.tag;
      b.onclick = () => { st.none = false; st.sel.has(k) ? st.sel.delete(k) : st.sel.add(k); onChange(); };
      if (o.onDropTag && !isTouch) {
        b.ondragover = (e) => { if ([...e.dataTransfer.types].includes('application/x-sp-items')) { e.preventDefault(); b.classList.add('over'); } };
        b.ondragleave = () => b.classList.remove('over');
        b.ondrop = (e) => {
          b.classList.remove('over');
          const ids = JSON.parse(e.dataTransfer.getData('application/x-sp-items') || '[]');
          if (ids.length) { e.preventDefault(); o.onDropTag(b.dataset.name, ids); }
        };
      }
    });
    box.querySelectorAll('[data-mode]').forEach((b) => b.onclick = () => { st.mode = b.dataset.mode; onChange(); });
  }

  /** Pole pro tagy: štítky s × a vstup s nabídkou existujících tagů (Enter nebo čárka přidá). Vrací { get() }. */
  function field(box, initial, known) {
    const tags = [...(initial || [])];
    const listId = 'tagList' + Math.random().toString(36).slice(2, 8);
    const add = (v) => {
      v = String(v).replace(/[,;]/g, ' ').replace(/\s+/g, ' ').trim();
      if (!v) return true;
      if (v.length > 30) { flash('Tag může mít nejvýš 30 znaků.', 'warning'); return false; }
      v = known.find((t) => tagKey(t) === tagKey(v)) || v;
      if (!tags.some((t) => tagKey(t) === tagKey(v))) {
        if (tags.length >= 10) { flash('Soubor může mít nejvýš 10 tagů.', 'warning'); return false; }
        tags.push(v);
      }
      draw(); box.querySelector('.tag-in').focus();
      return true;
    };
    function draw() {
      box.innerHTML = `<div class="tag-field form-control">${tags.map((t, i) => `<span class="tg">${esc(t)}<button type="button" data-rm="${i}" aria-label="Odebrat tag ${esc(t)}">×</button></span>`).join('')}
        <input type="text" class="tag-in" list="${listId}" maxlength="30" placeholder="${tags.length ? '' : 'Přidat tag…'}" aria-label="Přidat tag"></div>
        <datalist id="${listId}">${known.filter((t) => !tags.some((x) => tagKey(x) === tagKey(t))).map((t) => `<option value="${esc(t)}">`).join('')}</datalist>`;
      box.querySelectorAll('[data-rm]').forEach((b) => b.onclick = () => { tags.splice(+b.dataset.rm, 1); draw(); box.querySelector('.tag-in').focus(); });
      const inp = box.querySelector('.tag-in');
      inp.onkeydown = (e) => {
        if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); add(inp.value); }
        else if (e.key === 'Backspace' && !inp.value && tags.length) { tags.pop(); draw(); box.querySelector('.tag-in').focus(); }
      };
      inp.oninput = () => { if (known.includes(inp.value)) add(inp.value); };   // výběr z nabídky prohlížeče
    }
    draw();
    return { get: () => { const p = box.querySelector('.tag-in').value; if (p.trim() && !add(p)) throw new Error('Tag nejde přidat.'); return [...tags]; } };
  }

  window.Tags = { tagKey, allTags, newState, ok, current, chips, bar, field, isTouch };
})();
