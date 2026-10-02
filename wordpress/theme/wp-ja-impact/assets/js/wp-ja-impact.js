/* wp-ja-impact: header and page behaviour. Each job works on elements the theme's parts and patterns
 * draw, and on nothing else of the page:
 *
 *   drawer — below the desktop breakpoint the header menu is a drawer from the left, as the source's
 *            off-canvas is: opened by `.jim-drawer-toggle`, closed by its × button (`.jim-drawer-close`),
 *            Escape or a click on the dimmed page; focus goes back to the toggle.
 *
 * Page-group scripts (assets/js/groups/*.js) add the behaviour of their own sections. */
;(() => {
  const t = window.wpJaImpact || {}
  const desktop = window.matchMedia ? window.matchMedia('(min-width: 992px)') : { matches: true }

  const toggle = document.querySelector('.jim-drawer-toggle')
  const drawer = toggle ? document.getElementById(toggle.getAttribute('aria-controls') || '') : null
  const isDrawerOpen = () => document.documentElement.classList.contains('jim-drawer-open')
  const setDrawer = (open, { restore = false } = {}) => {
    if (!toggle || !drawer) return
    document.documentElement.classList.toggle('jim-drawer-open', open)
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
    toggle.setAttribute('aria-label', open ? t.closeMenu || 'Close the menu' : t.openMenu || 'Open the menu')
    if (open) {
      // The drawer is still visibility: hidden in the first frame of its transition, where focus()
      // does nothing: focus its first control once it shows (and again when the slide ends).
      const first = drawer.querySelector('.jim-drawer-close, a, button')
      const focus = () => {
        if (first && isDrawerOpen() && document.activeElement !== first) first.focus()
      }
      requestAnimationFrame(() => requestAnimationFrame(focus))
      drawer.addEventListener('transitionend', focus, { once: true })
    } else if (restore) toggle.focus()
  }
  if (toggle && drawer) {
    let close = drawer.querySelector('.jim-drawer-close')
    if (!close) {
      close = document.createElement('button')
      close.type = 'button'
      close.className = 'jim-drawer-close'
      close.textContent = '×'
      const head = drawer.querySelector('.jim-drawer__head') || drawer
      head.append(close)
    }
    close.setAttribute('aria-label', t.closeMenu || 'Close the menu')
    close.addEventListener('click', () => setDrawer(false, { restore: true }))
    toggle.addEventListener('click', () => setDrawer(!isDrawerOpen(), { restore: isDrawerOpen() }))
    document.addEventListener('click', (event) => {
      if (!isDrawerOpen()) return
      if (drawer.contains(event.target) || toggle.contains(event.target)) return
      setDrawer(false, { restore: true })
    })
    if (desktop.addEventListener)
      desktop.addEventListener('change', () => desktop.matches && setDrawer(false))
  }
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isDrawerOpen()) setDrawer(false, { restore: true })
  })

  // The password field's toggle: show or hide what was typed (Joomla's `input-password-toggle`).
  document.addEventListener('click', (event) => {
    const button = event.target.closest && event.target.closest('.input-password-toggle')
    const input = button && button.parentElement.querySelector('input')
    if (!input) return
    const show = input.type === 'password'
    input.type = show ? 'text' : 'password'
    button.classList.toggle('is-shown', show)
  })
})()
