<?php
require __DIR__ . '/db.php';

/* ---------- Handle form submissions (PRG pattern) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $anchor = $action === 'newsletter' ? '#footer' : '#contact';

    // Engagement beacon (sendBeacon) — anonymous counters only, so no CSRF.
    if ($action === 'track') {
        $event = (string)($_POST['event'] ?? '');
        if (in_array($event, ['quote_click'], true)) {
            et_track($event, (string)($_POST['label'] ?? ''));
        }
        http_response_code(204);
        exit;
    }

    if (!csrf_check()) {
        flash_set('error', 'Your session expired — please try again.');
        header("Location: index.php$anchor");
        exit;
    }
    // Honeypot: real users never fill this hidden field.
    if (!empty($_POST['website'])) {
        header("Location: index.php$anchor");
        exit;
    }

    $clean = static fn(string $k, int $max = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);

    if ($action === 'contact') {
        $name     = $clean('name', 120);
        $company  = $clean('company', 120);
        $email    = $clean('email', 160);
        $phone    = $clean('phone', 60);
        $industry = $clean('industry', 40);
        $interest = $clean('interest', 200);
        $message  = $clean('message', 3000);

        $errors = [];
        if ($name === '')    $errors[] = 'your name';
        if ($message === '') $errors[] = 'a message';
        if ($email === '' && $phone === '') $errors[] = 'an email or phone number';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'a valid email address';

        if ($errors) {
            flash_set('error', 'Please provide ' . implode(', ', $errors) . '.');
        } else {
            db()->prepare('INSERT INTO inquiries (name, company, email, phone, industry, interest, message, source)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $company, $email, $phone, $industry, $interest, $message, 'contact']);
            flash_set('success', 'Thank you, ' . $name . ' — your inquiry has been received. Our team will contact you shortly.');
        }
        header('Location: index.php#contact');
        exit;
    }

    if ($action === 'newsletter') {
        $email = $clean('email', 160);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Please enter a valid email address to subscribe.');
        } else {
            db()->prepare('INSERT INTO inquiries (name, email, interest, message, source)
                           VALUES (?, ?, ?, ?, ?)')
                ->execute(['Newsletter subscriber', $email, 'Newsletter subscription', 'Requested product news and updates.', 'newsletter']);
            flash_set('success', 'You are subscribed — we will keep you posted on new machinery and offers.');
        }
        header('Location: index.php#footer');
        exit;
    }
}

et_track_pageview();

/* ---------- Page data ---------- */
$s        = et_settings();
$products = et_products();
$flash    = flash_get();

