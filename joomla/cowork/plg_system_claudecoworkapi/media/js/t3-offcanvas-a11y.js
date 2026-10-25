/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 *
 * The keyboard for T3's off-canvas drawer (plugins/system/t3/base-bs3/js/off-canvas.js, the bytes
 * the plugin checked before printing this script). T3 opens and closes the drawer on clicks only: no
 * ESC, focus stays on ☰ behind the drawer, Tab walks the page underneath, and the × is hidden from
 * screen readers. This adds those around T3, never inside it: T3's functions live in a closure, so
 * ESC clicks the × T3 already listens to — or, on a drawer drawn without one, the ☰ that opened it,
 * which T3 closes an open drawer on too — and the open state is read from the classes T3 sets.
 * A ☰ or × a template draws as a link with no href, which Tab never reaches, is made a button the
 * keyboard reaches and works; one that already is keeps what it has.
 */
(function () {
  'use strict';

  var options = (window.Joomla && window.Joomla.getOptions && window.Joomla.getOptions('plg_system_claudecoworkapi.t3OffCanvas')) || {};
  var FOCUSABLE = 'a[href], area[href], button, input, select, textarea, iframe, summary, [tabindex], [contenteditable="true"]';

  // A control the keyboard cannot reach (a link with no href, a span) is given a stop in the Tab order
  // and the button's keys, Enter and Space, which click it as a pointer would. One that is reachable
  // already, or that names its own tabindex, is left exactly as it is.
  function operable(control) {
    if (control.matches(FOCUSABLE)) return;
    control.setAttribute('tabindex', '0');
    if (!control.hasAttribute('role')) control.setAttribute('role', 'button');
    control.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      e.preventDefault();
      if (!e.repeat) control.click();
    });
  }

  function start() {
    var toggles = [];
    var drawers = [];
    [].slice.call(document.querySelectorAll('.off-canvas-toggle[data-nav]')).forEach(function (toggle) {
      var drawer = document.querySelector(toggle.getAttribute('data-nav'));
      // Only the drawer T3 draws. It closes through its × or, without one, through this ☰.
      if (!drawer || !drawer.classList.contains('t3-off-canvas')) return;
      toggles.push(toggle);
      if (drawers.indexOf(drawer) === -1) drawers.push(drawer);
      if (!toggle.getAttribute('aria-label') && !toggle.textContent.trim()) toggle.setAttribute('aria-label', options.open || 'Toggle Navigation');
      if (drawer.id) toggle.setAttribute('aria-controls', drawer.id);
      toggle.setAttribute('aria-expanded', 'false');
    });
    if (!drawers.length || document.body.hasAttribute('data-cowork-offcanvas')) return;
    document.body.setAttribute('data-cowork-offcanvas', '');
    toggles.forEach(operable);
    drawers.forEach(function (drawer) {
      var close = drawer.querySelector('.close');
      // The × is a real button that shows and works; aria-hidden only kept it from being announced.
      if (close) {
        close.removeAttribute('aria-hidden');
        if (!close.getAttribute('aria-label')) close.setAttribute('aria-label', options.close || 'Close');
        operable(close);
      }
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

    // 🔒 NO WAY OUT, NO TRAP. ESC can only close a drawer through a control T3 listens to: its × or,
    // when the drawer has none, the ☰ that opens it (T3 closes an open drawer on that click). So every
    // key asks again whether the drawer is open and that control is still there, in the page: another
    // script may take it away at any time. Without one — or once a click on it threw or did not close
    // the drawer — the drawer is released to T3 alone: Tab is not held in it and ESC is not taken from
    // the page. A drawer whose × is there but does not close it is released, not clicked through ☰.
    var released = [];
    function current() {
      for (var i = 0; i < drawers.length; i++) if (drawers[i].classList.contains('off-canvas-current')) return drawers[i];
      return null;
    }
    function isOpen() { return document.body.classList.contains('off-canvas-open') && current() !== null; }
    function closer(drawer) {
      if (!drawer || !drawer.isConnected || released.indexOf(drawer) !== -1) return null;
      var close = drawer.querySelector('.close');
      if (close) return close;
      // No ×: the ☰ that opened it, or another one for this drawer, still in the page.
      if (opener && opener.isConnected && document.querySelector(opener.getAttribute('data-nav')) === drawer) return opener;
      for (var i = 0; i < toggles.length; i++)
        if (toggles[i].isConnected && document.querySelector(toggles[i].getAttribute('data-nav')) === drawer) return toggles[i];
      return null;
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

    // ESC clicks the × (or, without one, ☰), T3's own way out. T3 ignores it while its open or close animation holds its
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

    // Bootstrap 3, which T3 base-bs3 loads, takes every ESC pressed on a dropdown's toggle or in its
    // menu (preventDefault and stopPropagation) and acts on it only when that dropdown is open, to
    // close it. So where ESC lands is read before any script has it: in an open dropdown it is the
    // page's; in a closed one Bootstrap does nothing with it and it is still the drawer's.
    var escape = null;
    window.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' && e.key !== 'Esc') return;
      var target = e.target && e.target.closest ? e.target : null;
      var dropdown = target && target.closest('[data-toggle="dropdown"], .dropdown-menu');
      escape = { event: e, dropdown: dropdown ? (dropdown.closest('.open') ? 'open' : 'closed') : null };
    }, true);

    document.addEventListener('keydown', function (e) {
      var drawer = current();
      var open = held();
      // Pressed before T3 added the open class (it waits 50 ms): the drawer being opened, if it has a way out.
      var early = !isOpen() && Date.now() - openedAt < 400 && closer(opening) !== null;
      var dropdown = escape && escape.event === e ? escape.dropdown : null;
      var mine = drawer || opening;
      if (dropdown && !(mine && mine.contains(e.target))) dropdown = null;
      if ((e.key === 'Escape' || e.key === 'Esc') && dropdown !== 'open' && (!e.defaultPrevented || dropdown === 'closed') && (open || early)) {
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
