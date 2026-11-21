<?php
declare(strict_types=1);
// Veřejná úvodní stránka. Administrace začíná na prehled.php (po přihlášení).
require __DIR__ . '/app/lib/bootstrap.php';
session_start_safe();
$logged = !empty($_SESSION['auth']);
$adminUrl = $logged ? 'prehled.php' : 'login.php';
$adminLabel = $logged ? 'Přejít do administrace' : 'Přihlášení do administrace';

// Nastavení titulní stránky (Administrace → Nastavení → Titulní stránka)
require __DIR__ . '/app/lib/landing.php';
$L = landing_settings(db());
if (!$L['enabled']) { header('Location: ' . $adminUrl); exit; }
$demo = [
    ['label' => 'Reklamní banner', 'title' => 'Prostor pro vaši reklamu', 'button' => 'Zjistit více'],
    ['label' => 'Reklamní plocha', 'title' => 'Vaše reklamní sdělení může být právě zde', 'button' => 'Zobrazit nabídku'],
    ['label' => 'Reklamní banner', 'title' => 'Místo pro další reklamu', 'button' => 'Zjistit více'],
    ['label' => 'Reklamní plocha', 'title' => 'Oslovte návštěvníky i na konci stránky', 'button' => 'Zobrazit nabídku'],
];
$ad = function (int $i, string $variant) use ($L, $demo): void {
    $slot = $L['slots'][$i] ?? ['mode' => 'off'];
    if ($slot['mode'] === 'off') return;
    if ($slot['mode'] === 'code') {
        if (trim($slot['code']) === '') return;
        // Kód zadal správce v administraci, vkládá se záměrně beze změny (reklamní sítě potřebují vlastní HTML a skripty).
        echo '<aside class="ad-slot ad-slot-' . ($i + 1) . '" aria-label="Reklama">';
        if ($slot['label']) echo '<span class="ad-slot-label">Reklama</span>';
        echo '<div class="ad-slot-code">' . $slot['code'] . '</div></aside>';
        return;
    }
    $a = $demo[$i];
    ?>
    <aside class="ad ad-<?= $variant ?>" aria-label="<?= h($a['label']) ?>">
      <?php if ($variant === 'light'): ?><svg class="ad-blob" viewBox="0 0 600 120" preserveAspectRatio="none" aria-hidden="true"><path d="M0 120 C120 40 260 110 380 60 S560 0 600 30 L600 120 Z"/></svg><?php endif; ?>
      <span class="ad-label"><?= h($a['label']) ?></span>
      <span class="ad-sep" aria-hidden="true"></span>
      <strong class="ad-title"><?= h($a['title']) ?></strong>
      <?php if ($variant === 'light'): ?>
      <svg class="ad-megaphone" viewBox="0 0 120 90" aria-hidden="true">
        <path d="M18 58 l10 22 h12 l-6 -20 z" fill="#3b1fa8"/>
        <path d="M14 38 h18 l46 -24 v62 l-46 -24 h-18 a8 8 0 0 1 -8 -8 v-0 a6 6 0 0 1 8 -6z" fill="#6d4cf0"/>
        <ellipse cx="80" cy="45" rx="10" ry="31" fill="#fff" stroke="#6d4cf0" stroke-width="4"/>
        <ellipse cx="80" cy="45" rx="4" ry="12" fill="#c9b8ff"/>
        <path d="M98 22 l8 -8 M102 45 h11 M98 68 l8 8" stroke="#2dd4bf" stroke-width="4" stroke-linecap="round"/>
      </svg>
      <?php endif; ?>
      <a class="btn <?= $variant === 'light' ? 'btn-outline' : 'btn-white' ?>" href="#o-aplikaci"><?= h($a['button']) ?> <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </aside>
    <?php
};

