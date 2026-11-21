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
require __DIR__ . '/slide-sp-1.9.2.php';
mt_srand(1);
$design = sanitize_design($in['raw']);
$t = new DateTimeImmutable($in['t']);
echo json_encode(['design' => $design, 'key' => render_key($design, $t), 'jpeg' => base64_encode(render_slide($design, $t))]);
