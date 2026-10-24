// The off-canvas adapter on a drawer that loses its way out while it is open: a page that stands in
// for T3 (the classes it sets, the × it listens to), in a real browser, with no Joomla site.
//
//   node t3-offcanvas-dom.mjs <playwright-core folder> [adapter script]
//
// Checks that a drawer whose × is taken away, or whose × throws when clicked, is not trapped and
// keeps ESC for the page; and that the drawer with its × still works as before.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const [core, script = join(here, '../../plg_system_claudecoworkapi/media/js/t3-offcanvas-a11y.js')] = process.argv.slice(2);
const { chromium } = createRequire(import.meta.url)(core);
const adapter = readFileSync(script, 'utf8');
const pause = (ms) => new Promise((done) => setTimeout(done, ms));

// What T3's off-canvas.js does that the adapter reads: ☰ opens 50 ms after the click and stops it
// bubbling; the × closes. Nothing else.
const PAGE = `<!doctype html><html><body>
<a href="#before">before</a>
<button class="off-canvas-toggle" data-nav="#t3-off-canvas">☰</button>
<a href="#after">after</a>
<div id="t3-off-canvas" class="t3-off-canvas"><button class="close" aria-hidden="true">×</button><a href="#one">one</a><a href="#two">two</a></div>
<a href="#last">last</a>
<script>
  const drawer = document.getElementById('t3-off-canvas');
  document.querySelector('.off-canvas-toggle').addEventListener('click', (e) => {
    e.stopPropagation();
    setTimeout(() => { document.body.classList.add('off-canvas-open'); drawer.classList.add('off-canvas-current'); }, 50);
  });
  drawer.addEventListener('click', (e) => {
    if (e.target.closest('.close')) { document.body.classList.remove('off-canvas-open'); drawer.classList.remove('off-canvas-current'); }
  });
  window.escTaken = [];
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.escTaken.push(e.defaultPrevented); });
</script></body></html>`;

let failed = 0;
const expect = (label, got, want) => {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (!ok) failed++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}` + (ok ? '' : `\n       got : ${JSON.stringify(got)}\n       want: ${JSON.stringify(want)}`));
};

const browser = await chromium.launch();
try {
  // `spoil` runs in the page once the drawer is open: how it loses its way out.
  const run = async (spoil) => {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.setContent(PAGE);
    await page.addScriptTag({ content: adapter });
    await page.focus('.off-canvas-toggle');
    await page.keyboard.press('Enter');
    await pause(300);
    const opened = await page.evaluate(() => document.body.classList.contains('off-canvas-open'));
    if (spoil) await page.evaluate(spoil);
    await page.keyboard.press('Escape');
    await pause(2000);
    await page.keyboard.press('Escape');
    await pause(300);
    const open = await page.evaluate(() => document.body.classList.contains('off-canvas-open'));
    const outside = [];
    for (let i = 0; i < 8; i++) {
      await page.keyboard.press('Tab');
      outside.push(await page.evaluate(() => !document.getElementById('t3-off-canvas').contains(document.activeElement)));
    }
    const out = { opened, open, escTaken: await page.evaluate(() => window.escTaken), tabLeaves: outside.some(Boolean), errors };
    await page.close();
    return out;
  };

  const intact = await run(null);
  expect('with its ×: ESC closes it, taken once', [intact.opened, intact.open, intact.escTaken[0], intact.errors], [true, false, true, []]);
  const removed = await run(() => document.querySelector('.t3-off-canvas .close').remove());
  expect('× taken away while open: ESC stays the page\'s, Tab leaves, no error',
    [removed.opened, removed.open, removed.escTaken, removed.tabLeaves, removed.errors], [true, true, [false, false], true, []]);
  const throwing = await run(() => { document.querySelector('.t3-off-canvas .close').click = () => { throw new Error('the × broke'); }; });
  expect('× whose click throws: tried once, then ESC is the page\'s, Tab leaves, no error',
    [throwing.opened, throwing.open, throwing.escTaken, throwing.tabLeaves, throwing.errors], [true, true, [true, false], true, []]);
} finally {
  await browser.close();
}
console.log(failed ? `${failed} check(s) failed` : 'every check passed');
process.exit(failed ? 1 : 0);
