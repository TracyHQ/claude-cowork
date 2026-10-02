/* wp-ja-essence: the behaviour the source's scripts give its chrome, kept small and dependency free.
 *
 *  1. the header drawer (the source's off-canvas menu): the hamburger opens it, the close button, Escape or a
 *     click outside closes it; its submenus drill down like the source's (the panel replaces the list, a
 *     "back" row leads one level up, three levels are reachable);
 *  2. the desktop submenus: a tap on the parent or on its chevron opens the panel (touch has no hover), a tap
 *     outside or Escape closes it, keyboard focus opens it;
 *  3. the lead slider: dots and previous/next buttons over a row that scrolls natively, so touch swipes work and
 *     every card is reachable by mouse, touch and keyboard;
 *  4. the gallery article: its pictures become a slider with arrows and thumbnails in the hero place.
 *
 * Every part is an enhancement: without this file the drawer stays closed, the menus open on hover and focus
 * through core, the slider is a row to scroll and the gallery pictures follow the text. */
;(() => {
  const html = document.documentElement
  const reduced = () => {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches
    } catch {
      return false
    }
  }

  /* ---------- 1. drawer ---------- */
  const drawerToggle = document.querySelector('.je-drawer-toggle')
  const drawer = document.getElementById('je-drawer')
  let resetDrill = () => {}
  const drawerOpen = () => html.classList.contains('je-drawer-open')
  const setDrawer = (open) => {
    html.classList.toggle('je-drawer-open', open)
    drawerToggle?.setAttribute('aria-expanded', open ? 'true' : 'false')
    if (!open) resetDrill()
  }
  if (drawerToggle && drawer) {
    drawerToggle.addEventListener('click', () => setDrawer(!drawerOpen()))
    drawer.querySelector('.je-drawer-close')?.addEventListener('click', () => setDrawer(false))
    document.addEventListener('keydown', (e) => e.key === 'Escape' && drawerOpen() && setDrawer(false))
    document.addEventListener('click', (e) => {
      if (drawerOpen() && !e.target.closest('#je-drawer, .je-drawer-toggle')) setDrawer(false)
    })
  }

  const labelOf = (li) =>
    li
      .querySelector(':scope > .wp-block-navigation-item__content .wp-block-navigation-item__label')
      ?.textContent?.trim() ?? ''
  const toggleOf = (li) => li.querySelector(':scope > .wp-block-navigation-submenu__toggle')
  const panelOf = (li) => li.querySelector(':scope > .wp-block-navigation__submenu-container')
  // A parent item with a submenu: core's markup gives it a toggle button and a panel.
  const isParent = (li) => Boolean(li && li.classList.contains('has-child') && toggleOf(li) && panelOf(li))

  const drillNav = drawer?.querySelector('.je-drawer-nav')
  const drillRoot = drillNav?.querySelector(':scope > .wp-block-navigation__container')
  if (drillNav && drillRoot) {
    const back = document.createElement('button')
    back.type = 'button'
    back.className = 'je-drill-back'
    back.hidden = true
    back.innerHTML =
      '<span class="je-drill-back__arrow" aria-hidden="true">&lsaquo;</span><span class="je-drill-back__label"></span>'
    drillNav.insertBefore(back, drillRoot)
    // The open path, outermost parent first; the last item's panel is the list on screen.
    let path = []

    const render = (focus) => {
      for (const el of drillNav.querySelectorAll('.je-d-path, .je-d-open, .je-d-cur'))
        el.classList.remove('je-d-path', 'je-d-open', 'je-d-cur')
      path.forEach((li, i) => li.classList.add('je-d-path', ...(i === path.length - 1 ? ['je-d-open'] : [])))
      const current = path.length ? panelOf(path[path.length - 1]) : drillRoot
      current.classList.add('je-d-cur')
      drillNav.classList.toggle('je-drilled', path.length > 0)
      for (const li of drillNav.querySelectorAll('li.has-child')) {
        toggleOf(li)?.setAttribute('aria-expanded', path.includes(li) ? 'true' : 'false')
      }
      back.hidden = path.length === 0
      back.querySelector('.je-drill-back__label').textContent = path.length
        ? labelOf(path[path.length - 1])
        : ''
      if (focus) (focus === 'back' ? back : focus).focus({ preventScroll: true })
    }
    const drill = (li) => {
      if (!isParent(li) || path.includes(li)) return
      // Only a direct child of the list on screen can be opened.
      const parentList = li.parentElement
      if (!parentList?.classList.contains('je-d-cur') && !(path.length === 0 && parentList === drillRoot))
        return
      path = [...path, li]
      render(panelOf(li).querySelector('a, button') ?? 'back')
    }
    const up = () => {
      if (!path.length) return
      const left = path[path.length - 1]
      path = path.slice(0, -1)
      render(toggleOf(left))
    }
    resetDrill = () => {
      path = []
      render(null)
    }
    render(null)

    back.addEventListener('click', up)
    // Capture phase: core's own handler on the toggle (a flyout) never sees the click.
    drillNav.addEventListener(
      'click',
      (e) => {
        const toggle = e.target.closest('.wp-block-navigation-submenu__toggle')
        if (toggle && drillNav.contains(toggle)) {
          e.preventDefault()
          e.stopPropagation()
          drill(toggle.closest('li'))
          return
        }
        // A parent without an address of its own (a heading such as "#Category"): the label opens it as well.
        const link = e.target.closest('a.wp-block-navigation-item__content')
        if (link && !link.hasAttribute('href') && drillNav.contains(link)) {
          e.preventDefault()
          e.stopPropagation()
          drill(link.closest('li'))
        }
      },
      true
    )
  }

  /* ---------- 2. desktop submenus ---------- */
  const nav = document.querySelector('.je-header nav.je-nav, nav.je-nav')
  const top = nav?.querySelector(':scope > .wp-block-navigation__container')
  if (nav && top) {
    const parents = () => [...top.children].filter(isParent)
    const setOpen = (li, open) => {
      li.classList.toggle('je-open', open)
      toggleOf(li)?.setAttribute('aria-expanded', open ? 'true' : 'false')
    }
    const closeAll = (except) => {
      for (const li of parents()) if (li !== except) setOpen(li, false)
    }
    const focusVisible = (el) => {
      try {
        return el.matches(':focus-visible')
      } catch {
        return true
      }
    }
    nav.addEventListener(
      'click',
      (e) => {
        const item = parents().find((li) => li.contains(e.target))
        if (!item) return
        const isOpen = item.classList.contains('je-open')
        const toggle = e.target.closest('.wp-block-navigation-submenu__toggle')
        if (toggle && toggle.parentElement === item) {
          e.preventDefault()
          e.stopPropagation()
          closeAll(item)
          setOpen(item, !isOpen)
          return
        }
        const link = e.target.closest('a.wp-block-navigation-item__content')
        if (!link || link.parentElement !== item) return
        // Touch has no hover: the first tap on a parent opens its panel; a parent without an address only opens.
        const touch = e.pointerType === 'touch' || e.pointerType === 'pen'
        const bare = !link.hasAttribute('href')
        if (bare || (touch && !isOpen)) {
          e.preventDefault()
          e.stopPropagation()
          closeAll(item)
          setOpen(item, bare ? !isOpen : true)
        }
      },
      true
    )
    // Keyboard focus opens the panel of the item it enters and closes the ones it leaves.
    nav.addEventListener('focusin', (e) => {
      const item = parents().find((li) => li.contains(e.target))
      if (!item) return closeAll()
      if (focusVisible(e.target)) {
        closeAll(item)
        setOpen(item, true)
      }
    })
    nav.addEventListener('focusout', (e) => {
      const item = parents().find((li) => li.contains(e.target))
      if (item && !item.contains(e.relatedTarget) && item.classList.contains('je-open') && e.relatedTarget)
        setOpen(item, false)
    })
    document.addEventListener('pointerdown', (e) => {
      if (!nav.contains(e.target)) closeAll()
    })
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return
      const open = parents().find((li) => li.classList.contains('je-open'))
      if (!open) return
      closeAll()
      if (open.contains(document.activeElement)) toggleOf(open)?.focus()
    })
  }

  /* ---------- 3. lead slider ---------- */
  const initSlider = (root) => {
    const track = root.querySelector('.je-lead__items')
    if (!track) return
    const items = [...track.children]
    if (items.length < 2) return
    root.setAttribute('role', 'region')
    root.setAttribute('aria-roledescription', 'carousel')
    if (!root.hasAttribute('aria-label')) root.setAttribute('aria-label', 'Featured articles')

    const controls = document.createElement('div')
    controls.className = 'je-slider__nav'
    controls.innerHTML =
      '<button type="button" class="je-slider__btn je-slider__prev" aria-label="Previous slides"><span aria-hidden="true">&lsaquo;</span></button>' +
      '<ul class="je-slider__dots"></ul>' +
      '<button type="button" class="je-slider__btn je-slider__next" aria-label="Next slides"><span aria-hidden="true">&rsaquo;</span></button>'
    root.appendChild(controls)
    const prev = controls.querySelector('.je-slider__prev')
    const next = controls.querySelector('.je-slider__next')
    const dots = controls.querySelector('.je-slider__dots')

    let visible = 1
    let starts = [0]
    const measure = () => {
      const first = items[0].getBoundingClientRect().width || 1
      const gap = parseFloat(getComputedStyle(track).columnGap) || 0
      visible = Math.max(1, Math.min(items.length, Math.round((track.clientWidth + gap) / (first + gap))))
      const pages = Math.ceil(items.length / visible)
      starts = Array.from({ length: pages }, (_, p) => Math.min(p * visible, items.length - visible))
    }
    const offsetOf = (i) => items[i].offsetLeft - items[0].offsetLeft
    const current = () => {
      let best = 0
      for (let p = 1; p < starts.length; p++) {
        if (
          Math.abs(offsetOf(starts[p]) - track.scrollLeft) <
          Math.abs(offsetOf(starts[best]) - track.scrollLeft)
        )
          best = p
      }
      return best
    }
    const goTo = (page) => {
      const p = Math.max(0, Math.min(starts.length - 1, page))
      track.scrollTo({ left: offsetOf(starts[p]), behavior: reduced() ? 'auto' : 'smooth' })
    }
    const paint = () => {
      const page = current()
      const total = starts.length
      controls.hidden = total < 2
      prev.disabled = page <= 0
      next.disabled = page >= total - 1
      for (const [p, dot] of [...dots.children].entries()) {
        const button = dot.firstElementChild
        if (p === page) button.setAttribute('aria-current', 'true')
        else button.removeAttribute('aria-current')
      }
      // Slides off screen are out of the tab order: focus never lands on a card the visitor cannot see.
      items.forEach((item, i) => {
        const shown = i >= starts[page] && i < starts[page] + visible
        item.toggleAttribute('inert', !shown)
      })
    }
    const build = () => {
      measure()
      dots.replaceChildren(
        ...starts.map((start, p) => {
          const li = document.createElement('li')
          const button = document.createElement('button')
          button.type = 'button'
          button.setAttribute(
            'aria-label',
            visible > 1
              ? `Show slides ${start + 1} to ${Math.min(items.length, start + visible)}`
              : `Show slide ${start + 1}`
          )
          button.addEventListener('click', () => goTo(p))
          li.appendChild(button)
          return li
        })
      )
      paint()
    }
    prev.addEventListener('click', () => goTo(current() - 1))
    next.addEventListener('click', () => goTo(current() + 1))
    let frame = 0
    track.addEventListener('scroll', () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(paint)
    })
    let width = track.clientWidth
    const observer = new ResizeObserver(() => {
      if (track.clientWidth === width) return
      width = track.clientWidth
      const before = current()
      build()
      track.scrollTo({ left: offsetOf(starts[Math.min(before, starts.length - 1)]), behavior: 'auto' })
    })
    observer.observe(track)
    build()
  }
  for (const root of document.querySelectorAll('.je-lead--slide')) initSlider(root)

  /* ---------- 4. gallery article ---------- */
  const initGallery = (card) => {
    const gallery = card.querySelector('.jes-gallery')
    const pictures = gallery ? [...gallery.querySelectorAll('img')] : []
    if (pictures.length < 2) return
    const slider = document.createElement('div')
    slider.className = 'je-gslider'
    slider.setAttribute('role', 'group')
    slider.setAttribute('aria-roledescription', 'carousel')
    slider.setAttribute('aria-label', 'Gallery')
    const viewport = document.createElement('div')
    viewport.className = 'je-gslider__viewport'
    const slides = pictures.map((img, i) => {
      const figure = document.createElement('figure')
      figure.className = 'je-gslider__slide'
      figure.setAttribute('role', 'group')
      figure.setAttribute('aria-roledescription', 'slide')
      figure.setAttribute('aria-label', `${i + 1} of ${pictures.length}`)
      const copy = img.cloneNode(true)
      copy.removeAttribute('class')
      copy.removeAttribute('loading')
      copy.alt = img.alt || ''
      figure.appendChild(copy)
      viewport.appendChild(figure)
      return figure
    })
    const arrow = (cls, label, glyph) => {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = `je-gslider__arrow ${cls}`
      button.setAttribute('aria-label', label)
      button.innerHTML = `<span aria-hidden="true">${glyph}</span>`
      return button
    }
    const prev = arrow('je-gslider__prev', 'Previous picture', '&lsaquo;')
    const next = arrow('je-gslider__next', 'Next picture', '&rsaquo;')
    const thumbs = document.createElement('ul')
    thumbs.className = 'je-gslider__thumbs'
    let index = 0
    const show = (i) => {
      index = (i + slides.length) % slides.length
      slides.forEach((slide, n) => {
        slide.hidden = n !== index
      })
      for (const [n, li] of [...thumbs.children].entries()) {
        const button = li.firstElementChild
        if (n === index) button.setAttribute('aria-current', 'true')
        else button.removeAttribute('aria-current')
      }
    }
    pictures.forEach((img, n) => {
      const li = document.createElement('li')
      const button = document.createElement('button')
      button.type = 'button'
      button.setAttribute('aria-label', `Show picture ${n + 1}`)
      const thumb = document.createElement('img')
      thumb.src = img.currentSrc || img.src
      thumb.alt = ''
      thumb.loading = 'lazy'
      button.appendChild(thumb)
      button.addEventListener('click', () => show(n))
      li.appendChild(button)
      thumbs.appendChild(li)
    })
    prev.addEventListener('click', () => show(index - 1))
    next.addEventListener('click', () => show(index + 1))
    slider.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') show(index - 1)
      else if (e.key === 'ArrowRight') show(index + 1)
      else return
      e.preventDefault()
    })
    viewport.append(prev, next, thumbs)
    slider.appendChild(viewport)
    const hero = card.querySelector('.je-page__hero')
    if (hero) hero.before(slider)
    else card.prepend(slider)
    card.classList.add('je-gslider-on')
    show(0)
  }
  for (const card of document.querySelectorAll('.je-articlecard')) initGallery(card)
})()