$sectorCounts = ['agriculture' => 0, 'construction' => 0, 'mining' => 0];
foreach ($products as $p) {
    $sectorCounts[$p['sector']]++;
}
$totalProducts = count($products);
$prefillInterest = mb_substr(trim((string)($_GET['interest'] ?? '')), 0, 200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($s['company_name']) ?> — Agriculture, Construction &amp; Mining Machinery</title>
<meta name="description" content="Authorized importer and distributor of Doğanlar and Zoomlion machinery in Ethiopia — tractors, implements, excavators, cranes and mining equipment with nationwide delivery and after-sales support.">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%2314170F'/%3E%3Cpath d='M4 24L12 9L16 16L21 7L28 24' stroke='%23C1782E' stroke-width='2.6' fill='none' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/tokens.css">
<link rel="stylesheet" href="assets/site.css">
</head>
<body id="top">

<!-- ======= Top utility bar ======= -->
<div class="topbar">
  <div class="wrap">
    <div class="tb-group">
      <?php if ($s['phone']): ?>
      <a class="tb-item" href="tel:<?= e(preg_replace('/[^+\d]/', '', $s['phone'])) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3-8.7A2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 2 .7 2.9a2 2 0 01-.5 2.1L8 10a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c1 .3 2 .5 3 .6a2 2 0 011.6 2z"/></svg>
        <?= e($s['phone']) ?>
      </a>
      <?php endif; ?>
      <?php if ($s['email']): ?>
      <a class="tb-item" href="mailto:<?= e($s['email']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
        <?= e($s['email']) ?>
      </a>
      <?php endif; ?>
      <?php if ($s['hours']): ?>
      <span class="tb-item">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        <?= e($s['hours']) ?>
      </span>
      <?php endif; ?>
    </div>
    <span class="tb-note">Authorized importer — Doğanlar · Zoomlion</span>
  </div>
</div>

<!-- ======= Header ======= -->
<header class="site" id="siteHeader">
  <div class="wrap nav-row">
    <a href="#top" class="logo">
      <svg class="logo-mark" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M2 24L11 8L16 16L21 6L30 24" stroke="#C1782E" stroke-width="2.4" stroke-linejoin="round"/>
      </svg>
      Ethio<span class="dot">Tractors</span>
    </a>
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <nav class="main" id="mainnav" aria-label="Main navigation">
      <ul>
        <li><a href="#industries">Industries</a></li>
        <li><a href="#products">Products</a></li>
        <li><a href="#brands">Brands</a></li>
        <li><a href="#services">Services</a></li>
        <li><a href="#faq">FAQ</a></li>
        <li><a href="#contact">Contact</a></li>
        <li><a href="#contact" class="btn btn-solid">Request a Quote <span class="arr">→</span></a></li>
      </ul>
    </nav>
  </div>
</header>

<main>

  <!-- ======= Hero ======= -->
  <section class="hero" style="padding:0">
    <div class="hero-bg" aria-hidden="true"></div>
    <div class="wrap">
      <div class="hero-kicker">Addis Ababa · Ethiopia</div>
      <h1>Machinery for the <span class="accent">work</span> that builds Ethiopia</h1>
      <p class="hero-sub">
        <?= e($s['company_name']) ?> imports and supports agriculture, construction and mining
        equipment from Doğanlar and Zoomlion — matched to your soil, your site and your budget.
      </p>
      <div class="hero-ctas">
        <a href="#products" class="btn btn-solid">Explore the catalog <span class="arr">→</span></a>
        <a href="#contact" class="btn btn-inv">Request a quote</a>
      </div>
      <div class="hero-stats">
        <div class="hero-stat"><div class="n" data-count="<?= $totalProducts ?>">0</div><div class="l">Machine &amp; implement lines</div></div>
        <div class="hero-stat"><div class="n" data-count="3">0</div><div class="l">Industries served</div></div>
        <div class="hero-stat"><div class="n" data-count="2">0</div><div class="l">Global brand partners</div></div>
        <div class="hero-stat"><div class="n">∞</div><div class="l">Parts &amp; after-sales support</div></div>
      </div>
    </div>
    <a class="hero-scroll" href="#industries">Scroll</a>
  </section>

  <!-- ======= Partner marquee ======= -->
  <div class="partner-strip" aria-label="Brand partners">
    <div class="marquee">
      <?php for ($i = 0; $i < 2; $i++): ?>
      <div class="marquee-set" <?= $i ? 'aria-hidden="true"' : '' ?>>
        <span class="mq-brand">DOĞANLAR<small>Agriculture — Turkey</small></span>
        <span class="mq-sep"></span>
        <span class="mq-word">Authorized Importer &amp; Distributor</span>
        <span class="mq-sep"></span>
        <span class="mq-brand">ZOOMLION<small>Heavy Industry — China</small></span>
        <span class="mq-sep"></span>
        <span class="mq-word">Nationwide Delivery · Genuine Parts</span>
        <span class="mq-sep"></span>
        <span class="mq-brand">ETHIOTRACTORS<small>Addis Ababa — Ethiopia</small></span>
        <span class="mq-sep"></span>
        <span class="mq-word">Field · Site · Mine</span>
        <span class="mq-sep"></span>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- ======= Industries ======= -->
  <section id="industries">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">What We Move</span>
          <h2>Three Sectors,<br>One Importer</h2>
        </div>
        <p class="lede">From breaking soil to breaking ground, EthioTractors sources and supports the machinery Ethiopia's producers and contractors run on.</p>
      </div>
      <div class="ind-grid">
        <a href="#products" class="ind-card agri reveal" data-goto-sector="agriculture">
          <span class="count-chip"><?= $sectorCounts['agriculture'] ?> product lines</span>
          <div class="bg"></div>
          <div class="inner">
            <span class="num">01 — The Field</span>
            <h3>Agriculture</h3>
            <p>Tractors, harvesters, ploughs, harrows, cultivators and rotovators for Ethiopian soil.</p>
            <span class="go">View products <span class="arr">→</span></span>
          </div>
        </a>
        <a href="#products" class="ind-card con reveal" data-goto-sector="construction">
          <span class="count-chip"><?= $sectorCounts['construction'] ?> product lines</span>
          <div class="bg"></div>
          <div class="inner">
            <span class="num">02 — The Site</span>
            <h3>Construction</h3>
            <p>Excavators, loaders, bulldozers, cranes and concrete equipment for Ethiopia's growth.</p>
            <span class="go">View products <span class="arr">→</span></span>
          </div>
        </a>
        <a href="#products" class="ind-card mine reveal" data-goto-sector="mining">
          <span class="count-chip"><?= $sectorCounts['mining'] ?> product lines</span>
          <div class="bg"></div>
          <div class="inner">
            <span class="num">03 — The Mine</span>
            <h3>Mining</h3>
            <p>Dump trucks, drill rigs, crushers and screens for pit-to-stockpile operations.</p>
            <span class="go">View products <span class="arr">→</span></span>
          </div>
        </a>
      </div>
    </div>
  </section>

  <!-- ======= Products ======= -->
  <section id="products">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">The Catalog</span>
          <h2>Machinery &amp; Implements</h2>
        </div>
        <p class="lede">Every line we import, in one place. Filter by sector, search by name, and request a quote on the exact machine you need.</p>
      </div>

      <div class="cat-toolbar reveal">
        <div class="cat-tabs" role="tablist" aria-label="Filter by sector">
          <button class="cat-tab active" data-sector="all">All <span class="c"><?= $totalProducts ?></span></button>
          <?php foreach (ET_SECTORS as $key => $label): ?>
          <button class="cat-tab" data-sector="<?= $key ?>"><?= $label ?> <span class="c"><?= $sectorCounts[$key] ?></span></button>
          <?php endforeach; ?>
        </div>
        <div class="cat-search">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input type="search" id="catSearch" placeholder="Search machines, e.g. plough…" aria-label="Search products">
        </div>
      </div>

      <p class="cat-count" id="catCount">Showing all <?= $totalProducts ?> product lines</p>

      <div class="pgrid" id="pgrid">
        <?php foreach ($products as $p):
            $specs  = et_product_specs($p);
            $search = mb_strtolower($p['name'] . ' ' . $p['category'] . ' ' . $p['brand'] . ' ' . $p['tags']);
        ?>
        <article class="pcard reveal" data-sector="<?= e($p['sector']) ?>" data-search="<?= e($search) ?>">
          <div class="art">
            <span class="sector-flag <?= e($p['sector']) ?>"><?= e(ET_SECTORS[$p['sector']]) ?></span>
            <?php if ($p['image_url']): ?>
              <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            <?php else: ?>
              <?= et_icon($p['icon']) ?>
            <?php endif; ?>
          </div>
          <div class="body">
            <div class="cat-line"><?= e($p['brand']) ?><?= $p['category'] ? ' · ' . e($p['category']) : '' ?></div>
            <h3><?= e($p['name']) ?></h3>
            <p class="desc"><?= e($p['description']) ?></p>
            <?php if ($specs): ?>
            <div class="specs">
              <?php foreach (array_slice($specs, 0, 4) as $spec): ?>
              <div><span><?= e($spec[0] ?? '') ?></span><span><?= e($spec[1] ?? '') ?></span></div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="foot">
              <div class="tagrow">
                <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $p['tags']))), 0, 2) as $tag): ?>
                <span class="tag"><?= e($tag) ?></span>
                <?php endforeach; ?>
              </div>
              <button class="quote-link" data-quote="<?= e($p['name']) ?>" data-sector="<?= e($p['sector']) ?>">Get a quote →</button>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
        <div class="pgrid-empty" id="pgridEmpty" hidden>No machines match your search — try a different term, or <a href="#contact">ask us directly</a>.</div>
      </div>
    </div>
  </section>

  <!-- ======= Brands ======= -->
  <section id="brands">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">Who We Represent</span>
          <h2>Brands We Import</h2>
        </div>
        <p class="lede">Two manufacturers, decades of engineering — brought to Ethiopia with genuine parts and factory-backed support.</p>
      </div>
      <div class="brand-cards">
        <div class="brand-card doganlar reveal">
          <div>
            <div class="origin">Turkey — Established Agricultural Manufacturer</div>
            <h3>Doğanlar Agriculture</h3>
            <p>Nearly half a century of tillage-equipment manufacturing: ploughs, chisels, disc harrows, cultivators, tillers and rotovators — engineered and heat-treated for demanding soil conditions.</p>
          </div>
          <div class="b-foot">
            <a href="https://www.doganlartarim.com.tr" target="_blank" rel="noopener">doganlartarim.com.tr ↗</a>
            <span class="b-mark">DG</span>
          </div>
        </div>
        <div class="brand-card zoomlion reveal">
          <div>
            <div class="origin">China — Global Heavy Industry Manufacturer</div>
            <h3>Zoomlion</h3>
            <p>Earthmoving, mobile crane, construction hoisting, concrete, foundation, mining and agricultural machinery — backed by a global service and parts network.</p>
          </div>
          <div class="b-foot">
            <a href="https://en.zoomlion.com" target="_blank" rel="noopener">en.zoomlion.com ↗</a>
            <span class="b-mark">ZL</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ======= Services / why us ======= -->
  <section id="services" style="background:var(--paper-dim)">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">Beyond the Machine</span>
          <h2>Why EthioTractors</h2>
        </div>
        <p class="lede">Importing the equipment is the easy part. Getting it running — and keeping it running — is where we stay involved.</p>
      </div>
      <div class="why-grid reveal">
        <div class="why-item">
          <div class="num">01</div>
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
          <h3>Pre-Purchase Advisory</h3>
          <p>We help you match machine to job — soil type, site conditions, budget and expected utilization.</p>
        </div>
        <div class="why-item">
          <div class="num">02</div>
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 005.4-5.4L15 12l-3-3z"/></svg>
          <h3>Installation &amp; Commissioning</h3>
          <p>On-site assembly and commissioning for tractors, implements and heavy equipment.</p>
        </div>
        <div class="why-item">
          <div class="num">03</div>
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1-1.6 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.6-1 1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></svg>
          <h3>Parts &amp; After-Sales</h3>
          <p>Genuine spare parts sourcing and after-sales technical support from our service team.</p>
        </div>
        <div class="why-item">
          <div class="num">04</div>
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7"><rect x="1" y="6" width="14" height="11" rx="1"/><path d="M15 10h4l4 4v3h-8M5 21a2 2 0 100-4 2 2 0 000 4zM18 21a2 2 0 100-4 2 2 0 000 4z"/></svg>
          <h3>Nationwide Delivery</h3>
          <p>Coordinated delivery to farms, construction sites and mining operations across Ethiopia.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ======= Process ======= -->
  <section id="process">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">How We Work</span>
          <h2>From Inquiry to the Field</h2>
        </div>
        <p class="lede">A clear, four-step path from your first call to a machine working on your site.</p>
      </div>
      <div class="steps reveal">
        <div class="step">
          <div class="s-num">Step 01</div>
          <h3>Tell Us the Job</h3>
          <p>Share your site, farm size or project — by phone, email or the quote form below.</p>
        </div>
        <div class="step">
          <div class="s-num">Step 02</div>
          <h3>Sourcing &amp; Quotation</h3>
          <p>We match the right model and configuration, then send you pricing and availability.</p>
        </div>
        <div class="step">
          <div class="s-num">Step 03</div>
          <h3>Import &amp; Delivery</h3>
          <p>We handle importing and coordinate delivery to your location anywhere in Ethiopia.</p>
        </div>
        <div class="step">
          <div class="s-num">Step 04</div>
          <h3>Commissioning &amp; Support</h3>
          <p>On-site setup, operator handover and continued parts and service support.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ======= FAQ ======= -->
  <section id="faq">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">Common Questions</span>
          <h2>Before You Ask</h2>
        </div>
      </div>
      <div class="faq-wrap reveal">
        <details class="faq">
          <summary>Do you deliver outside Addis Ababa? <span class="ic">+</span></summary>
          <p class="faq-a">Yes — we coordinate delivery to farms, construction sites and mining operations across Ethiopia, and our team assists with on-site assembly and commissioning where needed.</p>
        </details>
        <details class="faq">
          <summary>Can you help me choose the right machine for my land or site? <span class="ic">+</span></summary>
          <p class="faq-a">That is exactly what our pre-purchase advisory is for. Tell us your soil type, plot or site size, and budget — we will recommend the machine and configuration that fits, not just the biggest one.</p>
        </details>
        <details class="faq">
          <summary>Are spare parts available locally? <span class="ic">+</span></summary>
          <p class="faq-a">We source genuine spare parts for every line we import and provide after-sales technical support through our service team, backed by Doğanlar's and Zoomlion's factory networks.</p>
        </details>
        <details class="faq">
          <summary>How do I get exact pricing and specifications? <span class="ic">+</span></summary>
          <p class="faq-a">Send an inquiry through the form below with the product you are interested in. We reply with the full specification sheet, current pricing and availability for that model.</p>
        </details>
        <details class="faq">
          <summary>Which brands do you officially represent? <span class="ic">+</span></summary>
          <p class="faq-a">We are an authorized importer and distributor of Doğanlar Agriculture (Turkey) tillage implements and Zoomlion (China) agriculture, construction and mining machinery — with more brand partnerships expanding.</p>
        </details>
      </div>
    </div>
  </section>

  <!-- ======= CTA band ======= -->
  <div class="cta-band">
    <div class="wrap">
      <div>
        <h2>Have a farm to equip or a site to build?</h2>
        <p>Talk to our team — quotes within one business day</p>
      </div>
      <a href="#contact" class="btn btn-inv">Request a quote <span class="arr">→</span></a>
    </div>
  </div>

  <!-- ======= Contact ======= -->
  <section id="contact" style="background:var(--paper-dim)">
    <div class="wrap">
      <div class="section-head reveal">
        <div>
          <span class="eyebrow">Get In Touch</span>
          <h2>Contact Us</h2>
        </div>
        <p class="lede">Tell us what you're moving, planting or building — we'll follow up with pricing and availability.</p>
      </div>

      <div class="contact-grid">
        <div class="contact-info reveal">
          <h3><?= e($s['company_name']) ?></h3>
          <dl>
            <?php if ($s['address']): ?>
            <div>
              <dt><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1116 0z"/><circle cx="12" cy="10" r="3"/></svg> Head Office</dt>
              <dd><?= e($s['address']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if ($s['phone']): ?>
            <div>
              <dt><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3-8.7A2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 2 .7 2.9a2 2 0 01-.5 2.1L8 10a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c1 .3 2 .5 3 .6a2 2 0 011.6 2z"/></svg> Phone</dt>
              <dd><a href="tel:<?= e(preg_replace('/[^+\d]/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a><?= $s['phone2'] ? ' · <a href="tel:' . e(preg_replace('/[^+\d]/', '', $s['phone2'])) . '">' . e($s['phone2']) . '</a>' : '' ?></dd>
            </div>
            <?php endif; ?>
            <?php if ($s['email']): ?>
            <div>
              <dt><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg> Email</dt>
              <dd><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></dd>
            </div>
            <?php endif; ?>
            <?php if ($s['branches']): ?>
            <div>
              <dt><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg> Branches</dt>
              <dd><?= e($s['branches']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if ($s['hours']): ?>
            <div>
              <dt><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Working Hours</dt>
              <dd><?= e($s['hours']) ?></dd>
            </div>
            <?php endif; ?>
          </dl>
          <?php if ($s['telegram'] || $s['facebook'] || $s['linkedin']): ?>
          <div class="socials">
            <?php if ($s['telegram']): ?><a href="<?= e($s['telegram']) ?>" target="_blank" rel="noopener" aria-label="Telegram"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M21 4L2 11l6 2 2 6 3-4 5 3z"/></svg></a><?php endif; ?>
            <?php if ($s['facebook']): ?><a href="<?= e($s['facebook']) ?>" target="_blank" rel="noopener" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M15 4h-2a4 4 0 00-4 4v3H7v4h2v6h4v-6h3l1-4h-4V8a1 1 0 011-1h3z"/></svg></a><?php endif; ?>
            <?php if ($s['linkedin']): ?><a href="<?= e($s['linkedin']) ?>" target="_blank" rel="noopener" aria-label="LinkedIn"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 10v7M7 7v.01M12 17v-4a2 2 0 014 0v4M12 13v4"/></svg></a><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <div class="contact-form reveal">
          <h3>Request a Quote</h3>
          <p class="fhint">Fields marked <span style="color:var(--ochre)">*</span> are required. We reply within one business day.</p>
          <form class="stack" method="post" action="index.php#contact" id="contactForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="contact">
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
            <div class="row-2">
              <div class="field">
                <label for="f-name">Full Name <span class="req">*</span></label>
                <input id="f-name" name="name" type="text" placeholder="Your name" required maxlength="120">
              </div>
              <div class="field">
                <label for="f-company">Company / Farm</label>
                <input id="f-company" name="company" type="text" placeholder="Optional" maxlength="120">
              </div>
            </div>
            <div class="row-2">
              <div class="field">
                <label for="f-email">Email <span class="req">*</span></label>
                <input id="f-email" name="email" type="email" placeholder="you@example.com" maxlength="160">
              </div>
              <div class="field">
                <label for="f-phone">Phone</label>
                <input id="f-phone" name="phone" type="tel" placeholder="+251 ..." maxlength="60">
              </div>
            </div>
            <div class="row-2">
              <div class="field">
                <label for="f-industry">Industry</label>
                <select id="f-industry" name="industry">
                  <option>Agriculture</option>
                  <option>Construction</option>
                  <option>Mining</option>
                  <option>Other</option>
                </select>
              </div>
              <div class="field">
                <label for="f-interest">Product of Interest</label>
                <input id="f-interest" name="interest" type="text" placeholder="e.g. Excavator, Disc Harrow" maxlength="200" value="<?= e($prefillInterest) ?>">
              </div>
            </div>
            <div class="field">
              <label for="f-message">Message <span class="req">*</span></label>
              <textarea id="f-message" name="message" placeholder="Tell us about your site, farm size, or project" required maxlength="3000"></textarea>
            </div>
            <button type="submit" class="btn btn-solid" style="align-self:flex-start">Send Inquiry <span class="arr">→</span></button>
          </form>
        </div>
      </div>

      <div class="contact-map reveal">
        <iframe title="EthioTractors location map" loading="lazy"
          src="https://www.google.com/maps?q=<?= rawurlencode($s['map_query'] ?: 'Addis Ababa, Ethiopia') ?>&output=embed"></iframe>
      </div>
    </div>
  </section>

</main>

<!-- ======= Footer ======= -->
<footer id="footer">
  <div class="wrap">
    <div class="footer-top">
      <div class="footer-brand">
        <a href="#top" class="logo">Ethio<span class="dot">Tractors</span></a>
        <p>Importing agriculture, construction and mining machinery for Ethiopia — from field to foundation.</p>
      </div>
      <div>
        <h4>Sectors</h4>
        <ul>
          <li><a href="#products">Agriculture</a></li>
          <li><a href="#products">Construction</a></li>
          <li><a href="#products">Mining</a></li>
        </ul>
      </div>
      <div>
        <h4>Company</h4>
        <ul>
          <li><a href="#brands">Brands</a></li>
          <li><a href="#services">Services</a></li>
          <li><a href="#faq">FAQ</a></li>
          <li><a href="#contact">Contact</a></li>
        </ul>
      </div>
      <div>
        <h4>Stay Updated</h4>
        <form class="news-form" method="post" action="index.php#footer">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="newsletter">
          <input type="email" name="email" placeholder="Your email address" aria-label="Email for newsletter" required>
          <button type="submit">Join</button>
        </form>
        <p class="footer-note">New machinery arrivals and offers. No spam.</p>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e($s['company_name']) ?>. All rights reserved.</span>
      <span><?= $s['trade_license'] ? 'Trade License No. ' . e($s['trade_license']) . ' · ' : '' ?>Addis Ababa, Ethiopia · <a href="admin.php">Staff Login</a></span>
    </div>
  </div>
</footer>

<a href="#top" class="to-top" id="toTop" aria-label="Back to top">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</a>

<?php if ($flash): ?>
<div class="toast <?= $flash['type'] === 'error' ? 'error' : '' ?>" id="toast" role="status">
  <span class="t-ic">
    <?php if ($flash['type'] === 'error'): ?>
    <svg viewBox="0 0 24 24" fill="none" stroke-width="3"><path d="M18 6L6 18M6 6l12 12"/></svg>
    <?php else: ?>
    <svg viewBox="0 0 24 24" fill="none" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
    <?php endif; ?>
  </span>
  <span><?= e($flash['message']) ?></span>
</div>
<?php endif; ?>

<script src="assets/site.js"></script>
</body>
</html>
