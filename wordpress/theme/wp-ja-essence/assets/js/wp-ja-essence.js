/* wp-ja-essence: the header drawer (the source's off-canvas menu): the hamburger opens it, the close
 * button, Escape or a click outside closes it. */
;(() => {
  const toggle = document.querySelector('.je-drawer-toggle')
  const drawer = document.getElementById('je-drawer')
  if (!toggle || !drawer) return
  const set = (open) => {
    document.documentElement.classList.toggle('je-drawer-open', open)
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
  }
  toggle.addEventListener('click', () => set(!document.documentElement.classList.contains('je-drawer-open')))
  drawer.querySelector('.je-drawer-close')?.addEventListener('click', () => set(false))
  document.addEventListener('keydown', (e) => e.key === 'Escape' && set(false))
  document.addEventListener('click', (e) => {
    if (!document.documentElement.classList.contains('je-drawer-open')) return
    if (!e.target.closest('#je-drawer, .je-drawer-toggle')) set(false)
  })
})()
