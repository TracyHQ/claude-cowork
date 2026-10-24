// The T3 off-canvas drawer, driven from the keyboard in a real browser: what t3-offcanvas.sh asserts.
//
//   node t3-offcanvas.mjs <site URL> <playwright-core folder>
//
// Prints one JSON line per viewport (390, 768, 1440): what the page has and what each key did.
import { createRequire } from 'node:module';

const [url, core] = process.argv.slice(2);
const { chromium } = createRequire(import.meta.url)(core);
const pause = (ms) => new Promise((done) => setTimeout(done, ms));

// The focused element, named the way a reader of the result can find it.
const focused = (page) => page.evaluate(() => {
  const el = document.activeElement;
  if (!el || el === document.body) return 'body';
  const drawer = document.querySelector('.t3-off-canvas');
  const where = drawer && drawer.contains(el) ? 'drawer' : 'page';
  return where + ':' + el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '')
    + (el.getAttribute('href') ? '[' + el.getAttribute('href') + ']' : '');
});
const isOpen = (page) => page.evaluate(() => document.body.classList.contains('off-canvas-open'));

const browser = await chromium.launch();
try {
  for (const width of [390, 768, 1440]) {
    const context = await browser.newContext({ viewport: { width, height: 844 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(url, { waitUntil: 'load' });
    await pause(400);
    const out = { width };
    out.adapter = await page.evaluate(() => !!document.querySelector('script[src*="t3-offcanvas-a11y"]'));
    const toggle = page.locator('.off-canvas-toggle').first();
    out.toggleVisible = await toggle.isVisible();
    if (!out.toggleVisible) { out.errors = errors; console.log(JSON.stringify(out)); await context.close(); continue; }
    out.toggleName = await toggle.evaluate((el) => el.getAttribute('aria-label') || el.textContent.trim());
    // Where Tab goes from ☰ when nothing was opened: what Tab after a close has to match.
    await toggle.focus();
    await page.keyboard.press('Tab');
    out.afterToggle = await focused(page);

    // Open from the keyboard.
    await toggle.focus();
    await page.keyboard.press('Enter');
    await pause(400);
    out.opened = await isOpen(page);
    out.focusOnOpen = await focused(page);
    out.close = await page.evaluate(() => {
      const x = document.querySelector('.t3-off-canvas .close');
      if (!x) return null;
      const box = x.getBoundingClientRect();
      const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
      const style = getComputedStyle(x);
      return { ariaHidden: x.getAttribute('aria-hidden'), label: x.getAttribute('aria-label'), box: [Math.round(box.x), Math.round(box.y), Math.round(box.width), Math.round(box.height)],
        hit: hit === x || x.contains(hit), opacity: style.opacity, visibility: style.visibility, display: style.display };
    });
    // What a screen reader is given for the ×: a button named "Close", or nothing at all.
    out.closeAnnounced = await page.getByRole('button', { name: /close/i }).filter({ has: page.locator('xpath=self::*[contains(@class,"close")]') }).count();
    const tabs = [];
    for (let i = 0; i < 12; i++) { await page.keyboard.press('Tab'); tabs.push(await focused(page)); }
    out.tabReachesClose = tabs.some((f) => /^drawer:button\.close/.test(f));
    out.tabLeaves = tabs.filter((f) => !f.startsWith('drawer:')).length;
    const back = [];
    for (let i = 0; i < 12; i++) { await page.keyboard.press('Shift+Tab'); back.push(await focused(page)); }
    out.shiftTabLeaves = back.filter((f) => !f.startsWith('drawer:')).length;

    await page.keyboard.press('Escape');
    await pause(900);
    out.escCloses = !(await isOpen(page));
    out.focusAfterEsc = await focused(page);
    await page.keyboard.press('Tab');
    out.tabAfterClose = await focused(page);
    out.tabContinues = out.tabAfterClose === out.afterToggle;

    // ESC while T3 still holds its opening lock (200 ms after the open class lands).
    if (!(await isOpen(page))) {
      await toggle.focus();
      await page.keyboard.press('Enter');
      await pause(90);
      await page.keyboard.press('Escape');
      await pause(1200);
      out.escDuringOpenCloses = !(await isOpen(page));
      out.focusAfterEarlyEsc = await focused(page);
    }
    // The × and the page behind still close it, and focus comes back to ☰ from those too.
    if (!(await isOpen(page))) {
      await toggle.focus();
      await page.keyboard.press('Enter');
      await pause(400);
      await page.locator('.t3-off-canvas .close').click();
      await pause(900);
      out.closeClickCloses = !(await isOpen(page));
      out.focusAfterCloseClick = await focused(page);
      await toggle.focus();
      await page.keyboard.press('Enter');
      await pause(400);
      await page.mouse.click(width - 5, 400);
      await pause(900);
      out.pageClickCloses = !(await isOpen(page));
      out.focusAfterPageClick = await focused(page);
    }
    out.scrollLocked = await page.evaluate(() => document.documentElement.classList.contains('noscroll'));
    out.errors = errors;
    console.log(JSON.stringify(out));
    await context.close();
  }
} finally {
  await browser.close();
}
