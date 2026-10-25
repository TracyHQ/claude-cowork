// The off-canvas adapter on a drawer that loses its way out while it is open: a page that stands in
// for T3 (the classes it sets, the × it listens to), in a real browser, with no Joomla site.
//
//   node t3-offcanvas-dom.mjs <playwright-core folder> [adapter script]
//
// Checks that the drawer closes on ESC through its ×, or through ☰ when it has none (JA Resume's block);
// that a drawer with no way out — × and ☰ taken away, or a × that throws — is not trapped and keeps ESC
// for the page; that a ☰ the keyboard cannot reach (JA Social II's link with no href) is made one; and that
// ESC on a menu link in the drawer, which Bootstrap 3's dropdown takes, still closes it.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const [core, script = join(here, '../../plg_system_claudecoworkapi/media/js/t3-offcanvas-a11y.js')] = process.argv.slice(2);
const { chromium } = createRequire(import.meta.url)(core);
const adapter = readFileSync(script, 'utf8');
const pause = (ms) => new Promise((done) => setTimeout(done, ms));

// What T3's off-canvas.js does that the adapter reads, with its timings: ☰ marks the drawer current,
// opens it 50 ms after the click and stops the click bubbling; ☰ again, the × or the page behind close
// it (the open class goes 100 ms later, the current class 700 ms later); each step holds a lock that
// ignores the next (200 ms after opening, 700 ms after closing). Nothing else.
const T3 = `<script>
  (() => {
    const body = document.body;
    const inner = document.querySelector('.t3-wrapper');
    const drawer = document.getElementById('t3-off-canvas');
    let loading = false;
    const hide = () => {
      if (loading) return;
      loading = true;
      setTimeout(() => body.classList.remove('off-canvas-open'), 100);
      setTimeout(() => { drawer.classList.remove('off-canvas-current'); loading = false; }, 700);
    };
    const show = () => {
      if (loading) return;
      loading = true;
      body.classList.add('off-canvas-open');
      setTimeout(() => { loading = false; }, 200);
    };
    document.querySelectorAll('.off-canvas-toggle').forEach((toggle) => toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (body.classList.contains('off-canvas-open')) { hide(); return; }
      drawer.classList.add('off-canvas-current');
      setTimeout(show, 50);
    }));
    drawer.addEventListener('click', (e) => { if (body.classList.contains('off-canvas-open') && e.target.closest('.close')) hide(); });
    inner.addEventListener('click', () => { if (body.classList.contains('off-canvas-open')) hide(); });
  })();
  window.escTaken = [];
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.escTaken.push(e.defaultPrevented); });
</script>`;
const markup = (toggle, header) => `<!doctype html><html><body>
<div id="t3-off-canvas" class="t3-off-canvas"><div class="t3-off-canvas-header">${header}</div><div class="t3-off-canvas-body"><a href="#one">one</a><a href="#two">two</a></div></div>
<div class="t3-wrapper"><a href="#before">before</a>${toggle}<a href="#after">after</a><a href="#last">last</a><div style="height: 3000px"></div></div>
${T3}</body></html>`;

// The ☰ and × T3's own base-bs3 block draws (JA Diner): buttons.
const PAGE = markup('<button class="off-canvas-toggle" data-nav="#t3-off-canvas">☰</button>', '<button class="close" aria-hidden="true">×</button>');
// JA Social II's block: ☰ and × are links with no href, which the keyboard cannot reach.
const SOCIAL = markup('<a aria-controls="off-canvas" aria-expanded="false" aria-label="Open Menu" class="btn btn-inverse off-canvas-toggle" type="button" data-pos="right" data-nav="#t3-off-canvas" data-effect="off-canvas-effect-4" title="open"><span class="bar-first"></span><span class="bar-mid"></span><span class="bar-last"></span></a>',
  '<h2 class="t3-off-canvas-header-title">Sidebar</h2><a type="button" class="close" data-dismiss="modal" aria-hidden="true" arial-expanded="true" aria-label="Close Menu" title="close">&times;</a>');
// JA Resume's block: a ☰ button, and a drawer with no × at all.
const RESUME = markup('<button class="off-canvas-toggle  col-md-1" type="button" data-pos="right" data-nav="#t3-off-canvas" data-effect="off-canvas-effect-4"><span class="patty"></span></button>',
  '<h2 class="t3-off-canvas-header-title">Sidebar</h2>');
