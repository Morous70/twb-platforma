// Přihlašovací stránka: tlačítko „Přihlásit pomocí Passkey“.
(() => {
  const btn = document.getElementById('pkLogin');
  if (!btn || !window.webauthn || !webauthn.supported()) return;
  btn.hidden = false;
  const box = document.querySelector('.login');
  const showError = (msg) => {
    let e = box.querySelector('.errbox');
    if (!e) { e = document.createElement('div'); e.className = 'errbox'; box.querySelector('.brand').after(e); }
    e.textContent = msg;
  };
  const post = async (action, body) => {
    const r = await fetch(`passkey.php?a=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}), credentials: 'same-origin' });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.error || 'Přihlášení se nezdařilo.');
    return j;
  };
  btn.onclick = async () => {
    btn.disabled = true;
    try {
      const cred = await webauthn.get(await post('options'));
      await post('verify', cred);
      location.href = './';
    } catch (e) {
      showError(e.name === 'NotAllowedError' ? 'Přihlášení bylo zrušeno nebo vypršelo.' : e.message);
      btn.disabled = false;
    }
  };
})();
