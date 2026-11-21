<?php
// Přihlášení: tokeny, zámek pokusů, heslo, obnova hesla, Google, CBOR, Passkey (ES256 i RS256), relace a CSRF.
// Přeneseno z testů Plánovače médií 1.8.0; HTTP testy zůstávají u aplikací.
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\{Host, Migrace as JadroMigrace};
use TWB\Prihlaseni\{Base64Url, Cbor, Google, Heslo, Migrace, Obnova, Passkey, Posta, Relace, Tokeny, Zamek};

const ZAKLAD = 'https://reklamy.example.cz';

/** Hostitel s tabulkami přihlášení. */
function ctx(): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('prihlaseni', Migrace::class);
    $tokeny = new Tokeny($h->db);
    $zamek = new Zamek($h->nastaveni, $h->protokol);
    $heslo = new Heslo($h->nastaveni);
    $slozka = $h->datovaSlozka . '/mail';
    $posta = new Posta('aplikace@example.cz', 'TWB Reklamy', $slozka);
    $obnova = new Obnova($h->nastaveni, $tokeny, $heslo, $zamek, $h->protokol, [$posta, 'posli'],
        fn() => (string)$h->nastaveni->dej('notify_email', ''), ZAKLAD, 'TWB Reklamy');
    $passkey = new Passkey($h->db, $tokeny, ZAKLAD, 'TWB Reklamy');
    return [$h, $tokeny, $zamek, $heslo, $obnova, $passkey, $slozka];
}
function maily(string $slozka): array { $m = glob("$slozka/mail-*.txt") ?: []; sort($m); return $m; }
function odkazZMailu(string $slozka): string
{
    $m = maily($slozka);
    preg_match('~\?reset=([0-9a-f]{64})~', (string)file_get_contents($m[count($m) - 1]), $mm);
    return $mm[1] ?? '';
}

// ---------- tokeny ----------
test('token: v databázi je jen otisk, platí jednou a jen do vypršení', function () {
    [$h, $t] = ctx();
    $tok = $t->vytvor('reset', 60);
    ok(!$h->db->q('SELECT 1 FROM auth_tokens WHERE token_hash = ?', [$tok])->fetchColumn(), 'surový token se nesmí ukládat');
    ok($t->platny('reset', $tok));
    ok(!$t->platny('jiny', $tok), 'jiný účel');
    ok($t->pouzij('reset', $tok));
    ok(!$t->pouzij('reset', $tok), 'podruhé nejde');
    ok(!$t->platny('reset', 'nesmysl'));
    ok(!$t->platny('reset', ''), 'prázdný');
    ok(!$t->platny('reset', str_repeat('a', 513)), 'příliš dlouhý');
    $e = $t->vytvor('reset', 60);
    $h->db->q('UPDATE auth_tokens SET expires = ? WHERE token_hash = ?', [time() - 1, hash('sha256', $e)]);
    ok(!$t->pouzij('reset', $e), 'vypršel');
    $a = $t->vytvor('reset', 60); $b = $t->vytvor('reset', 60);
    eq(2, $t->pocetPlatnych('reset'));
    $t->zneplatni('reset');
    ok(!$t->platny('reset', $a) && !$t->platny('reset', $b), 'zneplatněno');
});

// ---------- zámek ----------
test('omezení pokusů: po 5 chybách zámek, vypršení a nulování', function () {
    [$h,, $z] = ctx();
    eq(0, $z->zbyva());
    for ($i = 0; $i < 4; $i++) $z->neuspech('zkouška');
    eq(0, $z->zbyva(), 'po 4 chybách ještě ne');
    $z->neuspech('zkouška');
    ok($z->zbyva() > 280, 'zámek po 5. chybě');
    ok(str_contains($z->zprava(), 'Zkuste to za 5 min'), $z->zprava());
    ok(str_contains(end($h->protokol->zaznamy)[1], 'dočasně zablokováno'), 'záznam v protokolu');
    $h->nastaveni->uloz('login_last_fail', (string)(time() - 301));
    eq(0, $z->zbyva(), 'zámek po 5 minutách vyprší');
    $z->neuspech('zkouška');
    eq(0, $z->zbyva(), 'po vypršení se počítá znovu od jedné');
    $z->neuspech('x'); $z->neuspech('x'); $z->neuspech('x'); $z->neuspech('x');
    ok($z->zbyva() > 0);
    $z->vynuluj();
    eq(0, $z->zbyva(), 'vynulováno');
});

