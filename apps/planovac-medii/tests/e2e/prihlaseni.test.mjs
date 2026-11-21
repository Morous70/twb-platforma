// Přihlášení: Passkey (virtuální zařízení v Chromiu), Google (zkušební režim), zapomenuté heslo, karta Zabezpečení.
import { B, browser, login, test, eq, ok, done } from './helpers.mjs';
import fs from 'node:fs';
const { br, pg } = await browser();
const ctx = pg.context();
const cdp = await ctx.newCDPSession(pg);
await cdp.send('WebAuthn.enable');
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
  protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
const logout = async () => {
  await pg.waitForLoadState('networkidle').catch(() => {});
  await pg.waitForTimeout(300);
  for (let i = 0; i < 3; i++) {
    try { await pg.goto(`${B}/?logout=1`); break; } catch (e) { if (!/ERR_ABORTED/.test(String(e))) throw e; await pg.waitForTimeout(300); }
  }
  await pg.waitForSelector('input[name=password]');
};
await login(pg);

await test('karta Zabezpečení: heslo, Google a Passkey, upozornění na chybějící e-mail', async () => {
  await pg.goto(`${B}/#nastaveni/zabezpeceni`); await pg.waitForSelector('#pkBox');
  const t = await pg.innerText('#setBody');
  ok(t.includes('Změnit heslo') && t.includes('Přihlášení přes Google') && t.includes('Passkey'), t.slice(0, 200));
  ok(t.includes('není vyplněný e-mail'), 'bez e-mailu je upozornění');
  ok(await pg.isVisible('#pkAdd'), 'tlačítko Přidat zařízení');
}, pg);

await test('Passkey: přidání zařízení, přejmenování', async () => {
  await pg.fill('#pkName', 'Zkušební telefon');
  await pg.click('#pkAdd');
  await pg.waitForSelector('.pk-row');
  ok((await pg.innerText('.pk-row')).includes('Zkušební telefon'), await pg.innerText('#pkBox'));
  const creds = await cdp.send('WebAuthn.getCredentials', { authenticatorId });
  eq(1, creds.credentials.length, 'zařízení má klíč');
  await pg.click('[data-pkren]');
  await pg.fill('#pkNew', 'Telefon Honzy');
  await pg.click('#pkNewSave');
  await pg.waitForFunction(() => document.querySelector('.pk-row .pk-name')?.textContent.includes('Telefon Honzy'));
}, pg);

await test('Passkey: přihlášení bez hesla a záznam v Protokolu', async () => {
  await logout();
  await pg.waitForSelector('#pkLogin:not([hidden])');
  await Promise.all([pg.waitForNavigation(), pg.click('#pkLogin')]);
  await pg.waitForSelector('#main');
  await pg.goto(`${B}/#protokol`); await pg.waitForSelector('.log');
  ok((await pg.innerText('#main')).includes('Přihlášení: Passkey (Telefon Honzy)'), 'Protokol');
  await pg.goto(`${B}/#nastaveni/zabezpeceni`); await pg.waitForSelector('.pk-row');
  ok((await pg.innerText('.pk-row')).includes('naposledy'), 'poslední použití');
}, pg);

await test('Passkey: stejné zařízení se podruhé nezaregistruje, odebrání', async () => {
  await pg.fill('#pkName', 'Znovu');
  await pg.click('#pkAdd');
  await pg.waitForFunction(() => /už je zaregistrované/.test(document.querySelector('#toasts')?.innerText || ''));
  eq(1, (await pg.$$('.pk-row')).length);
  await pg.click('[data-pkdel]');
  await pg.waitForSelector('.pk-list .empty');
  await logout();
  ok(await pg.isHidden('#pkLogin').catch(() => true), 'bez zařízení není tlačítko Passkey');
  ok((await pg.$$('#pkLogin')).length === 0, 'tlačítko na stránce není');
}, pg);

await test('Google: propojení v Nastavení, přihlášení, odpojení', async () => {
  await login(pg);
  await pg.goto(`${B}/#nastaveni/zabezpeceni`); await pg.waitForSelector('#glBox');
  await Promise.all([pg.waitForURL(/zabezpeceni/), pg.click('#glBox a.btn')]);
  await pg.waitForSelector('#glBox');
  await pg.waitForFunction(() => document.querySelector('#glBox')?.innerText.includes('test@example.com'));
  await logout(); await pg.waitForSelector('#gLogin');
  await Promise.all([pg.waitForNavigation(), pg.click('#gLogin')]);
  await pg.waitForSelector('#main');
  await pg.goto(`${B}/#nastaveni/zabezpeceni`); await pg.waitForSelector('#glUnlink');
  await pg.click('#glUnlink');
  await pg.waitForFunction(() => document.querySelector('#glBox')?.innerText.includes('Propojit Google účet'));
  await logout();
  eq(0, (await pg.$$('#gLogin')).length);
}, pg);

await test('zapomenuté heslo: odkaz z e-mailu a nové heslo', async () => {
  await login(pg);
  await pg.goto(`${B}/#nastaveni/upozorneni`); await pg.waitForSelector('#sEmail');
  await pg.fill('#sEmail', 'majitel@example.com');
  await pg.click('#tSave'); await pg.waitForFunction(() => /uložen/i.test(document.querySelector('#toasts')?.innerText || ''));
  await logout();
  await pg.click('text=Zapomenuté heslo');
  await pg.fill('input[name=email]', 'majitel@example.com');
  await pg.click('form button');
  await pg.waitForSelector('.okbox');
  const dir = `${process.env.TEST_TMP}/app/data/tmp`;
  const mail = fs.readdirSync(dir).filter((f) => f.startsWith('mail-')).map((f) => fs.readFileSync(`${dir}/${f}`, 'utf8')).pop();
  ok(mail, 'e-mail byl odeslán');
  const link = mail.match(/https?:\/\/\S+\?reset=[0-9a-f]+/)[0];
  await pg.goto(link); await pg.waitForSelector('input[name=new]');
  await pg.fill('input[name=new]', 'nove-heslo-789');
  await pg.fill('input[name=new2]', 'nove-heslo-789');
  await pg.click('form button'); await pg.waitForSelector('.okbox');
  await pg.fill('input[name=password]', 'nove-heslo-789');
  await Promise.all([pg.waitForNavigation(), pg.click('form button')]);
  await pg.waitForSelector('#main');
  // vrátit původní heslo pro další testy
  await pg.goto(`${B}/#nastaveni/zabezpeceni`); await pg.waitForSelector('#pSave');
  await pg.fill('#pOld', 'nove-heslo-789'); await pg.fill('#pNew', process.env.TEST_PASSWORD);
  await pg.click('#pSave'); await pg.waitForFunction(() => /Heslo změněno/.test(document.querySelector('#toasts')?.innerText || ''));
}, pg);

await done(br);
