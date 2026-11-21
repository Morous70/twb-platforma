<?php
declare(strict_types=1);
// Spouští ORIGINÁLNÍ kód slidů (Social Planner 1.9.2) a vypíše JSON: očištěnou šablonu, klíč obsahu a JPEG (base64).
// Vstup (stdin, JSON): {"raw": šablona, "t": "Y-m-d H:i:s", "root": složka s assets/ a uploads/, "library": [{"id","file","type"}]}
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/stubs.php';
$in = json_decode((string)stream_get_contents(STDIN), true);
define('ROOT', $in['root']);
define('PUBLIC_DIR', $in['root'] . '/public');
function meta_get(string $k, $d = null) { return $d; }
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        global $in;
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE library (id INTEGER PRIMARY KEY, file TEXT, type TEXT)');
        foreach ($in['library'] as $r) $pdo->prepare('INSERT INTO library VALUES (?,?,?)')->execute([$r['id'], $r['file'], $r['type']]);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}
function user_vars($pdo): array { return []; }
function resolve_vars(string $text, DateTimeImmutable $t, array $ctx, bool $x): array { return stub_resolve($text, $t); }
function conds_ok(array $c, DateTimeImmutable $t): bool { return stub_conds($c, $t); }
// počasí: data z Plánovače/Social Planneru (data.php, weather.php) nahrazují jednoduché funkce stejného znění
const DT_DAYS = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
function cfg(string $k, $d = null) { return $d; }
function weather_http(string $url): array { throw new RuntimeException('bez sítě'); }
function weather_get(string $place, bool $fetch = true): array { $d = $GLOBALS['in']['pocasi'][$place] ?? null; return [$d, $d ? null : 'Počasí zatím není stažené.']; }
function weather_place(?string $p): string { return trim((string)$p) !== '' ? trim((string)$p) : 'Praha'; }
function weather_int(float $v): string { $r = (int)round($v); return (string)($r === 0 ? 0 : $r); }
function weather_ucfirst(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
function weather_day(array $data, int $n, DateTimeImmutable $t): ?array {
    $date = $t->setTime(12, 0)->modify("+$n day")->format('Y-m-d');
    foreach ($data['days'] ?? [] as $d) if (($d['date'] ?? '') === $date) return $d;
    return null;
}
function weather_day_label(string $date, DateTimeImmutable $t): string {
    if ($date === $t->format('Y-m-d')) return 'Dnes';
    if ($date === $t->modify('+1 day')->format('Y-m-d')) return 'Zítra';
    return weather_ucfirst(DT_DAYS[(int)(new DateTimeImmutable($date))->format('N')]);
}
require __DIR__ . '/slide-sp-1.9.2.php';
mt_srand(1);
$design = sanitize_design($in['raw']);
$t = new DateTimeImmutable($in['t']);
echo json_encode(['design' => $design, 'key' => render_key($design, $t), 'jpeg' => base64_encode(render_slide($design, $t))]);