// ---------- heslo ----------
test('heslo: nastavení, ověření, minimální délka, záložní otisk z konfigurace', function () {
    [$h,,, $heslo] = ctx();
    ok(!$heslo->jeNastavene() && !$heslo->over('cokoli'), 'bez hesla nikdo nevstoupí');
    throws(fn() => $heslo->nastav('krátké'), 'aspoň 8 znaků');
    $heslo->nastav('dlouhe-heslo-123');
    ok($heslo->jeNastavene() && $heslo->over('dlouhe-heslo-123') && !$heslo->over('jine'));
    ok(!str_contains((string)$h->nastaveni->dej('password_hash'), 'dlouhe-heslo'), 'ukládá se jen otisk');
    $zaloha = new Heslo($h->nastaveni, password_hash('z-konfigurace', PASSWORD_DEFAULT));
    ok($zaloha->over('dlouhe-heslo-123') && !$zaloha->over('z-konfigurace'), 'nastavené heslo má přednost');
    $h2 = novyHost();
    $jenZaloha = new Heslo($h2->nastaveni, password_hash('z-konfigurace', PASSWORD_DEFAULT));
    ok($jenZaloha->over('z-konfigurace'), 'záložní otisk platí, dokud heslo není nastavené');
});

// ---------- obnova hesla ----------
test('obnova hesla: špatná nebo chybějící adresa nic neodešle', function () {
    [$h,,,, $o,, $m] = ctx();
    $o->zadost('kdo@example.com');
    eq(0, count(maily($m)), 'bez nastaveného e-mailu');
    $h->nastaveni->uloz('notify_email', 'majitel@example.com');
    $h->nastaveni->uloz('reset_last_sent', '0');
    $o->zadost('jiny@example.com');
    eq(0, count(maily($m)), 'jiná adresa');
});

test('obnova hesla: odkaz v e-mailu, nové heslo, odkaz jde použít jen jednou', function () {
    [$h, $t, $z, $heslo, $o,, $m] = ctx();
    $h->nastaveni->uloz('notify_email', 'Majitel@Example.com');
    $heslo->nastav('stare-heslo-123');
    $z->neuspech('x');
    $o->zadost('majitel@example.com');
    eq(1, count(maily($m)));
    $mail = (string)file_get_contents(maily($m)[0]);
    ok(str_contains($mail, 'To: Majitel@Example.com'), $mail);
    ok(str_contains($mail, ZAKLAD . '/?reset='), 'odkaz vede na základní adresu');
    $tok = odkazZMailu($m);
    ok($tok !== '');
    throws(fn() => $o->pouzij($tok, 'kratke'), 'aspoň 8 znaků');
    ok($t->platny('reset', $tok), 'krátké heslo token nespotřebuje');
    $o->pouzij($tok, 'nove-heslo-123');
    ok($heslo->over('nove-heslo-123') && !$heslo->over('stare-heslo-123'));
    eq(0, (int)$h->nastaveni->dej('login_fails'), 'zámek se nuluje');
    throws(fn() => $o->pouzij($tok, 'dalsi-heslo-123'), 'už neplatí');
});

test('obnova hesla: nejvýš jedna žádost za 2 minuty, nová žádost zneplatní starý odkaz', function () {
    [$h, $t,,, $o,, $m] = ctx();
    $h->nastaveni->uloz('notify_email', 'majitel@example.com');
    $o->zadost('majitel@example.com');
    $prvni = odkazZMailu($m);
    $o->zadost('majitel@example.com');
    eq(1, count(maily($m)), 'druhá žádost hned po první se ignoruje');
    $h->nastaveni->uloz('reset_last_sent', (string)(time() - 121));
    $o->zadost('majitel@example.com');
    eq(2, count(maily($m)));
    ok(!$t->platny('reset', $prvni), 'starý odkaz neplatí');
    eq(1, $t->pocetPlatnych('reset'), 'platný je jen nejnovější odkaz');
});

test('obnova hesla: vlastní tvar odkazu (Social Planner: login.php?reset=)', function () {
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('prihlaseni', Migrace::class);
    $poslano = [];
    $o = new Obnova($h->nastaveni, new Tokeny($h->db), new Heslo($h->nastaveni), new Zamek($h->nastaveni, $h->protokol), $h->protokol,
        function (string $komu, string $predmet, string $text) use (&$poslano) { $poslano = [$komu, $predmet, $text]; return true; },
        fn() => 'a@b.cz', 'https://sp.example.cz/', 'Social Planner', '{zaklad}/login.php?reset={token}');
    $o->zadost('A@B.cz');
    ok(str_contains($poslano[2], 'https://sp.example.cz/login.php?reset='), $poslano[2]);
    eq('Obnovení hesla – Social Planner', $poslano[1]);
});

