<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

/** Odeslání prostého e-mailu. Při nastavené složce pro testy se e-mail jen uloží do souboru. */
final class Posta
{
    public function __construct(private string $odAdresa, private string $nazevAplikace, private ?string $slozkaProTesty = null) {}

    public function posli(string $komu, string $predmet, string $text): bool
    {
        if ($this->slozkaProTesty !== null) {
            @mkdir($this->slozkaProTesty, 0775, true);
            return file_put_contents($this->slozkaProTesty . '/mail-' . date('His') . '-' . bin2hex(random_bytes(3)) . '.txt', "To: $komu\nSubject: $predmet\n\n$text") !== false;
        }
        $hlavicky = [
            'From: ' . mb_encode_mimeheader($this->nazevAplikace, 'UTF-8') . ' <' . $this->odAdresa . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return (bool)@mail($komu, '=?UTF-8?B?' . base64_encode($predmet) . '?=', $text, implode("\r\n", $hlavicky));
    }
}
