/* wp-ja-morgan: the header's off-canvas sidebar and the slideshow's titled tabs.
 *
 * The off-canvas sidebar is the source's "Sidebar" panel (T3 off-canvas, the Joomla Pages menu):
 * the red button opens it, the close button, Escape or a click outside closes it, and focus goes
 * back to the button. The main menu's own mobile drawer is WordPress's navigation overlay.
 *
 * The slideshow steps through the motion library's carousel; the source shows the slide titles as a
 * row of tabs under the pictures (Owl `dotsData`), so each tab presses the carousel's own dot of the
 * same index, and the tab of the dot the carousel marks current is marked current too.
 *
 * The main menu's dropdown opens on hover (WordPress's navigation submenu, CSS :hover/:focus-within);
 * Escape closes it while the pointer still rests on it, as the source's does, until the pointer or
 * the focus leaves the item.
 *
 * In the mobile menu a submenu stays folded until its caret is pressed, as in the source; the caret
 * toggles the item's `jm-sub-open` class and its own aria-expanded. */
;(() => {
  const strings = window.wpJaMorgan || {}
  const root = document.documentElement

  const offCanvas = () => {
    const panel = document.getElementById('jm-off-canvas')
    const toggle = document.querySelector('.off-canvas-toggle')
    if (!panel || !toggle) return
    const close = panel.querySelector('.close')
    const set = (open) => {
      root.classList.toggle('jm-off-canvas-open', open)
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
      panel.setAttribute('aria-hidden', open ? 'false' : 'true')
      if (open) (panel.querySelector('a, button') || panel).focus()
      else toggle.focus()
    }
    panel.setAttribute('aria-hidden', 'true')
    panel.setAttribute('tabindex', '-1')
    toggle.addEventListener('click', () => set(!root.classList.contains('jm-off-canvas-open')))
    if (close) close.addEventListener('click', () => set(false))
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && root.classList.contains('jm-off-canvas-open')) set(false)
    })
    document.addEventListener('click', (event) => {
      if (!root.classList.contains('jm-off-canvas-open')) return
      if (panel.contains(event.target) || toggle.contains(event.target)) return
      set(false)
    })
    if (strings.openMenu) toggle.setAttribute('title', strings.openMenu)
  }

  const slideTabs = () => {
    for (const show of document.querySelectorAll('.acm-slideshow')) {
      const tabs = Array.from(show.querySelectorAll('.jm-slide-tab'))
      if (!tabs.length) continue
      const dots = () => Array.from(show.querySelectorAll('.tracy-motion-dot'))
      tabs.forEach((tab, i) => {
        tab.setAttribute('role', 'button')
        tab.setAttribute('tabindex', '0')
        const press = () => {
          const dot = dots()[i]
          if (dot) dot.click()
        }
        tab.addEventListener('click', press)
        tab.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            press()
          }
        })
      })
      const paint = () => {
        const current = dots().findIndex((dot) => dot.getAttribute('aria-current') === 'true')
        tabs.forEach((tab, i) => {
          tab.classList.toggle('is-current', i === current)
          tab.setAttribute('aria-pressed', i === current ? 'true' : 'false')
        })
      }
      new MutationObserver(paint).observe(show, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-current']
      })
      paint()
    }
  }

  const escapeCloses = () => {
    const items = document.querySelectorAll('.t3-mainnav .wp-block-navigation-submenu')
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return
      for (const item of items) {
        if (!item.matches(':hover, :focus-within')) continue
        item.classList.add('jm-submenu-closed')
        const link = item.querySelector(':scope > a')
        if (item.contains(document.activeElement) && link) link.focus()
      }
    })
    for (const item of items) {
      const reopen = () => item.classList.remove('jm-submenu-closed')
      item.addEventListener('mouseleave', reopen)
      item.addEventListener('focusout', (event) => {
        if (!item.contains(event.relatedTarget)) reopen()
      })
    }
  }

  const foldedSubmenus = () => {
    document.addEventListener(
      'click',
      (event) => {
        const caret =
          event.target instanceof Element
            ? event.target.closest('.is-menu-open .wp-block-navigation-submenu__toggle')
            : null
        if (!caret) return
        event.stopImmediatePropagation()
        const open = caret.closest('.wp-block-navigation-submenu').classList.toggle('jm-sub-open')
        caret.setAttribute('aria-expanded', open ? 'true' : 'false')
      },
      true
    )
    // The overlay starts with every caret reported open by WordPress; the folded state is the truth.
    new MutationObserver(() => {
      for (const caret of document.querySelectorAll('.is-menu-open .wp-block-navigation-submenu__toggle')) {
        const open = caret.closest('.wp-block-navigation-submenu').classList.contains('jm-sub-open')
        if (caret.getAttribute('aria-expanded') !== String(open))
          caret.setAttribute('aria-expanded', String(open))
      }
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['aria-expanded'] })
  }

  const start = () => {
    foldedSubmenus()
    offCanvas()
    slideTabs()
    escapeCloses()
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start)
  else start()
})()
