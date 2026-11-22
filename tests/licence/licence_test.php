<?php
// Licence: číselník shodný se Social Plannerem, odhad z textu a odkazu, název, odkaz, uvedení autora.
require dirname(__DIR__) . '/lib.php';

use TWB\Licence\Licence;

echo "Licence\n";

test('číselník je shodný s IMG_LICENSES v Social Planneru 1.9.2 (kódy i pořadí)', function () {
    eq(['cc0', 'cc-by', 'cc-by-sa', 'vlastni', 'placena', 'pixabay', 'pexels', 'ai', 'jine'], array_keys(Licence::KODY));
    eq(array_keys(Licence::KODY), array_keys(Licence::NAPOVEDA));
    eq(['cc-by', 'cc-by-sa', 'jine'], Licence::S_VERZI);
});

test('vyčištění: prázdné = neuvedeno, verze jen kde dává smysl, neznámý kód a špatná verze se odmítnou', function () {
    eq([null, null], Licence::vycisti('', '4.0'));
    eq(['cc-by', '4.0'], Licence::vycisti('cc-by', ' 4.0 '));
    eq(['cc0', null], Licence::vycisti('cc0', '4.0'));
    eq(['jine', 'GFDL 1.3'], Licence::vycisti('jine', 'GFDL  1.3'));
    eq(['cc-by-sa', null], Licence::vycisti('cc-by-sa', ''));
    throws(fn() => Licence::vycisti('cc-by-nc', null), 'Neznámý kód');
    throws(fn() => Licence::vycisti('cc-by', 'čtyři'), 'číslo');
    throws(fn() => Licence::vycisti('jine', str_repeat('x', 121)), '120');
});

test('odhad z odkazu Creative Commons, Pixabay a Pexels', function () {
    eq(['kod' => 'cc-by-sa', 'verze' => '4.0'], Licence::odhad(null, 'https://creativecommons.org/licenses/by-sa/4.0/deed.cs'));
    eq(['kod' => 'cc-by', 'verze' => '2.0'], Licence::odhad('cokoli', 'http://creativecommons.org/licenses/by/2.0/'));
    eq(['kod' => 'jine', 'verze' => 'CC BY-NC 4.0'], Licence::odhad(null, 'https://creativecommons.org/licenses/by-nc/4.0/'));
    eq(['kod' => 'cc0', 'verze' => null], Licence::odhad(null, 'https://creativecommons.org/publicdomain/zero/1.0/'));
    eq(['kod' => 'pixabay', 'verze' => null], Licence::odhad(null, 'https://pixabay.com/service/license-summary/'));
    eq(['kod' => 'pexels', 'verze' => null], Licence::odhad(null, 'https://www.pexels.com/license/'));
});

test('odhad z textu: CC v různých zápisech, NC/ND jako Jiné, volné dílo, copyright = neurčeno', function () {
    eq(['kod' => 'cc-by-sa', 'verze' => '4.0'], Licence::odhad('CC BY-SA 4.0'));
    eq(['kod' => 'cc-by', 'verze' => '3.0'], Licence::odhad('CC-BY-3.0'));
    eq(['kod' => 'cc-by-sa', 'verze' => '4.0'], Licence::odhad('Creative Commons Attribution-ShareAlike 4.0 International'));
    eq(['kod' => 'cc-by', 'verze' => null], Licence::odhad('Licence: CC BY'));
    eq(['kod' => 'jine', 'verze' => 'CC BY-NC-ND 4.0'], Licence::odhad('Creative Commons Attribution-NonCommercial-NoDerivatives 4.0'));
    eq(['kod' => 'cc0', 'verze' => null], Licence::odhad('CC0 1.0'));
    eq(['kod' => 'cc0', 'verze' => null], Licence::odhad('Public domain'));
    eq(['kod' => 'cc0', 'verze' => null], Licence::odhad('volné dílo'));
    eq(['kod' => 'pixabay', 'verze' => null], Licence::odhad('Pixabay License'));
    eq(['kod' => 'ai', 'verze' => null], Licence::odhad('Vygenerováno AI'));
    eq(['kod' => null, 'verze' => null], Licence::odhad('© 2026 Jan Novák, všechna práva vyhrazena'));
    eq(['kod' => null, 'verze' => null], Licence::odhad('Copyright Eva'));
    eq(['kod' => 'jine', 'verze' => 'GNU Free Documentation License'], Licence::odhad('GNU Free Documentation License'));
    eq(['kod' => null, 'verze' => null], Licence::odhad('  ', ''));
});

test('název a odkaz na licenci', function () {
    eq('CC BY-SA 4.0', Licence::nazev('cc-by-sa', '4.0'));
    eq('CC BY', Licence::nazev('cc-by', null));
    eq('CC0 (volné dílo)', Licence::nazev('cc0', '9'));
    eq('GFDL', Licence::nazev('jine', 'GFDL'));
    eq('Jiné', Licence::nazev('jine', ''));
    eq(null, Licence::nazev(null));
    eq(null, Licence::nazev('neznama'));
    eq('https://creativecommons.org/licenses/by-sa/3.0/', Licence::odkaz('cc-by-sa', '3.0'));
    eq('https://creativecommons.org/licenses/by/4.0/', Licence::odkaz('cc-by'));
    eq('https://example.com/l', Licence::odkaz('cc-by', '4.0', 'https://example.com/l'));
    eq('https://creativecommons.org/licenses/by/4.0/', Licence::odkaz('cc-by', '4.0', 'javascript:alert(1)'), 'nebezpečný odkaz se nepoužije');
    eq(null, Licence::odkaz('vlastni'));
    eq('https://pixabay.com/service/license-summary/', Licence::odkaz('pixabay'));
});

test('uvedení autora: text, HTML s odkazy a escapováním, povinnost, (upraveno)', function () {
    $u = Licence::uvedeni(['autor' => 'Eva <Malá>', 'zdroj' => 'TWB Media galerie', 'zdroj_url' => 'https://galerie.example/?p=medium&id=3', 'kod' => 'cc-by', 'verze' => '4.0']);
    eq('Foto: Eva <Malá>, TWB Media galerie, CC BY 4.0', $u['text']);
    eq('Foto: Eva &lt;Malá&gt;, <a href="https://galerie.example/?p=medium&amp;id=3" target="_blank" rel="noopener">TWB Media galerie</a>, '
        . '<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener license">CC BY 4.0</a>', $u['html']);
    ok($u['povinne']);
    eq(['autor', 'zdroj', 'licence'], array_column($u['casti'], 'druh'));
    eq('Foto: Eva, CC BY-SA (upraveno)', Licence::uvedeni(['autor' => 'Eva', 'kod' => 'cc-by-sa'], 'Foto: ', true)['text']);
    $v = Licence::uvedeni(['autor' => 'Já', 'kod' => 'vlastni', 'zdroj_url' => 'javascript:x']);
    eq('Foto: Já, Vlastní foto', $v['text']);
    ok(!$v['povinne']);
    eq('', Licence::uvedeni([])['text']);
    eq('', Licence::uvedeni(['zdroj_url' => 'https://x.example'])['html']);
    eq('Autor: Petr', Licence::uvedeni(['autor' => 'Petr', 'kod' => 'nesmysl'], 'Autor: ')['text']);
});

