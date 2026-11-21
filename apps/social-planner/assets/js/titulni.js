'use strict';
const { $, $$, api, flash, icons } = App;
const HINT = {
  code: 'Zobrazí se váš reklamní kód.',
  demo: 'Zobrazí se ukázková plocha z návrhu („Prostor pro vaši reklamu“). Hodí se, dokud nemáte reklamu.',
  off: 'Plocha se na stránce vůbec nezobrazí.',
};
function syncMode(i) {
  const m = ($(`input[name="slot${i}_mode"]:checked`) || {}).value || 'demo';
  $(`[data-code-wrap="${i}"]`).hidden = m !== 'code';
  $(`[data-mode-hint="${i}"]`).textContent = HINT[m];
}
function fill(s) {
  $('#enabled').checked = s.enabled; $('#indexable').checked = s.indexable; $('#head_code').value = s.head_code;
  s.slots.forEach((x, i) => {
    $(`#s${i}${x.mode}`).checked = true; $(`#slot${i}_code`).value = x.code; $(`#slot${i}_label`).checked = x.label; syncMode(i);
  });
}
$$('input[type=radio][name$="_mode"]').forEach(r => r.addEventListener('change', () => syncMode(r.name.match(/\d+/)[0])));
api('landing_get').then(r => fill(r.settings)).catch(e => flash(e.message, 'danger'));
$('#form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const b = $('#btnSave'); b.disabled = true;
  try { const r = await api('landing_save', new FormData($('#form'))); fill(r.settings); flash('Nastavení titulní stránky je uloženo.'); }
  catch (err) { flash(err.message, 'danger'); }
  b.disabled = false;
});
icons();
