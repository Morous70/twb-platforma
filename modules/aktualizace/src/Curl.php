<?php
declare(strict_types=1);
namespace TWB\Aktualizace;

use RuntimeException;

/** Výchozí HTTP klient (curl) pro Update Server a kontrolu zdraví. */
final class Curl
{
    public function __construct(private string $token, private string $agent, private int $maxVelikost) {}

    /** @return array{code:int, body:string} */
    public function stahni(string $url, ?string $ulozit = null): array
    {
        if (!str_starts_with($url, 'https://') && !preg_match('~^http://127\.0\.0\.1[:/]~', $url)) throw new RuntimeException('Update Server musí mít adresu https://.');
        $ch = curl_init($url);
        $hlavicky = ['Accept: application/json'];
        if ($this->token !== '') { $hlavicky[] = "Authorization: Bearer {$this->token}"; $hlavicky[] = "X-Update-Token: {$this->token}"; }
        $opt = [CURLOPT_TIMEOUT => $ulozit ? 600 : 30, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => $this->agent, CURLOPT_HTTPHEADER => $hlavicky];
        $fh = null;
        if ($ulozit) {
            $fh = fopen($ulozit, 'wb');
            $opt[CURLOPT_FILE] = $fh;
            $opt[CURLOPT_NOPROGRESS] = false;
            $max = $this->maxVelikost;
            $opt[CURLOPT_PROGRESSFUNCTION] = fn($c, $celkem, $stazeno) => $stazeno > $max ? 1 : 0;   // příliš velké → přerušit
        } else {
            $opt[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opt);
        $telo = curl_exec($ch);
        $err = curl_error($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($fh) fclose($fh);
        if ($telo === false) throw new RuntimeException("Update Server neodpovídá: $err");
        return ['code' => $kod, 'body' => $ulozit ? '' : (string)$telo];
    }

    /** Kontrola zdraví nové verze. @return array{code:int, body:string, error:string} */
    public static function zdravi(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'TWB-update-health']);
        $telo = curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($telo) ? $telo : '', 'error' => $telo === false ? curl_error($ch) : ''];
    }
}