// ---------- Google ----------
function idToken(array $claims): string { return 'h.' . Base64Url::koduj(json_encode($claims)) . '.s'; }

test('Google: kontrola ID tokenu (vydavatel, aplikace, platnost, nonce, ověřený e-mail)', function () {
    $g = new Google('klient-123', 'tajne', ZAKLAD . '/login-google.php', fn() => ['code' => 500, 'json' => null]);
    $good = ['iss' => 'https://accounts.google.com', 'aud' => 'klient-123', 'exp' => time() + 600, 'nonce' => 'abc', 'sub' => '1001', 'email' => 'ja@example.com', 'email_verified' => true];
    eq(['sub' => '1001', 'email' => 'ja@example.com'], $g->overToken(idToken($good), 'abc'));
    foreach ([['iss' => 'https://evil.example'], ['aud' => 'jina-aplikace'], ['exp' => time() - 5], ['nonce' => 'jiny'], ['email_verified' => false], ['sub' => ''], ['email' => '']] as $bad) {
        throws(fn() => $g->overToken(idToken($bad + $good), 'abc'), '', 'zamítnout ' . show($bad));
    }
    throws(fn() => $g->overToken(idToken($good), ''), 'nonce');
    throws(fn() => $g->overToken('nesmysl', 'abc'));
});

