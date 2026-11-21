'use strict';
// Spuštění editoru slidu: načte knihovnu (obrázky do slidu) a slide podle ?id=, pak editSlide() ze slide-editor.js.
(async () => {
  try {
    await loadLibrary();
    const id = +new URLSearchParams(location.search).get('id') || 0;
    let m = null;
    if (id) {
      const s = await api(`slide_get&id=${id}`);
      m = { id: s.id, original_name: s.name, tags: s.tags, design: s.design };
      document.title = `${s.name} – slide`;
    }
    await editSlide(m);
  } catch (e) {
    $('#main').innerHTML = `<div class="errbox">Editor se nepodařilo otevřít: ${esc(e.message)}</div><p><a class="btn" href="knihovna.php">← Knihovna médií</a></p>`;
  }
})();
