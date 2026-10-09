(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (window.lucide) lucide.createIcons();
  $('#year').textContent = new Date().getFullYear();

  /* ---------- Header: glass on scroll ---------- */
  const nav = $('#nav');
  const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 60);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- Mobile menu ---------- */
  const menu = $('#menu');
  const btn = $('#menu-btn');
  let lenis;
  const setMenu = (open) => {
    menu.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open);
    btn.textContent = open ? 'Close' : 'Menu';
    nav.style.zIndex = open ? 60 : '';
    document.body.classList.toggle('menu-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
    if (lenis) open ? lenis.stop() : lenis.start();
  };
  btn.addEventListener('click', () => setMenu(!menu.classList.contains('open')));
  $$('a', menu).forEach((a) => a.addEventListener('click', () => setMenu(false)));
  addEventListener('keydown', (e) => e.key === 'Escape' && setMenu(false));

  /* ---------- Projects filter ---------- */
  const fbtns = $$('[data-filter]');
  if (fbtns.length) {
    const cards = $$('[data-cat]');
    fbtns.forEach((b) =>
      b.addEventListener('click', () => {
        const f = b.dataset.filter;
        fbtns.forEach((x) => {
          x.classList.toggle('on', x === b);
          x.setAttribute('aria-pressed', x === b);
        });
        cards.forEach((c) => (c.hidden = !(f === 'all' || c.dataset.cat === f)));
        if (window.gsap) gsap.fromTo(cards.filter((c) => !c.hidden), { opacity: 0, y: 24 }, { opacity: 1, y: 0, stagger: 0.06, duration: 0.6, overwrite: true });
        if (window.ScrollTrigger) ScrollTrigger.refresh();
      })
    );
  }

  /* ---------- Contact form: WhatsApp / email (static site) ---------- */
  const form = $('#enquiry');
  if (form) {
    const text = () => {
      const d = new FormData(form);
      return ['Name: ' + d.get('name'), 'Phone: ' + d.get('phone'), 'Email: ' + (d.get('email') || '-'), 'Project type: ' + d.get('type'), 'Message: ' + (d.get('message') || '-')].join('\n');
    };
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      window.open('https://wa.me/919072509773?text=' + encodeURIComponent('Hello Monocoque Interiors,\n' + text()), '_blank', 'noopener');
    });
    $('#mail-btn').addEventListener('click', () => {
      if (!form.reportValidity()) return;
      location.href = 'mailto:info@monocoqueinteriors.com?subject=' + encodeURIComponent('Project enquiry') + '&body=' + encodeURIComponent(text());
    });
  }

  /* ---------- Reduced motion: no animation, show everything ---------- */
  if (reduce || !window.gsap) {
    $('#pre')?.remove();
    return;
  }

  gsap.registerPlugin(ScrollTrigger, SplitText);

  /* ---------- Lenis + ScrollTrigger ---------- */
  lenis = new Lenis({ lerp: 0.09, anchors: { offset: -120 } });
  lenis.on('scroll', ScrollTrigger.update);
  gsap.ticker.add((t) => lenis.raf(t * 1000));
  gsap.ticker.lagSmoothing(0);
  lenis.stop();

  document.fonts.ready.then(() => {
    const pre = $('#pre');
    const count = $('#pre-count');
    const progress = { v: 0 };

    /* initial hidden states (set by JS so the page works without it) */
    const preChars = pre ? SplitText.create('.pre-line', { type: 'chars' }).chars : [];
    const heroTitle = SplitText.create('#hero-title', { type: 'lines', mask: 'lines' });
    gsap.set('.hero-fade', { opacity: 0, y: 24 });
    gsap.set('.nav-in', { opacity: 0, y: -16 });
    if ($('.strip')) gsap.set('.strip .frame', { clipPath: 'inset(100% 0% 0% 0%)' });
    gsap.set(heroTitle.lines, { yPercent: 110 });
    if ($('[data-reveal]')) gsap.set('[data-reveal]', { opacity: 0, y: 40 });

    /* ---------- Preloader → hero intro ---------- */
    const tl = gsap.timeline({
      onComplete: () => {
        if (pre) pre.remove();
        lenis.start();
        ScrollTrigger.refresh();
      },
    });
    if (pre) {
      tl.from(preChars, { yPercent: 120, rotate: 6, stagger: 0.045, duration: 0.9, ease: 'power4.out' }, 0)
        .to(progress, { v: 100, duration: 2.1, ease: 'power2.inOut', onUpdate: () => (count.textContent = Math.round(progress.v)) }, 0)
        .to(preChars, { yPercent: -120, stagger: 0.025, duration: 0.6, ease: 'power3.in' }, '+=0.15')
        .to(pre, { yPercent: -100, duration: 1.1, ease: 'expo.inOut' }, '>-0.05');
    }
    tl.to(heroTitle.lines, { yPercent: 0, stagger: 0.14, duration: 1.2, ease: 'power4.out' }, pre ? '-=0.55' : 0.1);
    if ($('.strip')) tl.to('.strip .frame', { clipPath: 'inset(0% 0% 0% 0%)', stagger: 0.12, duration: 1.3, ease: 'expo.out' }, '<0.2');
    tl.to('.nav-in', { opacity: 1, y: 0, stagger: 0.07, duration: 0.8, ease: 'power3.out' }, '<0.1')
      .to('.hero-fade', { opacity: 1, y: 0, stagger: 0.12, duration: 0.9, ease: 'power3.out' }, '<0.2');

    /* ---------- Scroll animations ---------- */
    // Section headings: line mask reveal
    $$('[data-split]').forEach((el) => {
      SplitText.create(el, {
        type: 'lines',
        mask: 'lines',
        autoSplit: true,
        onSplit: (self) =>
          gsap.from(self.lines, {
            yPercent: 110,
            stagger: 0.12,
            duration: 1.1,
            ease: 'power4.out',
            scrollTrigger: { trigger: el, start: 'top 85%' },
          }),
      });
    });

    // Generic fade-up, batched so items stagger
    $$('[data-reveal]').length && ScrollTrigger.batch('[data-reveal]', {
      start: 'top 90%',
      once: true,
      onEnter: (items) => gsap.to(items, { opacity: 1, y: 0, stagger: 0.1, duration: 1, ease: 'power3.out' }),
    });

    // Image frames: scale-in parallax; .clip frames also get a scrubbed clip-path reveal
    $$('.frame').forEach((frame) => {
      const img = $('img', frame);
      if (img) gsap.fromTo(img, { yPercent: -7 }, {
        yPercent: 7,
        ease: 'none',
        scrollTrigger: { trigger: frame, start: 'top bottom', end: 'bottom top', scrub: true },
      });
      if (frame.classList.contains('clip')) {
        gsap.fromTo(frame, { clipPath: 'inset(14% 10% 14% 10%)' }, {
          clipPath: 'inset(0% 0% 0% 0%)',
          ease: 'none',
          scrollTrigger: { trigger: frame, start: 'top 95%', end: 'top 45%', scrub: true },
        });
      }
    });

    // Hero strip drifts up slightly while leaving the viewport
    if ($('.strip')) gsap.to('.strip', {
      yPercent: -8,
      ease: 'none',
      scrollTrigger: { trigger: '#hero', start: 'top top', end: 'bottom top', scrub: true },
    });

    // Services: highlight active category in sticky switch
    $$('[data-spy]').forEach((sec) =>
      ScrollTrigger.create({
        trigger: sec,
        start: 'top 40%',
        end: 'bottom 40%',
        onToggle: (s) => s.isActive && $$('.switch a').forEach((a) => a.classList.toggle('on', a.hash === '#' + sec.id)),
      })
    );

    // Stats count-up
    $$('[data-count]').forEach((el) => {
      const end = +el.dataset.count;
      const o = { v: 0 };
      el.textContent = '0';
      ScrollTrigger.create({
        trigger: el,
        start: 'top 92%',
        once: true,
        onEnter: () => gsap.to(o, { v: end, duration: 1.8, ease: 'power2.out', onUpdate: () => (el.textContent = Math.round(o.v)) }),
      });
    });
  });
})();