test('Google: výměna kódu přes předaný HTTP klient, adresa přihlášení, chybné odpovědi', function () {
    $good = ['iss' => 'accounts.google.com', 'aud' => 'klient-123', 'exp' => time() + 600, 'nonce' => 'n1', 'sub' => '7', 'email' => 'a@b.cz', 'email_verified' => 'true'];
    $volano = [];
    $g = new Google('klient-123', 'tajne', ZAKLAD . '/login-google.php', function ($m, $u, $o) use (&$volano, $good) {
        $volano = [$m, $u, $o];
        return ['code' => 200, 'json' => ['id_token' => idToken($good)]];
    });
    eq(['sub' => '7', 'email' => 'a@b.cz'], $g->vymenKod('kod-z-googlu', 'n1'));
    eq(['POST', 'https://oauth2.googleapis.com/token'], [$volano[0], $volano[1]]);
    eq(['kod-z-googlu', 'klient-123', 'tajne', 'authorization_code'], [$volano[2]['form']['code'], $volano[2]['form']['client_id'], $volano[2]['form']['client_secret'], $volano[2]['form']['grant_type']]);
    $url = $g->adresaPrihlaseni('stav', 'n1');
    ok(str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?') && str_contains($url, 'state=stav') && str_contains($url, 'nonce=n1'), $url);
    $chyba = new Google('k', 's', 'r', fn() => ['code' => 400, 'json' => ['error' => 'invalid_grant']]);
    throws(fn() => $chyba->vymenKod('x', 'n'), 'invalid_grant');
    ok($g->nastaveno() && !(new Google('', '', 'r', fn() => []))->nastaveno());
});

// ---------- CBOR ----------
test('CBOR: čísla, řetězce, pole a mapy (vzorky z RFC 8949)', function () {
    $d = function (string $hex) { $o = 0; return Cbor::dekoduj(hex2bin($hex), $o); };
    eq(0, $d('00')); eq(23, $d('17')); eq(500, $d('1901f4')); eq(-1, $d('20')); eq(-7, $d('26')); eq(-257, $d('390100'));
    eq('', $d('60')); eq('IETF', $d('6449455446')); eq("\x01\x02", $d('420102'));
    eq([1, 2, 3], $d('83010203'));
    eq([1 => 2, 3 => 4], $d('a201020304'));
    eq([-1 => 1, 'a' => [true, null]], $d('a22001' . '6161' . '82f5f6'));
    throws(fn() => $d('5a'), 'CBOR');
    throws(fn() => $d('4401'), 'CBOR');
    throws(fn() => $d(''), 'CBOR');
});

test('Base64Url: obousměrně, neplatný vstup', function () {
    $x = random_bytes(33);
    eq($x, Base64Url::dekoduj(Base64Url::koduj($x)));
    ok(!str_contains(Base64Url::koduj("\xfb\xff\xfe"), '+') && !str_contains(Base64Url::koduj("\xfb\xff\xfe"), '='));
    throws(fn() => Base64Url::dekoduj('***'), 'Neplatná data');
});

// ---------- Passkey: virtuální zařízení ----------
function cbHead(int $major, int $n): string
{
    if ($n < 24) return chr($major << 5 | $n);
    if ($n < 256) return chr($major << 5 | 24) . chr($n);
    if ($n < 65536) return chr($major << 5 | 25) . pack('n', $n);
    return chr($major << 5 | 26) . pack('N', $n);
}
function cbInt(int $n): string { return $n >= 0 ? cbHead(0, $n) : cbHead(1, -1 - $n); }
function cbBytes(string $s): string { return cbHead(2, strlen($s)) . $s; }
function cbText(string $s): string { return cbHead(3, strlen($s)) . $s; }
function cbMap(array $pairs): string { $o = cbHead(5, count($pairs)); foreach ($pairs as [$k, $v]) $o .= $k . $v; return $o; }

final class FakeKey
{
    public $pkey;
    public string $credId;
    public int $counter = 0;

    public function __construct(private Passkey $pk, private bool $rsa = false)
    {
        $this->pkey = $rsa ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])
                           : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credId = random_bytes(32);
    }
    private function cose(): string
    {
        $d = openssl_pkey_get_details($this->pkey);
        if ($this->rsa) return cbMap([[cbInt(1), cbInt(3)], [cbInt(3), cbInt(-257)], [cbInt(-1), cbBytes($d['rsa']['n'])], [cbInt(-2), cbBytes($d['rsa']['e'])]]);
        return cbMap([[cbInt(1), cbInt(2)], [cbInt(3), cbInt(-7)], [cbInt(-1), cbInt(1)],
            [cbInt(-2), cbBytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT))], [cbInt(-3), cbBytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))]]);
    }
    private function authData(array $o, bool $attested): string
    {
        $flags = $o['flags'] ?? (0x01 | 0x04 | ($attested ? 0x40 : 0));
        $ad = hash('sha256', $o['rp'] ?? $this->pk->rpId(), true) . chr($flags) . pack('N', $o['counter'] ?? $this->counter);
        if ($attested) $ad .= str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . $this->cose();
        return $ad;
    }
    private function clientData(string $type, string $challenge, array $o): string
    {
        return json_encode(['type' => $type, 'challenge' => $o['challenge'] ?? $challenge, 'origin' => $o['origin'] ?? $this->pk->puvod(), 'crossOrigin' => false]);
    }
    public function create(string $challenge, array $o = []): array
    {
        $att = cbMap([[cbText('fmt'), cbText('none')], [cbText('attStmt'), cbMap([])], [cbText('authData'), cbBytes($this->authData($o, true))]]);
        return ['id' => Base64Url::koduj($this->credId), 'rawId' => Base64Url::koduj($this->credId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => Base64Url::koduj($this->clientData('webauthn.create', $challenge, $o)), 'attestationObject' => Base64Url::koduj($att)]];
    }
    public function get(string $challenge, array $o = []): array
    {
        $this->counter = $o['counter'] ?? $this->counter + 1;
        $ad = $this->authData($o + ['counter' => $this->counter], false);
        $cd = $this->clientData('webauthn.get', $challenge, $o);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $this->pkey, OPENSSL_ALGO_SHA256);
        if (!empty($o['badsig'])) $sig[strlen($sig) - 1] = chr(ord($sig[strlen($sig) - 1]) ^ 1);
        return ['id' => Base64Url::koduj($this->credId), 'rawId' => Base64Url::koduj($this->credId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => Base64Url::koduj($cd), 'authenticatorData' => Base64Url::koduj($ad), 'signature' => Base64Url::koduj($sig), 'userHandle' => null]];
    }
}
function zaregistruj(Passkey $pk, FakeKey $k, string $nazev = 'Zkouška', array $o = []): int
{
    return $pk->registruj($k->create($pk->moznostiRegistrace()['publicKey']['challenge'], $o), $nazev);
}
function prihlasit(Passkey $pk, FakeKey $k, array $o = []): array
{
    return $pk->overPrihlaseni($k->get($pk->moznostiPrihlaseni()['publicKey']['challenge'], $o));
}

