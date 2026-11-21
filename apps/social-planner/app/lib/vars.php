<?php
declare(strict_types=1);
// Proměnné v textu příspěvku: {{datum}}, {{den}} … a vlastní (text nebo seznam hodnot, který se střídá při každém opakování).

const BUILTIN_VARS = [
    'datum'   => 'datum zveřejnění (19. 11. 2026); {{datum:+1}} = následující den',
    'den'     => 'den v týdnu (čtvrtek); {{Den}} = Čtvrtek',
    'mesic'   => 'měsíc (listopad)',
    'rok'     => 'rok (2026)',
    'cas'     => 'čas zveřejnění (18:30)',
    'cislo'   => 'pořadí výskytu opakování (1, 2, 3 …)',
    'tyden'   => 'číslo týdne (47)',
    'svatek'  => 'kdo má svátek v den zveřejnění (český kalendář); {{svatek:+1}} = zítra',
    'odpocet' => '{{odpocet:24.12.2026}} → za 34 dní / zítra / dnes',
    'dnu'     => '{{dnu:24.12.2026}} → počet dní do data (číslo)',
    'hodina'  => 'hodina zveřejnění (18)',
];
/** Vlastní proměnné: name => ['type' => text|list, 'value' => …]. */
function user_vars(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT name, type, value FROM variables")->fetchAll() as $r) $out[$r['name']] = $r;
    return $out;
}

/** Pořadí výskytu příspěvku v sérii (0 = první). */
function series_index(PDO $pdo, array $post): int {
    if (empty($post['series_id']) || empty($post['scheduled_at'])) return 0;
    $q = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE series_id = ? AND scheduled_at < ?");
    $q->execute([$post['series_id'], $post['scheduled_at']]);
    return (int)$q->fetchColumn();
}

/** Nahradí {{proměnné}} v textu (kalendář, vlastní proměnné, data, zdroje, počasí). Neznámé nechá beze změny. */
function expand_vars(string $text, int $ts, int $index, array $user): string {
    if (strpos($text, '{{') === false) return $text;
    require_once __DIR__ . '/data.php';
    return resolve_vars($text, dt_from_ts($ts), ['index' => $index, 'user' => $user])[0];
}

/** Neznámé proměnné v textu (celé zápisy, např. „{{Sportka.nejbližší}}“). */
function unknown_vars(string $text, array $user, ?int $ts = null): array {
    if (strpos($text, '{{') === false) return [];
    require_once __DIR__ . '/data.php';
    return resolve_vars($text, dt_from_ts($ts ?? time()), ['index' => 0, 'user' => $user])[1];
}
