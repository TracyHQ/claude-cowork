/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 *
 * The keyboard for T3's off-canvas drawer (plugins/system/t3/base-bs3/js/off-canvas.js, the bytes
 * the plugin checked before printing this script). T3 opens and closes the drawer on clicks only: no
 * ESC, focus stays on ☰ behind the drawer, Tab walks the page underneath, and the × is hidden from
 * screen readers. This adds those around T3, never inside it: T3's functions live in a closure, so
 * ESC clicks the × T3 already listens to, and the open state is read from the classes T3 sets.
 */
(function () {
  'use strict';

  var options = (window.Joomla && window.Joomla.getOptions && window.Joomla.getOptions('plg_system_claudecoworkapi.t3OffCanvas')) || {};
  var FOCUSABLE = 'a[href], area[href], button, input, select, textarea, iframe, summary, [tabindex], [contenteditable="true"]';

  function start() {
    var toggles = [];
    var drawers = [];
    [].slice.call(document.querySelectorAll('.off-canvas-toggle[data-nav]')).forEach(function (toggle) {
      var drawer = document.querySelector(toggle.getAttribute('data-nav'));
      // Only the drawer T3 draws, with the × T3 closes it by: ESC has no other way to close it, so a
      // drawer without one is left as T3 leaves it — no trap, no ESC taken from the page.
      if (!drawer || !drawer.classList.contains('t3-off-canvas') || !drawer.querySelector('.close')) return;
      toggles.push(toggle);
      if (drawers.indexOf(drawer) === -1) drawers.push(drawer);
      if (!toggle.getAttribute('aria-label') && !toggle.textContent.trim()) toggle.setAttribute('aria-label', options.open || 'Toggle Navigation');
      if (drawer.id) toggle.setAttribute('aria-controls', drawer.id);
      toggle.setAttribute('aria-expanded', 'false');
    });
    if (!drawers.length || document.body.hasAttribute('data-cowork-offcanvas')) return;
    document.body.setAttribute('data-cowork-offcanvas', '');
    drawers.forEach(function (drawer) {
      var close = drawer.querySelector('.close');
      // The × is a real button that shows and works; aria-hidden only kept it from being announced.
      close.removeAttribute('aria-hidden');
      if (!close.getAttribute('aria-label')) close.setAttribute('aria-label', options.close || 'Close');
      if (drawer.tabIndex < 0 && !drawer.hasAttribute('tabindex')) drawer.setAttribute('tabindex', '-1');
    });

    // The ☰ that opened it. T3 stops the click from bubbling, so it is noted on the way down.
    var opener = null;
    var opening = null;
    var openedAt = 0;
    document.addEventListener('click', function (e) {
      var toggle = e.target && e.target.closest && e.target.closest('.off-canvas-toggle[data-nav]');
      if (toggle && toggles.indexOf(toggle) !== -1 && !isOpen()) { opener = toggle; opening = document.querySelector(toggle.getAttribute('data-nav')); openedAt = Date.now(); }
    }, true);

    // 🔒 NO WAY OUT, NO TRAP. ESC can only close a drawer through its ×, so every key asks again whether
    // the drawer is open and its × is still there, in the page: another script may take it away at any
    // time. Without one — or once a click on it threw or did not close the drawer — the drawer is
    // released to T3 alone: Tab is not held in it and ESC is not taken from the page.
    var released = [];
    function current() {
      for (var i = 0; i < drawers.length; i++) if (drawers[i].classList.contains('off-canvas-current')) return drawers[i];
      return null;
    }
    function isOpen() { return document.body.classList.contains('off-canvas-open') && current() !== null; }
    function closer(drawer) {
      if (!drawer || !drawer.isConnected || released.indexOf(drawer) !== -1) return null;
      var close = drawer.querySelector('.close');
      return close && close.isConnected ? close : null;
    }
    function release(drawer) { if (drawer && released.indexOf(drawer) === -1) released.push(drawer); }
    function held() { return isOpen() && closer(current()) !== null; }
    function visible(el) {
      if (el.disabled || el.getAttribute('tabindex') === '-1' || el.closest('[inert]')) return false;
      var style = window.getComputedStyle(el);
      return style.visibility !== 'hidden' && style.display !== 'none' && el.getClientRects().length > 0;
    }
    function focusables(drawer) { return [].slice.call(drawer.querySelectorAll(FOCUSABLE)).filter(visible); }
    function into(drawer) {
      var list = focusables(drawer);
      (list[0] || drawer).focus({ preventScroll: true });
    }

    // Open and closed, as T3 marks them on <body>.
    var wasOpen = false;
    var closing = null;
    new MutationObserver(function () {
      var open = isOpen();
      if (open === wasOpen) return;
      wasOpen = open;
      toggles.forEach(function (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); });
      if (open) {
        if (held()) into(current());
        return;
      }
      if (closing) { clearTimeout(closing); closing = null; }
      // Every way it closes (ESC, ×, the page behind, ☰) gives focus back to the ☰ that opened it,
      // unless the click that closed it put focus somewhere on purpose.
      var active = document.activeElement;
      if (opener && (!active || active === document.body || drawers.some(function (d) { return d.contains(active); }))) opener.focus({ preventScroll: true });
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });

    // ESC clicks the ×, T3's own way out. T3 ignores it while its open or close animation holds its
    // lock (200 ms after opening), so it is tried again until the drawer is shut, a bounded number of times.
    function shut(tries) {
      closing = null;
      var drawer = current();
      if (!drawer || !document.body.classList.contains('off-canvas-open')) {
        // Pressed before T3 added the open class (it waits 50 ms): try once it is there.
        if (tries > 0 && Date.now() - openedAt < 400) closing = setTimeout(function () { shut(tries - 1); }, 60);
        return;
      }
      var close = closer(drawer);
      if (!close) { release(drawer); return; }
      try { close.click(); } catch (error) { release(drawer); return; }
      closing = setTimeout(function () {
        closing = null;
        if (!isOpen()) return;
        if (tries > 0) shut(tries - 1);
        else release(drawer);
      }, 150);
    }

    document.addEventListener('keydown', function (e) {
      var drawer = current();
      var open = held();
      // Pressed before T3 added the open class (it waits 50 ms): the drawer being opened, if it has its ×.
      var early = !isOpen() && Date.now() - openedAt < 400 && closer(opening) !== null;
      if ((e.key === 'Escape' || e.key === 'Esc') && !e.defaultPrevented && (open || early)) {
        // A dialog of its own inside the drawer keeps its ESC.
        var dialog = e.target && e.target.closest ? e.target.closest('[role="dialog"], dialog, .modal') : null;
        if (dialog && drawer && drawer.contains(dialog)) return;
        e.preventDefault();
        if (!closing) shut(8);
        return;
      }
      if (e.key !== 'Tab' || !open || !drawer) return;
      // While the drawer is open, Tab and Shift-Tab go round the drawer's own controls.
      var list = focusables(drawer);
      if (!list.length) { e.preventDefault(); drawer.focus({ preventScroll: true }); return; }
      var first = list[0];
      var last = list[list.length - 1];
      var active = document.activeElement;
      var inside = drawer.contains(active);
      if (e.shiftKey && (!inside || active === first || active === drawer)) { e.preventDefault(); last.focus({ preventScroll: true }); }
      else if (!e.shiftKey && (!inside || active === last)) { e.preventDefault(); first.focus({ preventScroll: true }); }
    });
    // Focus that lands outside an open drawer some other way is brought back to it.
    document.addEventListener('focusin', function (e) {
      var drawer = current();
      if (held() && drawer && !drawer.contains(e.target)) into(drawer);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
