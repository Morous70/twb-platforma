// Passkey (WebAuthn) v prohlížeči: převody mezi base64url a ArrayBuffer a volání navigator.credentials.
(() => {
  const dec = (s) => {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(s + '='.repeat((4 - (s.length % 4)) % 4));
    return Uint8Array.from(bin, (c) => c.charCodeAt(0)).buffer;
  };
  const enc = (buf) => {
    let s = '';
    new Uint8Array(buf).forEach((b) => { s += String.fromCharCode(b); });
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);

  /** Možnosti ze serveru (JSON) → možnosti pro prohlížeč. */
  function prepare(pk) {
    const o = { ...pk, challenge: dec(pk.challenge) };
    if (pk.user) o.user = { ...pk.user, id: dec(pk.user.id) };
    if (pk.excludeCredentials) o.excludeCredentials = pk.excludeCredentials.map((c) => ({ ...c, id: dec(c.id) }));
    if (pk.allowCredentials) o.allowCredentials = pk.allowCredentials.map((c) => ({ ...c, id: dec(c.id) }));
    return o;
  }

  /** Nové zařízení: vrátí objekt k odeslání na server. */
  async function create(options) {
    const c = await navigator.credentials.create({ publicKey: prepare(options.publicKey) });
    return { id: c.id, rawId: enc(c.rawId), type: c.type, response: {
      clientDataJSON: enc(c.response.clientDataJSON), attestationObject: enc(c.response.attestationObject) } };
  }

  /** Přihlášení: vrátí objekt k odeslání na server. */
  async function get(options) {
    const c = await navigator.credentials.get({ publicKey: prepare(options.publicKey) });
    return { id: c.id, rawId: enc(c.rawId), type: c.type, response: {
      clientDataJSON: enc(c.response.clientDataJSON), authenticatorData: enc(c.response.authenticatorData),
      signature: enc(c.response.signature), userHandle: c.response.userHandle ? enc(c.response.userHandle) : null } };
  }

  window.webauthn = { supported, create, get };
})();
