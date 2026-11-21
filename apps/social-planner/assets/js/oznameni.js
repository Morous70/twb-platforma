'use strict';
const { $, api, form, flash, icons } = App;
const body = () => form({ email: $('#email').value.trim(), on_fail: $('#onFail').checked ? 1 : '', on_token: $('#onToken').checked ? 1 : '' });
api('notify_get').then(r => { $('#email').value = r.settings.email; $('#onFail').checked = r.settings.on_fail; $('#onToken').checked = r.settings.on_token; })
  .catch(e => flash(e.message, 'danger'));
$('#form').addEventListener('submit', async (e) => {
  e.preventDefault();
  try { await api('notify_save', body()); flash('Nastavení je uloženo.'); } catch (err) { flash(err.message, 'danger'); }
});
$('#btnTest').addEventListener('click', async () => {
  try { await api('notify_save', body()); await api('notify_test', form({})); flash('Zkušební e-mail byl odeslán, zkontrolujte schránku (i spam).'); }
  catch (err) { flash(err.message, 'danger'); }
});
icons();