// Ilustrace kalendáře: aktuální měsíc, tři týdny od pondělí tohoto týdne
$monthsNom = [1 => 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen', 'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
$monthsGen = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
$today = new DateTimeImmutable('today');
$monday = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
$marks = [2 => 'fb', 9 => 'ig', 11 => 'th'];   // posun ve dnech od pondělí => síť
$day = fn(int $off) => $monday->modify("+$off days");
$cardDate = fn(int $off, string $time) => $day($off)->format('j') . '. ' . $monthsGen[(int)$day($off)->format('n')] . ' • ' . $time;
$cssV = (int)@filemtime(__DIR__ . '/assets/css/landing.css');
?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Social Planner – plánování příspěvků na sociální sítě</title>
  <meta name="description" content="Jednoduché místo pro plánování a správu příspěvků na Facebooku, Instagramu a Threads.">
  <meta name="generator" content="<?= h(APP_NAME . ' ' . APP_VERSION) ?>">
  <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/css/landing.css?v=<?= $cssV ?>">
  <link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<?php if (!$L['indexable']): ?>  <meta name="robots" content="noindex,nofollow">
<?php endif; ?>
<?= $L['head_code'] !== '' ? $L['head_code'] . "\n" : '' ?>
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-in">
    <a class="brand" href="index.php" aria-label="Social Planner – domů">
      <img src="assets/img/logo.svg" alt="" width="44" height="44">
      <span>Social Planner</span>
    </a>
    <nav class="topnav" aria-label="Hlavní">
      <a href="#top" class="active">Domů</a>
      <a href="#o-aplikaci">O aplikaci</a>
    </nav>
    <a class="btn btn-primary btn-sm" href="<?= $adminUrl ?>">
      <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
      <span class="hide-xs"><?= h($adminLabel) ?></span><span class="show-xs">Přihlásit</span>
    </a>
  </div>
</header>

<main id="top">
  <section class="hero wrap">
    <div class="hero-text">
      <span class="eyebrow">Plánování sociálních sítí</span>
      <h1>Všechny příspěvky.<br><span class="underline">Jeden</span> přehled.</h1>
      <p class="lead">Jednoduché místo pro plánování a správu vašich příspěvků na sociálních sítích.</p>
      <div class="hero-cta">
        <a class="btn btn-primary btn-lg" href="<?= $adminUrl ?>">
          <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
          <?= $logged ? 'Přejít do administrace' : 'Přihlásit se do administrace' ?>
        </a>
        <a class="btn btn-ghost btn-lg" href="#jak-to-funguje">
          <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M10 8l6 4-6 4z" class="fill"/></svg>
          Jak to funguje
        </a>
      </div>
    </div>

    <div class="hero-art" aria-hidden="true">
      <div class="blob"></div>
      <svg class="sparks" viewBox="0 0 60 50"><path d="M8 6l6 16M26 4l-4 18M44 14l-14 12"/></svg>
      <div class="cal">
        <div class="cal-head">
          <svg class="ic" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
          <strong>Plán příspěvků</strong>
          <span class="cal-month"><span class="arr">‹</span> <?= h($monthsNom[(int)$today->format('n')] . ' ' . $today->format('Y')) ?> <span class="arr">›</span></span>
        </div>
        <div class="cal-grid">
          <?php foreach (['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'] as $d): ?><span class="dow"><?= $d ?></span><?php endforeach; ?>
          <?php for ($i = 0; $i < 21; $i++): $d = $day($i); ?>
            <span class="cell<?= $d == $today ? ' today' : '' ?>"><?= $d->format('j') ?><?php if (isset($marks[$i])): ?><i class="chk chk-<?= $marks[$i] ?>"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i><?php endif; ?></span>
          <?php endfor; ?>
        </div>
      </div>
      <div class="post post-fb"><span class="net net-fb">FB</span><div><b>Facebook</b><small><?= h($cardDate(2, '10:00')) ?></small></div><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i><span class="thumb t1"></span><span class="lines"><i></i><i></i></span></div>
      <div class="post post-ig"><span class="net net-ig">IG</span><div><b>Instagram</b><small><?= h($cardDate(9, '14:00')) ?></small></div><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i><span class="thumb t2"></span><span class="lines"><i></i><i></i></span></div>
      <div class="post post-th"><span class="net net-th">TH</span><div><b>Threads</b><small><?= h($cardDate(11, '9:00')) ?></small></div><i class="ok"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i><span class="thumb t3"></span><span class="lines"><i></i><i></i></span></div>
    </div>
  </section>

  <div class="wrap"><?php $ad(0, 'light'); ?></div>

  <section class="wrap features" aria-label="Hlavní funkce">
    <article class="feat">
      <span class="feat-ic ic-violet"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/></svg></span>
      <div><h2>Kalendář příspěvků</h2><p>Přehledně si zobrazte všechny naplánované příspěvky v kalendáři.</p></div>
    </article>
    <article class="feat">
      <span class="feat-ic ic-teal"><svg class="ic" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg></span>
      <div><h2>Přehled plánování</h2><p>Mějte své příspěvky, termíny a platformy vždy pod kontrolou.</p></div>
    </article>
    <article class="feat">
      <span class="feat-ic ic-violet"><svg class="ic" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
      <div><h2>Rychlý přístup</h2><p>Snadné a rychlé přihlášení do vaší administrace.</p></div>
    </article>
  </section>

  <div class="wrap"><?php $ad(1, 'dark'); ?></div>

  <section class="wrap steps" id="jak-to-funguje">
    <span class="eyebrow">Jak to funguje</span>
    <h2 class="sec-title">Tři kroky od nápadu ke zveřejnění</h2>
    <ol class="step-list">
      <li><span class="num">1</span><h3>Propojte účty</h3><p>Facebook stránky, Instagram a Threads propojíte jedním přihlášením. Žádná hesla k sítím se neukládají.</p></li>
      <li><span class="num">2</span><h3>Napište a naplánujte</h3><p>Text, obrázky nebo video, štítky a čas. Jeden příspěvek pošlete na více účtů najednou, s náhledem pro každou síť.</p></li>
      <li><span class="num">3</span><h3>Zbytek proběhne sám</h3><p>Ve zvolený čas se příspěvek zveřejní. Výsledky uvidíte ve statistikách, včetně doporučení nejlepšího času.</p></li>
    </ol>
  </section>

  <div class="wrap"><?php $ad(2, 'light'); ?></div>

  <section class="wrap about" id="o-aplikaci">
    <div>
      <span class="eyebrow">O aplikaci</span>
      <h2 class="sec-title">Soukromý plánovač pro vlastní účty</h2>
      <p>Social Planner běží na vlastním hostingu a slouží jen svému majiteli. Data, přístupy k sítím i zálohy zůstávají u vás, žádná cizí služba je nevidí.</p>
    </div>
    <ul class="about-list">
      <li><b>Sítě:</b> Facebook stránky, Instagram (příspěvky, galerie, reels, stories) a Threads</li>
      <li><b>Plánování:</b> kalendář, opakování, šablony, sady hashtagů, hromadné plánování</li>
      <li><b>Výsledky:</b> statistiky dosahu a reakcí, doporučení nejlepšího času</li>
      <li><b>Bezpečí:</b> přihlášení heslem nebo Passkey, automatické zálohy i na Google Drive</li>
    </ul>
  </section>

  <div class="wrap ad-bottom"><?php $ad(3, 'dark'); ?></div>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <span><b>Social Planner</b> • Soukromá aplikace <span class="ver">· verze <?= h(APP_VERSION) ?></span></span>
    <a href="<?= $adminUrl ?>"><?= h($adminLabel) ?></a>
  </div>
</footer>
<script>
// zvýraznění položky menu podle sekce na obrazovce
(function () {
  const links = document.querySelectorAll('.topnav a');
  const about = document.getElementById('o-aplikaci');
  if (!('IntersectionObserver' in window) || !about) return;
  new IntersectionObserver((e) => {
    const on = e[0].isIntersecting;
    links[0].classList.toggle('active', !on); links[1].classList.toggle('active', on);
  }, { rootMargin: '-40% 0px -40% 0px' }).observe(about);
})();
</script>
</body>
</html>
