<?php
declare(strict_types=1);
// Nastavení titulní stránky (index.php): reklamní plochy, kód do <head>, indexace. Ukládá se do tabulky meta jako JSON.

const LANDING_CODE_MAX = 30000;   // max. délka jednoho reklamního kódu (znaky)

function landing_defaults(): array {
    return [
        'enabled'   => true,          // false = index.php přesměruje rovnou na přihlášení
        'indexable' => true,          // false = <meta name="robots" content="noindex">
        'head_code' => '',            // kód do <head> (např. skript reklamní sítě)
        'slots' => [
            // mode: demo = ukázková plocha z návrhu, code = vlastní reklamní kód, off = skryto
            ['mode' => 'demo', 'code' => '', 'label' => true],   // 1: pod úvodem
            ['mode' => 'demo', 'code' => '', 'label' => true],   // 2: pod kartami funkcí
            ['mode' => 'demo', 'code' => '', 'label' => true],   // 3: pod sekcí Jak to funguje (od 1.9.0)
            ['mode' => 'demo', 'code' => '', 'label' => true],   // 4: těsně nad patičkou (od 1.9.0)
        ],
    ];
}

function landing_settings(PDO $pdo): array {
    $raw = $pdo->query("SELECT value FROM meta WHERE key = 'landing'")->fetchColumn();
    $d = landing_defaults();
    $s = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($s)) return $d;
    $out = [
        'enabled'   => (bool)($s['enabled'] ?? $d['enabled']),
        'indexable' => (bool)($s['indexable'] ?? $d['indexable']),
        'head_code' => (string)($s['head_code'] ?? ''),
        'slots'     => [],
    ];
    foreach ($d['slots'] as $i => $def) {
        $x = $s['slots'][$i] ?? [];
        $mode = in_array($x['mode'] ?? '', ['demo', 'code', 'off'], true) ? $x['mode'] : $def['mode'];
        $out['slots'][$i] = ['mode' => $mode, 'code' => (string)($x['code'] ?? ''), 'label' => (bool)($x['label'] ?? true)];
    }
    return $out;
}

/** Uloží nastavení z formuláře ($_POST). Vrací uložené hodnoty, nebo vyhodí výjimku s českou chybou. */
function landing_save(PDO $pdo, array $in): array {
    $s = landing_defaults();
    $s['enabled']   = !empty($in['enabled']);
    $s['indexable'] = !empty($in['indexable']);
    $s['head_code'] = trim(str_replace("\r\n", "\n", (string)($in['head_code'] ?? '')));
    if (mb_strlen($s['head_code']) > LANDING_CODE_MAX) throw new RuntimeException('Kód do hlavičky je příliš dlouhý.');
    foreach ($s['slots'] as $i => &$slot) {
        $mode = (string)($in["slot{$i}_mode"] ?? 'demo');
        $slot['mode']  = in_array($mode, ['demo', 'code', 'off'], true) ? $mode : 'demo';
        $slot['code']  = trim(str_replace("\r\n", "\n", (string)($in["slot{$i}_code"] ?? '')));
        $slot['label'] = !empty($in["slot{$i}_label"]);
        if (mb_strlen($slot['code']) > LANDING_CODE_MAX) throw new RuntimeException('Reklamní kód plochy ' . ($i + 1) . ' je příliš dlouhý.');
        if ($slot['mode'] === 'code' && $slot['code'] === '') throw new RuntimeException('U plochy ' . ($i + 1) . ' je zvolen reklamní kód, ale pole je prázdné. Vložte kód, nebo zvolte jinou možnost.');
    }
    unset($slot);
    $pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('landing', ?)")->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
    log_line('NASTAVENÍ titulní stránky uloženo');
    return $s;
}
