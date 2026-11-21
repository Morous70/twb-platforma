'use strict';
// Spuštění editoru slidu: načte galerii (obrázky do slidu) a slide podle ?id=, pak editSlide() ze slide-editor.js.
(async () => {
  try {
    await loadLibrary();
    const id = +URL_P.get('id') || 0;
    let m = null;
    if (id) {
      const s = await api(`slide_get&id=${id}`);
      m = { id: s.id, original_name: s.name, tags: [], design: s.design };
    }
    await editSlide(m);
  } catch (e) {
    $('#main').innerHTML = `<div class="errbox">Editor se nepodařilo otevřít: ${esc(e.message)}</div><p><a class="btn" href="${esc(window.REKLAMY.zpet)}">← Zpět na kampaň</a></p>`;
  }
})();