test('Passkey ES256: registrace a přihlášení, počítadlo roste', function () {
    [$h,,,,, $pk] = ctx();
    $k = new FakeKey($pk);
    $id = zaregistruj($pk, $k, 'Telefon');
    $row = $h->db->q('SELECT * FROM passkeys WHERE id = ?', [$id])->fetch();
    eq('Telefon', $row['name']);
    ok(str_contains($row['public_key'], 'BEGIN PUBLIC KEY'));
    eq(-7, (int)$row['alg']);
    eq($id, (int)prihlasit($pk, $k)['id']);
    prihlasit($pk, $k);
    eq(2, (int)$h->db->q('SELECT sign_count FROM passkeys WHERE id = ?', [$id])->fetchColumn());
    ok($h->db->q('SELECT last_used FROM passkeys WHERE id = ?', [$id])->fetchColumn() !== null, 'last_used');
    eq(['Telefon'], array_column($pk->seznam(), 'name'));
});

test('Passkey RS256 (Windows Hello): registrace a přihlášení', function () {
    [,,,,, $pk] = ctx();
    $k = new FakeKey($pk, true);
    zaregistruj($pk, $k, 'Počítač');
    prihlasit($pk, $k);
    prihlasit($pk, $k);
});

test('Passkey: čas zápisu lze předat (Social Planner ukládá čas jako číslo)', function () {
    [$h, $t] = ctx();
    $pk = new Passkey($h->db, $t, ZAKLAD, 'SP', 'u', 'u', fn() => 1700000000);
    $k = new FakeKey($pk);
    $id = zaregistruj($pk, $k);
    eq(1700000000, (int)$h->db->q('SELECT created_at FROM passkeys WHERE id = ?', [$id])->fetchColumn());
});

test('Passkey: zamítnutá přihlášení', function () {
    [$h, $t,,,, $pk] = ctx();
    $k = new FakeKey($pk);
    zaregistruj($pk, $k);
    throws(fn() => prihlasit($pk, $k, ['badsig' => true]), 'podpis');
    throws(fn() => prihlasit($pk, $k, ['origin' => 'https://evil.example']), 'původ');
    throws(fn() => prihlasit($pk, $k, ['rp' => 'evil.example']), 'jiné adrese');
    throws(fn() => prihlasit($pk, $k, ['flags' => 0x01]), 'neověřilo uživatele');
    throws(fn() => prihlasit($pk, $k, ['flags' => 0x04]), 'přítomnosti');
    throws(fn() => prihlasit($pk, $k, ['challenge' => Base64Url::koduj(random_bytes(32))]), 'Výzva');
    throws(fn() => prihlasit($pk, new FakeKey($pk)), 'není zaregistrované');
    // opakování stejné odpovědi (replay): výzva je jednorázová
    $cred = $k->get($pk->moznostiPrihlaseni()['publicKey']['challenge']);
    $pk->overPrihlaseni($cred);
    throws(fn() => $pk->overPrihlaseni($cred), 'Výzva');
    // počítadlo se nesmí vrátit zpět (klonovaný klíč)
    throws(fn() => prihlasit($pk, $k, ['counter' => 1]), 'Počítadlo');
    // výzva po vypršení
    $ch = $pk->moznostiPrihlaseni()['publicKey']['challenge'];
    $h->db->q('UPDATE auth_tokens SET expires = ? WHERE token_hash = ?', [time() - 1, hash('sha256', $ch)]);
    throws(fn() => $pk->overPrihlaseni($k->get($ch)), 'Výzva');
    // výzva z registrace nejde použít k přihlášení
    $reg = $pk->moznostiRegistrace()['publicKey']['challenge'];
    throws(fn() => $pk->overPrihlaseni($k->get($reg)), 'Výzva');
});

test('Passkey: zamítnutá registrace a možnosti registrace', function () {
    [$h,,,,, $pk] = ctx();
    $k = new FakeKey($pk);
    throws(fn() => zaregistruj($pk, $k, 'x', ['origin' => 'https://evil.example']), 'původ');
    throws(fn() => zaregistruj($pk, $k, 'x', ['rp' => 'evil.example']), 'jiné adrese');
    throws(fn() => zaregistruj($pk, $k, 'x', ['flags' => 0x41]), 'neověřilo uživatele');
    throws(fn() => zaregistruj($pk, $k, 'x', ['flags' => 0x05]), 'údaje o klíči');
    zaregistruj($pk, $k, 'jednou');
    throws(fn() => zaregistruj($pk, $k, 'podruhé'), 'už je zaregistrované');
    eq(1, (int)$h->db->q('SELECT COUNT(*) FROM passkeys')->fetchColumn());
    $opts = $pk->moznostiRegistrace()['publicKey'];
    eq('reklamy.example.cz', $opts['rp']['id']);
    eq('required', $opts['authenticatorSelection']['userVerification']);
    eq(1, count($opts['excludeCredentials']), 'už zaregistrovaná zařízení se vylučují');
});