// JA Resume's block with a menu in the drawer as T3 draws it, and Bootstrap 3's handling of ESC there
// (base-bs3/bootstrap/js/bootstrap.js, Dropdown.prototype.keydown): it takes every ESC pressed on a
// dropdown's toggle or in its menu, and closes the dropdown only when it is open.
const BOOTSTRAP = `<script>
  document.addEventListener('keydown', (e) => {
    const el = e.key === 'Escape' && e.target.closest('[data-toggle="dropdown"], .dropdown-menu');
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();
    const parent = el.parentElement;
    if (parent.classList.contains('open')) { parent.classList.remove('open'); parent.querySelector('[data-toggle="dropdown"]').focus(); }
  });
</script>`;
const MENU = RESUME.replace('<a href="#one">one</a>', '<ul class="nav"><li class="dropdown"><a href="#pages" class="dropdown-toggle" data-toggle="dropdown">Pages</a><ul class="dropdown-menu"><li><a href="#login">Login</a></li></ul></li></ul>')
  .replace('<script>', BOOTSTRAP + '<script>');

let failed = 0;
const expect = (label, got, want) => {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (!ok) failed++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}` + (ok ? '' : `\n       got : ${JSON.stringify(got)}\n       want: ${JSON.stringify(want)}`));
};

const browser = await chromium.launch();
const where = () => {
  const el = document.activeElement;
  if (!el || el === document.body) return 'body';
  if (el.classList.contains('off-canvas-toggle')) return 'toggle';
  return (document.getElementById('t3-off-canvas').contains(el) ? 'drawer:' : 'page:') + (el.getAttribute('href') || el.className || el.tagName.toLowerCase());
};
const isOpen = (page) => page.evaluate(() => document.body.classList.contains('off-canvas-open'));
const fresh = async (html) => {
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.setContent(html);
  await page.addScriptTag({ content: adapter });
  return { page, errors };
};
try {
  // `spoil` runs in the page once the drawer is open: how it loses its way out.
  const run = async (spoil, html = PAGE) => {
    const { page, errors } = await fresh(html);
    await page.focus('.off-canvas-toggle');
    await page.keyboard.press('Enter');
    await pause(300);
    const opened = await isOpen(page);
    if (spoil) await page.evaluate(spoil);
    await page.keyboard.press('Escape');
    await pause(2000);
    const focusAfter = await page.evaluate(where);
    await page.keyboard.press('Escape');
    await pause(300);
    const open = await isOpen(page);
    const outside = [];
    for (let i = 0; i < 8; i++) {
      await page.keyboard.press('Tab');
      outside.push(await page.evaluate(() => !document.getElementById('t3-off-canvas').contains(document.activeElement)));
    }
    const out = { opened, open, focusAfter, escTaken: await page.evaluate(() => window.escTaken), tabLeaves: outside.some(Boolean), errors };
    await page.close();
    return out;
  };

  const intact = await run(null);
  expect('with its ×: ESC closes it, taken once, focus back on ☰', [intact.opened, intact.open, intact.escTaken[0], intact.focusAfter, intact.errors], [true, false, true, 'toggle', []]);
  const removed = await run(() => document.querySelector('.t3-off-canvas .close').remove());
  expect('× taken away while open: ESC closes it through ☰, T3\'s other way out, focus back on ☰',
    [removed.opened, removed.open, removed.escTaken[0], removed.focusAfter, removed.errors], [true, false, true, 'toggle', []]);
  const throwing = await run(() => { document.querySelector('.t3-off-canvas .close').click = () => { throw new Error('the × broke'); }; });
  expect('× whose click throws: tried once, then ESC is the page\'s, Tab leaves, no error',
    [throwing.opened, throwing.open, throwing.escTaken, throwing.tabLeaves, throwing.errors], [true, true, [true, false], true, []]);
  const stranded = await run(() => { document.querySelector('.t3-off-canvas .close').remove(); document.querySelector('.off-canvas-toggle').remove(); });
  expect('× and ☰ both taken away while open: no way out, so ESC stays the page\'s, Tab leaves, no error',
    [stranded.opened, stranded.open, stranded.escTaken, stranded.tabLeaves, stranded.errors], [true, true, [false, false], true, []]);

  // A ☰ that already works keeps what it had: nothing added to it.
  {
    const { page } = await fresh(PAGE);
    expect('a ☰ button is left as it is: no tabindex, no role',
      await page.evaluate(() => { const t = document.querySelector('.off-canvas-toggle'); return [t.hasAttribute('tabindex'), t.hasAttribute('role')]; }), [false, false]);
    await page.close();
  }

  // JA Social II: a ☰ the keyboard cannot reach is made one it can, and opens on Enter and on Space.
  {
    const { page, errors } = await fresh(SOCIAL);
    await page.focus('a[href="#before"]');
    await page.keyboard.press('Tab');
    const reached = await page.evaluate(where);
    const named = await page.evaluate(() => { const t = document.querySelector('.off-canvas-toggle'); return [t.getAttribute('tabindex'), t.getAttribute('role'), t.getAttribute('aria-label')]; });
    await page.keyboard.press('Enter');
    await pause(300);
    const openedByEnter = await isOpen(page);
    const focusOnOpen = await page.evaluate(where);
    const tabs = [];
    for (let i = 0; i < 4; i++) { await page.keyboard.press('Tab'); tabs.push(await page.evaluate(where)); }
    await page.keyboard.press('Escape');
    await pause(1000);
    const closedByEsc = !(await isOpen(page));
    const focusOnClose = await page.evaluate(where);
    await page.keyboard.press(' ');
    await pause(300);
    const openedBySpace = await isOpen(page);
    const scrolled = await page.evaluate(() => window.scrollY);
    await page.keyboard.press('Escape');
    await pause(1000);
    expect('Social II: Tab reaches the ☰, a button named as the template names it', [reached, named], ['toggle', ['0', 'button', 'Open Menu']]);
    expect('Social II: Enter opens it and puts focus in the drawer', [openedByEnter, focusOnOpen.startsWith('drawer:')], [true, true]);
    expect('Social II: Tab goes round the drawer, the × included', tabs.every((t) => t.startsWith('drawer:')) && tabs.some((t) => t.includes('close')), true);
    expect('Social II: ESC closes it, focus back on ☰', [closedByEsc, focusOnClose], [true, 'toggle']);
    expect('Social II: Space opens it too, without scrolling the page', [openedBySpace, scrolled], [true, 0]);
    expect('Social II: and closes, no error', [await isOpen(page), errors], [false, []]);
    await page.close();
  }

  // The script printed twice (two layouts each asking for it) works once: one Enter, one click.
  {
    const { page, errors } = await fresh(SOCIAL);
    await page.addScriptTag({ content: adapter });
    await page.evaluate(() => { window.clicks = 0; document.querySelector('.off-canvas-toggle').addEventListener('click', () => window.clicks++, true); });
    await page.focus('.off-canvas-toggle');
    await page.keyboard.press('Enter');
    await pause(300);
    expect('Social II, script printed twice: Enter clicks ☰ once, and it opens', [await page.evaluate(() => window.clicks), await isOpen(page), errors], [1, true, []]);
    await page.close();
  }

  // JA Resume: a drawer with no ×. ESC closes it through ☰, and focus comes back to it.
  {
    const resume = await run(null, RESUME);
    expect('Resume: Enter opens it, ESC closes it, taken once, focus back on ☰',
      [resume.opened, resume.open, resume.escTaken[0], resume.focusAfter, resume.errors], [true, false, true, 'toggle', []]);
    const { page } = await fresh(RESUME);
    await page.focus('.off-canvas-toggle');
    await page.keyboard.press('Enter');
    await pause(300);
    const focusOnOpen = await page.evaluate(where);
    const expanded = await page.evaluate(() => document.querySelector('.off-canvas-toggle').getAttribute('aria-expanded'));
    expect('Resume: focus goes into the drawer, ☰ says it is expanded', [focusOnOpen.startsWith('drawer:'), expanded], [true, 'true']);
    await page.close();
    // ESC right after Enter, before T3 has marked it open: still closes it, and does not open it again.
    const early = await fresh(RESUME);
    await early.page.focus('.off-canvas-toggle');
    await early.page.keyboard.press('Enter');
    await early.page.keyboard.press('Escape');
    await pause(2000);
    expect('Resume: ESC pressed at once after Enter closes it and leaves it closed',
      [await isOpen(early.page), await early.page.evaluate(where), await early.page.evaluate(() => window.escTaken), early.errors], [false, 'toggle', [true], []]);
    await early.page.close();
  }

  // A menu in the drawer: Bootstrap's dropdown has every ESC pressed there, but uses it only to close
  // a dropdown that is open. Otherwise ESC still closes the drawer.
  for (const open of [false, true]) {
    const { page, errors } = await fresh(open ? MENU.replace('class="dropdown"', 'class="dropdown open"') : MENU);
    await page.focus('.off-canvas-toggle');
    await page.keyboard.press('Enter');
    await pause(300);
    await page.focus('a[href="#login"]');
    await page.keyboard.press('Escape');
    await pause(1500);
    const got = [await isOpen(page), await page.evaluate(where), errors];
    if (open) expect('a menu link in an open dropdown: ESC is Bootstrap\'s, it closes the dropdown, the drawer stays', got, [true, 'drawer:#pages', []]);
    else expect('a menu link in a closed dropdown: ESC still closes the drawer, focus back on ☰', got, [false, 'toggle', []]);
    await page.close();
  }
} finally {
  await browser.close();
}
console.log(failed ? `${failed} check(s) failed` : 'every check passed');
process.exit(failed ? 1 : 0);
