// Pomůcky pro testy v prohlížeči. Spouští je tests/run.php --e2e (nastaví BASE_URL, TEST_PASSWORD, TEST_TMP).
// Playwright se hledá přes NODE_PATH (CommonJS require), Chromium případně v CHROMIUM_PATH.
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

export const B = process.env.BASE_URL;
export const TMP = process.env.TEST_TMP;
const T = { pass: 0, fail: 0, fails: [] };

export async function browser() {
  const br = await chromium.launch({ args: ['--lang=cs-CZ'], env: { ...process.env, LANG: 'cs_CZ.UTF-8', LANGUAGE: 'cs' }, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
  const ctx = await br.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'cs-CZ', permissions: ['clipboard-read', 'clipboard-write'] });
  const pg = await ctx.newPage();
  pg.errors = [];
  pg.on('pageerror', (e) => pg.errors.push(String(e.stack || e)));
  pg.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) pg.errors.push('console: ' + m.text()); });
  pg.on('dialog', (d) => d.accept());
  return { br, pg };
}

export async function login(pg) {
  await pg.goto(B + '/');
  await pg.fill('input[name=password]', process.env.TEST_PASSWORD);
  await Promise.all([pg.waitForNavigation(), pg.click('form button')]);
  await pg.waitForSelector('#main');
}

/** Volání API z přihlášené stránky (funkce api() aplikace). */
export const api = (pg, action, body, extra = '') => pg.evaluate(([a, b, x]) => api(a, b ?? undefined, x), [action, body ?? null, extra]);
export const toasts = async (pg) => (await pg.$$eval('#toasts .toast', (t) => t.map((x) => x.innerText.replace(/\s+/g, ' ')))).join(' | ');

export class Fail extends Error {}
export function eq(exp, act, msg = '') {
  if (JSON.stringify(exp) !== JSON.stringify(act)) throw new Fail(`${msg ? msg + ' – ' : ''}čekáno ${JSON.stringify(exp)}, je ${JSON.stringify(act)}`);
}
export function ok(c, msg = 'podmínka neplatí') { if (!c) throw new Fail(msg); }

export async function test(name, fn, pg) {
  const before = pg ? pg.errors.length : 0;
  try {
    await fn();
    if (pg && pg.errors.length > before) throw new Fail('chyba JavaScriptu na stránce: ' + pg.errors.slice(before).join(' / ').slice(0, 500));
    T.pass++; console.log(`  ✓ ${name}`);
  } catch (e) {
    T.fail++;
    const msg = e instanceof Fail ? e.message : String(e.message || e).split('\n')[0];
    T.fails.push(`${name}: ${msg}`);
    console.log(`  ✗ ${name}\n      ${msg}`);
    if (pg && TMP) await pg.screenshot({ path: `${TMP}/chyba-${name.replace(/[^\w]+/g, '-').slice(0, 40)}.png` }).catch(() => {});
  }
}

export function done(br) {
  console.log('##RESULT ' + JSON.stringify(T));
  return br.close().then(() => process.exit(T.fail ? 1 : 0));
}
