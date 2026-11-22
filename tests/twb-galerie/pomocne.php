<?php
// Pomůcky pro testy aplikace TWB Media galerie (spouštějte přes: php tests/run.php twb-galerie).
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
require KOREN . '/apps/twb-galerie/lib/autoload.php';

use MediaGalerie\App;

/** Dočasné složky testů se po skončení smažou. */
function docasna(string $predpona): string
{
    static $slozky = null;
    if ($slozky === null) {
        $slozky = [];
        register_shutdown_function(function () use (&$slozky) {
            foreach ($slozky as $d) if (is_dir($d) || is_file($d)) exec('rm -rf ' . escapeshellarg($d));
        });
    }
    $d = sys_get_temp_dir() . '/twb-galerie-' . $predpona . '-' . bin2hex(random_bytes(4));
    $slozky[] = $d;
    return $d;
}

/** Nová aplikace s prázdnou databází v dočasné složce. @param array<string, mixed> $cfg */
function novaApp(array $cfg = []): App
{
    $dir = docasna('app');
    @mkdir($dir, 0775, true);
    $app = new App(KOREN . '/apps/twb-galerie', $cfg + ['data_dir' => $dir]);
    $app->migruj();
    return $app;
}

/** Soubor MP4 (hlavička ftyp + výplň), který finfo pozná jako video/mp4 – náhodná data by občas poznal jako jiný typ. */
function mp4(int $n): string { return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x07", max(0, $n - 24)); }

/** JPEG (GD) s volitelnými metadaty: IPTC (klíč „2#025“ => seznam hodnot), XMP (obsah rdf:Description) a EXIF IFD0 (tag => [typ, data]). */
function jpegMeta(array $iptc = [], ?string $xmp = null, array $exif = [], int $w = 64, int $h = 48): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 30, 140, 90));
    ob_start(); imagejpeg($im, null, 85); $jpg = (string)ob_get_clean();
    $segmenty = '';
    if ($exif) {
        // TIFF little-endian: hlavička, IFD0 s položkami, data za IFD
        ksort($exif);
        $n = count($exif);
        $dataOd = 8 + 2 + $n * 12 + 4;
        $ifd = pack('v', $n); $data = '';
        foreach ($exif as $tag => [$typ, $hodnota]) {
            $delka = strlen($hodnota);
            if ($delka <= 4) $ifd .= pack('vvV', $tag, $typ, $delka) . str_pad($hodnota, 4, "\0");
            else { $ifd .= pack('vvVV', $tag, $typ, $delka, $dataOd + strlen($data)); $data .= $hodnota . (strlen($hodnota) % 2 ? "\0" : ''); }
        }
        $tiff = "II*\0" . pack('V', 8) . $ifd . pack('V', 0) . $data;
        $obsah = "Exif\0\0" . $tiff;
        $segmenty .= "\xFF\xE1" . pack('n', strlen($obsah) + 2) . $obsah;
    }
    if ($xmp !== null) {
        $obsah = "http://ns.adobe.com/xap/1.0/\0" . '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" xmlns:cc="http://creativecommons.org/ns#"'
            . $xmp . '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
        $segmenty .= "\xFF\xE1" . pack('n', strlen($obsah) + 2) . $obsah;
    }
    $jpg = substr($jpg, 0, 2) . $segmenty . substr($jpg, 2);
    if ($iptc) {
        $data = '';
        foreach ($iptc as $klic => $hodnoty) {
            [$rec, $ds] = array_map('intval', explode('#', $klic));
            foreach ((array)$hodnoty as $v) $data .= chr(0x1C) . chr($rec) . chr($ds) . pack('n', strlen($v)) . $v;
        }
        $f = tempnam(sys_get_temp_dir(), 'jpg'); file_put_contents($f, $jpg);
        $jpg = (string)iptcembed($data, $f); unlink($f);
    }
    return $jpg;
}

/** Řetězec jako EXIF ASCII (typ 2) a jako Windows XP… (typ 1, UTF-16LE). */
function exifText(string $s): array { return [2, $s . "\0"]; }
function exifXp(string $s): array { return [1, mb_convert_encoding($s, 'UTF-16LE', 'UTF-8') . "\0\0"]; }
