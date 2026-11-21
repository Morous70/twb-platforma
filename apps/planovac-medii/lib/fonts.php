<?php
// Vlastní písma pro slidy (Nastavení → Obrázky → Písma pro slidy).
// Soubory jsou v data/fonts (aktualizace je nepřepíše), seznam v nastavení custom_fonts: {key: {name, file, created_at}}.
require_once __DIR__ . '/slide.php';

const FONT_MAX_BYTES = 5 * 1024 * 1024;
const FONT_CZ_CHARS = 'ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚŤĎŇ';

function fonts_custom(): array {
    $c = json_decode((string)setting('custom_fonts', '{}'), true);
    return is_array($c) ? $c : [];
}
function fonts_custom_save(array $c): void {
    set_setting('custom_fonts', json_encode($c, JSON_UNESCAPED_UNICODE));
    fonts_all(true);
}

/** Otisk vykresleného znaku (prázdný = null). */
function font_glyph_sig(string $file, string $ch): ?string {
    $im = imagecreatetruecolor(64, 64);
    imagettftext($im, 32, 0, 12, 48, 0xffffff, $file, $ch);
    $data = '';
    $any = false;
    for ($y = 0; $y < 64; $y += 2) for ($x = 0; $x < 64; $x += 2) { $v = imagecolorat($im, $x, $y) & 255; $data .= chr($v); if ($v > 30) $any = true; }
    return $any ? md5($data) : null;
}

/** České znaky, které písmo nemá (vykreslí se jako prázdno nebo jako náhradní obdélník). */
function font_missing_chars(string $file): array {
    $notdef = font_glyph_sig($file, "\u{FFFE}");
    $miss = [];
    foreach (mb_str_split(FONT_CZ_CHARS) as $ch) {
        $sig = font_glyph_sig($file, $ch);
        if ($sig === null || $sig === $notdef) $miss[] = $ch;
    }
    return $miss;
}

/** Nahraje písmo. Vrací [písmo, chybějící české znaky]. */
function font_upload(string $tmp, string $origName, string $name = ''): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['ttf', 'otf'], true)) throw new InvalidArgumentException('Písmo musí být soubor TTF nebo OTF.');
    $size = @filesize($tmp);
    if (!$size) throw new InvalidArgumentException('Soubor je prázdný.');
    if ($size > FONT_MAX_BYTES) throw new InvalidArgumentException('Písmo je větší než 5 MB.');
    $head = (string)file_get_contents($tmp, false, null, 0, 4);
    if (!in_array($head, ["\x00\x01\x00\x00", 'OTTO', 'true', 'ttcf'], true)) throw new InvalidArgumentException('Soubor není písmo TTF ani OTF.');
    if (!function_exists('imagettfbbox') || @imagettfbbox(20, 0, $tmp, 'Abc 123') === false) throw new InvalidArgumentException('Písmo se nepodařilo načíst – soubor je poškozený nebo ho server neumí použít.');
    @mkdir(CUSTOM_FONT_DIR, 0775, true);
    $key = 'u_' . bin2hex(random_bytes(5));
    $file = "$key.$ext";
    if (!copy($tmp, CUSTOM_FONT_DIR . "/$file")) throw new RuntimeException('Písmo se nepodařilo uložit na server.');
    $name = trim($name) !== '' ? trim($name) : trim(preg_replace('/[-_]+/', ' ', pathinfo($origName, PATHINFO_FILENAME)));
    $name = mb_substr($name !== '' ? $name : 'Moje písmo', 0, 60);
    $c = fonts_custom();
    $c[$key] = ['name' => $name, 'file' => $file, 'created_at' => date('Y-m-d H:i:s')];
    fonts_custom_save($c);
    $missing = font_missing_chars(CUSTOM_FONT_DIR . "/$file");
    app_log('info', "Nahráno písmo „{$name}“." . ($missing ? ' Chybí české znaky: ' . implode(' ', $missing) . '.' : ''));
    return [font_out($key), $missing];
}

function font_out(string $key): array {
    $f = fonts_all()[$key];
    return ['key' => $key, 'name' => $f['name'], 'weights' => $f['weights'], 'custom' => $f['custom'],
        'files' => array_combine($f['weights'], array_map(fn($w) => $f['custom'] ? 'api.php?a=font&key=' . $key : "assets/fonts/{$f['file']}-$w.ttf", $f['weights']))];
}

/** Slidy, které písmo používají (názvy). */
function font_usage(string $key): array {
    $names = [];
    foreach (q("SELECT original_name, content FROM media WHERE kind = 'slide'")->fetchAll() as $m) {
        $d = json_decode((string)$m['content'], true)['design'] ?? [];
        foreach ($d['elements'] ?? [] as $e) if (($e['font'] ?? '') === $key) { $names[] = $m['original_name']; break; }
    }
    return $names;
}

function font_rename(string $key, string $name): array {
    $c = fonts_custom();
    if (!isset($c[$key])) throw new InvalidArgumentException('Písmo nenalezeno.');
    $name = mb_substr(trim($name), 0, 60);
    if ($name === '') throw new InvalidArgumentException('Zadejte název písma.');
    $c[$key]['name'] = $name;
    fonts_custom_save($c);
    return font_out($key);
}

function font_delete(string $key): void {
    $c = fonts_custom();
    if (!isset($c[$key])) throw new InvalidArgumentException('Písmo nenalezeno.');
    $used = font_usage($key);
    if ($used) throw new InvalidArgumentException('Písmo je použité ve slidu ' . implode(', ', array_map(fn($n) => "„{$n}“", array_slice($used, 0, 5)))
        . (count($used) > 5 ? ' a dalších' : '') . '. Nejdřív ve slidu vyberte jiné písmo.');
    @unlink(CUSTOM_FONT_DIR . '/' . $c[$key]['file']);
    $name = $c[$key]['name'];
    unset($c[$key]);
    fonts_custom_save($c);
    app_log('info', "Odebráno písmo „{$name}“.");
}

/** Soubory vlastních písem pro zálohu: [soubor => md5]. */
function font_backup_files(): array {
    $out = [];
    foreach (fonts_custom() as $f) {
        $p = CUSTOM_FONT_DIR . '/' . ($f['file'] ?? '');
        if (preg_match('/^u_[a-z0-9]+\.(ttf|otf)$/', $f['file'] ?? '') && is_file($p)) $out[$f['file']] = md5_file($p);
    }
    return $out;
}
