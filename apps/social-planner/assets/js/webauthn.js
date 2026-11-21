'use strict';
(function () {
  const enc = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const dec = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), c => c.charCodeAt(0));
  const supported = () => !!(window.PublicKeyCredential && window.isSecureContext);
  function msg(e) {
    if (e && e.name === 'NotAllowedError') return 'Přihlášení bylo zrušeno nebo vypršelo.';
    if (e && e.name === 'InvalidStateError') return 'Toto zařízení už je zaregistrované.';
    return (e && e.message) || 'Operace se nezdařila.';
  }
  async function login() {
    const r = await fetch('passkey.php?a=options', { method: 'POST' }); const o = await r.json();
    if (!r.ok) throw new Error(o.error || 'Chyba');
    const pk = o.publicKey; pk.challenge = dec(pk.challenge);
    const c = await navigator.credentials.get({ publicKey: pk });
    const body = { id: c.id, rawId: enc(c.rawId), type: c.type, response: { clientDataJSON: enc(c.response.clientDataJSON), authenticatorData: enc(c.response.authenticatorData), signature: enc(c.response.signature), userHandle: c.response.userHandle ? enc(c.response.userHandle) : null } };
    const v = await fetch('passkey.php?a=verify', { method: 'POST', body: JSON.stringify(body) }); const j = await v.json();
    if (!v.ok) throw new Error(j.error || 'Přihlášení se nezdařilo.');
  }
  // registrace: api = App.api, name = název zařízení
  async function register(api, form, name) {
    const o = (await api('passkey_reg_options', form({}))).publicKey;
    o.challenge = dec(o.challenge); o.user.id = dec(o.user.id);
    (o.excludeCredentials || []).forEach(x => x.id = dec(x.id));
    const c = await navigator.credentials.create({ publicKey: o });
    const cred = { id: c.id, rawId: enc(c.rawId), type: c.type, response: { clientDataJSON: enc(c.response.clientDataJSON), attestationObject: enc(c.response.attestationObject) } };
    await api('passkey_reg_verify', form({ name, cred: JSON.stringify(cred) }));
  }
  window.WA = { supported, login, register, msg };
})();
