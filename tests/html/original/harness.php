<?php
declare(strict_types=1);
// Spouští zmrazený html.php z Plánovače. Vstup (JSON, stdin): {op, src, t, fonts:{klic:{name,weights,custom,files:{vaha:cesta}}}}
require dirname(__DIR__, 2) . '/slidy/stubs.php';
$in = json_decode((string)stream_get_contents(STDIN), true);
$GLOBALS['FONTY'] = $in['fonts'];
function resolve_text(string $t, DateTimeImmutable $d): array { return stub_resolve($t, $d); }
function fonts_all(): array { return $GLOBALS['FONTY']; }
function font_file(string $k, int $w): string { return $GLOBALS['FONTY'][$k]['files'][$w] ?? $GLOBALS['FONTY'][$k]['files'][array_key_first($GLOBALS['FONTY'][$k]['files'])]; }
require __DIR__ . '/html-pm.php';
$t = new DateTimeImmutable($in['t']);
try {
    $r = match ($in['op']) {
        'default' => html_default(),
        'check' => html_check(base64_decode($in['src'])),
        'resolve' => html_resolve(base64_decode($in['src']), $t),
        'fonts' => html_fonts_css(base64_decode($in['src'])),
        'build' => html_build(base64_decode($in['src']), $t),
        'hosts' => html_external_hosts(base64_decode($in['src'])),
    };
    echo json_encode(['ok' => $r]);
} catch (InvalidArgumentException $e) { echo json_encode(['chyba' => $e->getMessage()]); }
