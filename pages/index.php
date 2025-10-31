<?php
session_start();
include __DIR__ . '/../func/db.php';
include __DIR__ . '/../func/header.php';

// Notification counter
$notif_count = 0;
if (isset($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $notif_query = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_query->bind_param("i", $uid);
    $notif_query->execute();
    $notif_query->bind_result($notif_count);
    $notif_query->fetch();
    $notif_query->close();
}

// Dynamic profile link
$profile_link = '../login.php'; // default if not logged in
if (isset($_SESSION['role'])) {
  $profile_link = ($_SESSION['role'] === 'admin') ? '../pages/admin.php' : '../profile.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PharmaSys</title>
  <link rel="stylesheet" href="../assets/css/design.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    /* photo background slideshow */
    .hero-slideshow { position: relative; width:100%; height:100vh; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .hero-slideshow .slide { position:absolute; inset:0; background-size:cover; background-position:center; opacity:0; transition: opacity 1s ease-in-out; }
    .hero-slideshow .dim { position:absolute; inset:0; background:rgba(0,0,0,0.45); pointer-events:none; }
    .hero-slideshow .title { position:relative; z-index:3; color:#fff; text-shadow:0 2px 8px rgba(0,0,0,0.6); }
    /* simple keyframe fade sequence */
    @keyframes slideFade { 0%{opacity:0} 8%{opacity:1} 33%{opacity:1} 41%{opacity:0} 100%{opacity:0} }
    .hero-slideshow .slide.s1 { background-image: url('../assets/webasset/1.jpg'); animation: slideFade 12s infinite; animation-delay: 0s; }
    .hero-slideshow .slide.s2 { background-image: url('../assets/webasset/2.jpg'); animation: slideFade 12s infinite; animation-delay: 4s; }
    .hero-slideshow .slide.s3 { background-image: url('../assets/webasset/3.webp'); animation: slideFade 12s infinite; animation-delay: 8s; }
    /* make sure the visible state shows opacity:1 via animation */
    .hero-slideshow .slide { opacity:0; }
    /* Smooth opacity transitions for the whole hero when scrolling */
    .hero-slideshow { will-change: opacity; transition: opacity 0.2s linear; }
  /* Typed heading style */
  .title { font-weight:700; letter-spacing: -1px; }
  .title .typed { font-size: clamp(1.9rem, 5.5vw, 4.6rem); font-weight: 800; color: #fff; }
  .caret { display:inline-block; width:3px; background:#fff; opacity:1; animation: twink 1s steps(1) infinite; margin-left:8px; height:1.1em; vertical-align:middle; }
  @media (max-width:576px){ .title .typed { font-size: clamp(1.6rem, 8vw, 2.6rem); } }
  @keyframes twink { 50% { opacity: 0 } }
  /* tagline text */
  .hero-tagline { margin-top:.6rem; color: rgba(255,255,255,0.95); font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: clamp(1.0rem, 1.6vw, 1.4rem); font-weight:600; text-shadow:0 1px 2px rgba(0,0,0,0.6); opacity:0; transform:translateY(6px); transition: opacity 0.5s ease, transform 0.5s ease; }
  .hero-tagline.visible { opacity:1; transform:translateY(0); }
  </style>
</head>
<body>

  <main id="home" class="text-center py-5">
    <div class="hero-slideshow mb-4">
      <div class="slide s1"></div>
      <div class="slide s2"></div>
      <div class="slide s3"></div>
      <div class="dim"></div>
      <div style="text-align:center; z-index:4; position:relative;">
        <h1 class="title display-1" style="margin:0;">
          <span id="type-text" aria-live="polite" class="typed" role="text"></span>
          <span class="caret" aria-hidden="true"></span>
        </h1>
        <p id="hero-tagline" class="hero-tagline">Your leading partner in pharmaceutical needs.</p>
      </div>
    </div>
  </main>
  <script>
    // Fade as the user scrolls down.
    (function(){
      var hero = document.querySelector('.hero-slideshow');
      if (!hero) return;
      var ticking = false;
      function onScroll(){
        if (!ticking) {
          window.requestAnimationFrame(function(){
            var y = window.scrollY || window.pageYOffset;
            var vh = window.innerHeight || document.documentElement.clientHeight;
            // scroll fade effect
            var fadeRange = vh * 0.6;
            var opacity = 1;
            if (y > 0) opacity = Math.max(0, 1 - (y / fadeRange));
            hero.style.opacity = opacity;
            ticking = false;
          });
          ticking = true;
        }
      }
      // initial call
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll);
    })();
  </script>
    <script>
      // typewriter loop
      (function(){
        const el = document.getElementById('type-text');
        if (!el) return;
  const phrases = ["Medicine?","Prescriptions?","PharmaSys®"];
        const typeSpeed = 80; // ms per char
        const pauseAfter = 1200; // ms before deleting
        const pauseBetween = 300; // ms before typing next
        let pIndex = 0;
        let forward = true;
        let charIndex = 0;
  let taglineShown = false;

        function type() {
          const current = phrases[pIndex];
          if (forward) {
            charIndex++;
            el.textContent = current.slice(0, charIndex);
              if (charIndex >= current.length) {
                // If we finished typing the brand, show the tagline and stop the loop.
                if (current === 'PharmaSys®') {
                  const tag = document.getElementById('hero-tagline');
                  if (tag) {
                    tag.classList.add('visible');
                    taglineShown = true;
                  }
                  // leave the typed brand visible and do not continue the loop
                  return;
                }
                forward = false;
                setTimeout(type, pauseAfter);
                return;
              }
          } else {
            // hide tagline while deleting or typing other phrases, but only if it hasn't been permanently shown
            const tag = document.getElementById('hero-tagline');
            if (tag && !taglineShown) tag.classList.remove('visible');
            charIndex--;
            el.textContent = current.slice(0, charIndex);
            if (charIndex <= 0) {
              forward = true;
              pIndex = (pIndex + 1) % phrases.length;
              setTimeout(type, pauseBetween);
              return;
            }
          }
          setTimeout(type, typeSpeed);
        }
        // start after small delay
        setTimeout(type, 600);
      })();
    </script>

  <!-- Medicine Section moved to pages/medicine_search.php -->

  <!-- About Section -->
  <section id="about" class="about-section container my-5 py-4">
    <div class="row align-items-center">
      <div class="col-md-6">
        <h2 class="text-primary mb-3">About PharmaSys</h2>
        <p>
          PharmaSys® stands as a trusted partner in advancing modern healthcare through innovative and high-quality pharmaceutical solutions. With a steadfast commitment to excellence, we provide products and services designed to meet the evolving demands of patients, professionals, and institutions alike. Our expertise lies in merging scientific innovation with precision manufacturing to ensure safety, efficacy, and reliability. At PharmaSys®, we redefine pharmaceutical excellence—empowering healthier lives through trusted, forward-thinking solutions.
        </p>
      </div>
      <div class="col-md-6 text-center">
        <img src="../assets/image/utot.webp" alt="About PharmaSys" class="img-fluid rounded shadow-sm" style="max-height: 300px;">
      </div>
    </div>
  </section>

<!--
  Contact Section
  <section id="contact" class="contact-section container my-5 py-4">
    <div class="row">
      <div class="col-md-6">
        <h2 class="text-primary mb-3">Contact Us</h2>
        <p>Have questions, feedback, or need assistance? Reach out to our team — we're here to help you.</p>
      </div>
      <div class="col-md-6">
        <form>
          <div class="mb-3">
            <label for="name" class="form-label">Your Name</label>
            <input type="text" class="form-control" id="name" placeholder="Enter your name" required>
          </div>
          <div class="mb-3">
            <label for="email" class="form-label">Your Email</label>
            <input type="email" class="form-control" id="email" placeholder="Enter your email" required>
          </div>
          <div class="mb-3">
            <label for="message" class="form-label">Message</label>
            <textarea class="form-control" id="message" rows="4" placeholder="Type your message here..." required style="resize: none; height: 100px;"></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Send Message</button>
        </form>
      </div>
    </div>
  </section>
    -->
  <!-- Footer -->
  <?php include __DIR__ . '/../func/footer.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>