test('Passkey: příliš mnoho nepoužitých výzev, smazání zařízení, dostupnost jen na HTTPS', function () {
    [$h, $t,,,, $pk] = ctx();
    for ($i = 0; $i < 101; $i++) $t->vytvor('wa-login', 300);
    throws(fn() => $pk->moznostiPrihlaseni(), 'Příliš mnoho požadavků');
    $k = new FakeKey($pk);
    $id = zaregistruj($pk, $k);
    ok($pk->smaz($id) && !$pk->smaz($id));
    ok($pk->dostupne());
    ok(!(new Passkey($h->db, $t, 'http://reklamy.example.cz', 'x'))->dostupne(), 'prostý HTTP ne');
    ok((new Passkey($h->db, $t, 'http://localhost:8080', 'x'))->dostupne(), 'localhost ano');
    eq('http://localhost:8080', (new Passkey($h->db, $t, 'http://localhost:8080/app', 'x'))->puvod());
});

// ---------- relace ----------
test('relace: přihlášení, odhlášení po nečinnosti, pasivní požadavek čas neprodlužuje', function () {
    $h = novyHost();
    $s = [];
    $r = new Relace($s, $h->protokol, fn() => null);
    ok(!$r->jePrihlasen(), 'bez přihlášení');
    $r->prihlas('heslo');
    ok($r->jePrihlasen(60));
    eq(['info', 'Přihlášení: heslo.'], $h->protokol->zaznamy[0]);
    $s['auth_last'] = time() - 30;
    ok($r->jePrihlasen(60, true), 'pasivní uvnitř limitu');
    ok($s['auth_last'] <= time() - 29, 'pasivní čas neobnovil');
    ok($r->jePrihlasen(60), 'běžný požadavek');
    ok($s['auth_last'] >= time() - 1, 'běžný čas obnovil');
    $s['auth_last'] = time() - 61;
    ok(!$r->jePrihlasen(60, true), 'po limitu odhlášen');
    eq('Byli jste odhlášeni z důvodu nečinnosti.', $r->vezmiChybu());
    eq(null, $r->vezmiChybu(), 'hláška se ukáže jen jednou');
    ok(!isset($s['auth']), 'příznak smazán');
    eq('Odhlášení z důvodu nečinnosti.', end($h->protokol->zaznamy)[1]);
    $r->prihlas('heslo');
    $s['auth_last'] = time() - 99999;
    ok($r->jePrihlasen(0), '0 = nikdy neodhlašovat');
});

test('relace: zbývající čas, odhlášení, nové ID relace při přihlášení', function () {
    $h = novyHost();
    $s = [];
    $zavolano = 0;
    $r = new Relace($s, $h->protokol, function () use (&$zavolano) { $zavolano++; });
    $r->prihlas('Passkey');
    eq(1, $zavolano, 'ID relace se obnoví');
    eq(null, $r->zbyvaSekund(0));
    $zb = $r->zbyvaSekund(100);
    ok($zb > 95 && $zb <= 100, (string)$zb);
    $r->odhlas();
    ok(!$r->jePrihlasen(60) && !isset($s['auth_last']));
});

test('CSRF: token je stálý, ověří se jen shodný', function () {
    $h = novyHost();
    $s = [];
    $r = new Relace($s, $h->protokol, fn() => null);
    ok(!$r->csrfOver('cokoli'), 'bez tokenu nic neprojde');
    $t = $r->csrfToken();
    eq(32, strlen($t));
    eq($t, $r->csrfToken());
    ok($r->csrfOver($t));
    ok(!$r->csrfOver($t . 'x') && !$r->csrfOver('') && !$r->csrfOver(null) && !$r->csrfOver(['a']));
});

test('migrace: tabulky vzniknou a znovu se nevytvářejí', function () {
    $h = novyHost();
    $m = new JadroMigrace($h->db);
    ok($m->spust('prihlaseni', Migrace::class));
    ok(!$m->spust('prihlaseni', Migrace::class));
    foreach (['auth_tokens', 'passkeys'] as $t) eq(1, (int)$h->db->q("SELECT COUNT(*) FROM sqlite_master WHERE name = ?", [$t])->fetchColumn(), $t);
});
