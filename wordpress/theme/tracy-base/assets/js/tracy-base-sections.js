/* tracy-base: the two section behaviours the Joomla ACM blocks carry in script, restored as
 * progressive enhancement. Without this file every tab panel shows in order and the carousel is a
 * plain scroll container — readable, only longer.
 *
 *   tabs      — `.acm-tabs`: the label links become a WAI-ARIA tablist (roving tabindex, arrow
 *               keys, Home/End); only the selected panel shows, as on the source.
 *   carousel  — `.acm-carousel`: two step buttons beside the heading scroll the track by one
 *               slide; each is disabled at its end of the track. */
;(() => {
  for (const block of document.querySelectorAll('.acm-tabs')) {
    const links = [...block.querySelectorAll('.acm-tabs__tab a[href^="#"]')]
    const pairs = links
      .map((link) => ({ link, panel: document.getElementById(link.getAttribute('href').slice(1)) }))
      .filter((p) => p.panel && block.contains(p.panel))
    if (pairs.length < 2) continue
    const list = block.querySelector('.acm-tabs__list')
    if (list) list.setAttribute('role', 'tablist')
    pairs.forEach(({ link, panel }, i) => {
      if (!link.id) link.id = `${panel.id}-tab`
      link.setAttribute('role', 'tab')
      link.setAttribute('aria-controls', panel.id)
      link.parentElement?.setAttribute('role', 'presentation')
      panel.setAttribute('role', 'tabpanel')
      panel.setAttribute('aria-labelledby', link.id)
      panel.setAttribute('tabindex', '0')
      panel.dataset.index = String(i)
    })
    const select = (index, focus) => {
      pairs.forEach(({ link, panel }, i) => {
        const on = i === index
        link.setAttribute('aria-selected', on ? 'true' : 'false')
        link.setAttribute('tabindex', on ? '0' : '-1')
        link.parentElement?.classList.toggle('is-active', on)
        panel.hidden = !on
      })
      if (focus) pairs[index].link.focus()
    }
    pairs.forEach(({ link }, i) => {
      link.addEventListener('click', (event) => {
        event.preventDefault()
        select(i, false)
      })
      link.addEventListener('keydown', (event) => {
        const last = pairs.length - 1
        const next = {
          ArrowRight: i === last ? 0 : i + 1,
          ArrowLeft: i === 0 ? last : i - 1,
          Home: 0,
          End: last
        }[event.key]
        if (next === undefined) return
        event.preventDefault()
        select(next, true)
      })
    })
    select(0, false)
  }

  for (const block of document.querySelectorAll('.acm-carousel')) {
    const track = block.querySelector('.acm-carousel__track')
    const head = block.querySelector('.tracy-section__head')
    if (!track || !head || track.children.length < 2) continue
    const nav = document.createElement('div')
    nav.className = 'acm-carousel__nav'
    const make = (dir, label) => {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = `acm-carousel__step acm-carousel__step--${dir}`
      button.setAttribute('aria-label', label)
      button.addEventListener('click', () => {
        const slide = track.children[0]
        const gap = parseFloat(getComputedStyle(track).columnGap) || 0
        const step = (slide ? slide.getBoundingClientRect().width : track.clientWidth) + gap
        track.scrollBy({ left: dir === 'prev' ? -step : step, behavior: 'smooth' })
      })
      nav.append(button)
      return button
    }
    const prev = make('prev', 'Previous slide')
    const next = make('next', 'Next slide')
    const update = () => {
      prev.disabled = track.scrollLeft <= 1
      next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1
    }
    track.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)
    head.parentElement.insertBefore(nav, head.nextSibling)
    block.classList.add('has-steps')
    update()
  }
})()
