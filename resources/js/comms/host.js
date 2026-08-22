import devices from '../media/devices.js'
import mesh from './mesh.js'

/**
 * The comms widget, and everything it is holding.
 *
 * This runs in the host document, and that is the whole point of it. A
 * navigation replaces the page's body and reloads every iframe in it — an
 * iframe reloads whenever it is moved in the DOM, which is what preserving one
 * across a swap amounts to — so nothing that must survive a navigation can live
 * inside a frame. The top-level document is the one context that does survive.
 *
 * So the camera, the microphone and the peer connections live here, and the
 * frames are views over them:
 *
 *   badge   the circle in the corner
 *   panel   what opens when it is pressed — chat, parties, the switches
 *   stage   the row of faces, drawn into by this document
 *
 * The frames are still frames because that is what keeps a venue's screens and
 * this widget out of each other's stylesheets and stacking contexts. They just
 * no longer own anything: the stage is a shell this writes into, which it may
 * do because they are the same origin. Streams cannot be posted between
 * documents — a MediaStream is not structured-cloneable — but they can be
 * handed straight to an element in a document you can reach.
 */
!function () {
    const badge = document.getElementById('streetmesh-badge')
    const panel = document.getElementById('streetmesh-panel')
    const stage = document.getElementById('streetmesh-stage')

    const config = window.streetmeshComms

    if (!config || !badge || !panel || !stage) {
        return
    }

    /*
     * Module scripts run once per URL however many times the page is swapped,
     * so this is the first and only pass. What does re-run is the small inline
     * script that writes `window.streetmeshComms` — which is how a navigation
     * tells us the party may have changed. See `livewire:navigated` below.
     */
    if (window.__streetmeshCommsWired) {
        return
    }

    window.__streetmeshCommsWired = true

    let open = false
    let speaking = false
    let showing = false

    /**
     * What was being shared before this page existed.
     *
     * A navigation keeps this document alive, so the stream survives and none
     * of this is needed. A reload does not, and this is what carries the
     * decision across it — the camera is picked up again on the other side.
     *
     * Session storage rather than local, deliberately. Carrying on across a
     * reload is continuing something switched on a moment ago; carrying on into
     * a visit tomorrow would be a camera coming on by itself.
     */
    const REMEMBERED = 'streetmesh:comms:sharing'

    const remember = () => {
        try {
            window.sessionStorage?.setItem(REMEMBERED, JSON.stringify({ speaking, showing }))
        } catch {
            /* Private browsing refuses rather than answering. It only means the
               camera does not follow you through a reload. */
        }
    }

    const remembered = () => {
        try {
            return JSON.parse(window.sessionStorage?.getItem(REMEMBERED) || '{}') || {}
        } catch {
            return {}
        }
    }

    /**
     * Put this application's theme on each frame's own document.
     *
     * Dark is a class on `<html>` here rather than a preference the browser
     * reports — the venue has a light/dark/system setting of its own — and each
     * frame is a separate document with its own `<html>`. Left alone they
     * follow the operating system, so a venue set to dark had a chat panel in
     * light sitting on top of it.
     *
     * Reached into rather than messaged, because they are the same origin and
     * this is one class. It also means the shells need no script at all.
     */
    const dressFrames = () => {
        const dark = document.documentElement.classList.contains('dark')

        for (const surface of [badge, panel, stage]) {
            surface.contentDocument?.documentElement.classList.toggle('dark', dark)
        }
    }

    for (const surface of [badge, panel, stage]) {
        surface.addEventListener('load', dressFrames)
    }

    /* The venue's own appearance setting changes it while the page is open. */
    new MutationObserver(dressFrames).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    })

    const toAll = (method, params = {}) => {
        for (const surface of [badge, panel, stage]) {
            surface.contentWindow?.postMessage({ method, params }, window.location.origin)
        }
    }

    // ── the media ────────────────────────────────────────────────────────────

    const capture = devices({
        onChange () {
            speaking = capture.holds('audio')
            showing = capture.holds('video')

            party?.carry()
            draw()
            remember()

            /* So the panel's switches show what is actually happening. */
            toAll('streetmesh.stage.media', { speaking, showing })
        },

        /**
         * A device we had, tried to get back, and could not.
         *
         * Said out loud because until now it was said to nobody: the console
         * carried it and the console is off unless somebody turned it on. The
         * consequence of the silence is a person talking to a room that cannot
         * hear them, which is the one thing here worth interrupting somebody
         * about.
         *
         * Only after the attempt to recover has failed. A device that is
         * unplugged and comes straight back says nothing at all, which is why
         * this cannot cry wolf.
         */
        onLost (kind) {
            toAll('streetmesh.stage.trouble', {
                why: kind === 'audio'
                    ? 'Your microphone stopped, and would not start again.'
                    : 'Your camera stopped, and would not start again.',
            })
        },
    })

    let party = null
    let partyKey = null

    /**
     * Join the party the page says we are in, or leave the one we were in.
     *
     * Asked at boot, after every navigation, and whenever the panel says
     * somebody started, joined or left one — the three ways it can change
     * without this document being replaced.
     */
    const reconcileParty = () => {
        const next = config.party ?? null

        if ((next?.key ?? null) === partyKey) {
            return
        }

        const wasInOne = partyKey !== null

        party?.leave()
        party = null
        partyKey = next?.key ?? null

        /*
         * Put the camera and the microphone down on the way out.
         *
         * They were turned on to talk to somebody. Leaving is the moment that
         * stops being true, and a light still burning over a conversation that
         * has ended is the kind of thing somebody discovers an hour later.
         *
         * Only on the way out of one, never on the way in to another: joining
         * a second party while already speaking is somebody carrying on, and
         * shutting them off mid-sentence to be tidy would be worse than the
         * light.
         *
         * Here rather than in the mesh, which does not own these — it is handed
         * what to send and has no business deciding whether there is anything
         * to send at all.
         */
        if (wasInOne && next === null) {
            capture.drop('audio')
            capture.drop('video')
        }

        if (next) {
            party = mesh({
                ticketUrl: next.ticketUrl,
                signalsUrl: next.signalsUrl,
                csrf: config.csrf,
                tracks: () => capture.tracks(),
                onPeople: draw,
                onTrouble: (why) => toAll('streetmesh.stage.trouble', { why }),
            })

            void party.join()
        }

        draw()
    }

    /**
     * Turn a camera or a microphone on, or off.
     *
     * A function rather than something drawn, because there is a state where
     * nothing is drawn: somebody with no party and nothing turned on has no
     * circle at all, and that is exactly when they need to turn one on.
     */
    const toggle = (kind) => {
        if (capture.holds(kind)) {
            capture.drop(kind)

            return
        }

        void capture.add(kind).then((got) => {
            if (!got) {
                toAll('streetmesh.stage.trouble', {
                    why: kind === 'audio'
                        ? 'This browser would not give us the microphone.'
                        : 'This browser would not give us the camera.',
                })
            }
        })
    }

    // ── the faces, drawn into the stage's document ───────────────────────────

    /**
     * Circles are kept between redraws but not between loads: they belong to
     * the stage's document, and a navigation gives us a new one. Assigning a
     * stream to a fresh video element restarts playback, so within a document
     * they are reused.
     */
    /** Below this a face is not a face. */
    const SMALLEST = 36

    let circles = new Map()

    /* A row that fitted in portrait need not fit in landscape. */
    window.addEventListener('resize', () => draw())

    const circle = (doc, key) => {
        if (circles.has(key)) {
            return circles.get(key)
        }

        const el = doc.createElement('div')

        el.className = 'face'

        /*
         * No letter drawn here.
         *
         * There used to be one underneath, on the reasoning that a picture is
         * fetched from somebody else's server and might not arrive. What that
         * amounted to was a second, differently styled answer to "what does
         * this person look like" — and a domicile answers that for every one of
         * its residents now, with their picture or with their initial. Two
         * answers can only disagree, and the one drawn here is the one with
         * less to go on.
         */
        el.innerHTML = `
            <video autoplay playsinline></video>
            <img class="portrait" alt="" decoding="async" hidden>
            <div class="quiet">${config.icons?.microphoneSlash ?? ''}</div>
            <div class="lost">${config.icons?.unreachable ?? ''}</div>
        `

        circles.set(key, el)

        return el
    }

    const paint = (el, { name, icon, stream, video, audio, self, lost }) => {
        const picture = el.querySelector('video')
        const face = el.querySelector('.portrait')

        /*
         * Pointed at the stream, and re-pointed when what is in it changes.
         *
         * The stream object is made once and tracks are added to it later, so
         * the element is usually given an empty one. WebKit notices the track
         * that turns up afterwards; Chrome does not reliably, and the picture
         * that never appeared was arriving the whole time. Re-assigning is what
         * makes it look again, and it is cheap because it only happens when the
         * count actually changes.
         */
        /*
         * Which tracks, not how many.
         *
         * A count cannot see a swap: somebody turning their camera off and on
         * sends a different track on a new line, and the stream goes from one
         * video to one video. Counting them, this decides nothing has changed
         * and leaves the element pointed at a stream whose first video track is
         * the one that stopped.
         */
        const carrying = stream ? stream.getTracks().map((track) => track.id).join() : ''

        if (stream && (picture.srcObject !== stream || picture.dataset.carrying !== carrying)) {
            picture.srcObject = stream
            picture.dataset.carrying = carrying
        }

        /* Hearing yourself a fraction of a second late is the single most
           disorienting thing a call can do. */
        picture.muted = Boolean(self)

        picture.hidden = !video

        /*
         * And make sure it is actually playing.
         *
         * The element is pointed at this peer's stream once, when the circle is
         * first drawn, and that stream is usually empty at the time — a track
         * is added to it minutes later when somebody presses Show. An element
         * that began playing an empty stream does not reliably start rendering
         * the track that arrives afterwards, and one that has been `hidden`
         * since may have stopped decoding altogether.
         *
         * WebKit resumes on its own; Chrome does not. That asymmetry is exactly
         * what "I can see them but they cannot see me" looks like, and it is
         * nothing to do with the connection — by then the picture is arriving,
         * it is simply not being drawn.
         *
         * The rejection is ignored on purpose: a play that loses a race with
         * another play is not a problem worth a line in anybody's console.
         */
        if (video) {
            void picture.play().catch(() => {})
        }
        /*
         * Their own picture, from their own server — which is the only thing
         * drawn for somebody who is not sending video.
         *
         * Assigned only when it actually changes. `draw` runs on every arrival,
         * every camera toggle and every resize, and setting `src` to the value
         * it already holds is a fresh fetch in some browsers — a party of four
         * would quietly poll four domiciles for as long as anybody stayed.
         *
         * A server that answers with nothing leaves an empty circle, and that
         * is the intended failure rather than an oversight: this venue has no
         * opinion about what somebody looks like, so it draws none.
         */
        const wanted = icon || ''

        if (face.dataset.wanted !== wanted) {
            face.dataset.wanted = wanted
            face.hidden = true

            if (wanted) {
                face.onload = () => { face.hidden = !picture.hidden }
                face.onerror = () => { face.hidden = true }
                face.src = wanted
            } else {
                face.removeAttribute('src')
            }
        }

        /*
         * And never over a live picture of them. A camera coming on has to take
         * the circle back, and going off has to give it up again — neither of
         * which changes the address, so neither goes through the branch above.
         */
        if (video) {
            face.hidden = true
        } else if (wanted && face.complete && face.naturalWidth > 0) {
            face.hidden = false
        }

        /*
         * Presence comes from the tracks rather than from a report of them.
         * Whether somebody can be heard is answered by whether their audio is
         * arriving, which is the truth rather than a claim about it.
         *
         * Except when they cannot be reached, where the honest answer is that
         * we do not know. No audio is arriving because no connection exists,
         * and drawing "their microphone is off" would be inventing a fact about
         * somebody we cannot hear from at all. The two marks share a slot and
         * the stronger one takes it.
         */
        el.querySelector('.quiet').hidden = audio || Boolean(lost)

        /* Only your own picture is mirrored — see the stage's stylesheet. */
        el.classList.toggle('self', Boolean(self))

        /*
         * Somebody this browser could not reach.
         *
         * Drawn where the muted mark is drawn and in the same shape, because it
         * is the same kind of fact: something about this person that the circle
         * would otherwise leave you to infer from an absence. An empty circle
         * already means "not sending a picture", and without this it also means
         * "cannot be reached at all" — two very different things wearing one
         * face, and the reason this failure has been mistaken for four separate
         * bugs already.
         */
        el.classList.toggle('lost', Boolean(lost))
        el.querySelector('.lost').hidden = !lost

        /* A list of who is in the party belongs on the panel, where there is
           room for it. This is what a pointer resting on a circle can find. */
        el.title = lost
            ? `${name || ''} — cannot connect`.trim()
            : name || ''
    }

    /**
     * Faces that are not anybody, for looking at a full party alone.
     *
     * `localStorage.smCrowd = 3` and reload. Purely a drawing aid — no
     * connection, no stream, no presence — so that a question about how four
     * circles sit on a narrow screen can be answered without finding three
     * other people and a fourth device.
     *
     * The same shelf as `smDebug`, and off unless somebody has deliberately put
     * a number there.
     */
    const crowd = () => {
        let many = 0

        try {
            many = Number(window.localStorage?.getItem('smCrowd')) || 0
        } catch {
            /* Private browsing refuses to answer, which is the same as none. */
        }

        /*
         * Everybody wears your face, which is not a joke about the fake names
         * it replaced.
         *
         * Those were bare words — `robin`, `sam` — and a bare word is not a
         * handle, so nothing could ever fetch a picture for one. Once the
         * circles stopped drawing a letter of their own, this drew a row of
         * empty discs and stopped answering the question it exists for, which
         * is how faces lay out at a real size on a narrow screen.
         *
         * Your own address is the one handle this page is certain resolves, so
         * these borrow it: real fetches, real bytes, and obviously pretend.
         */
        return Array.from({ length: Math.max(0, Math.min(many, 8)) }, (_, i) => ({
            session: `nobody-${i}`,
            name: config.me,
            icon: config.myIcon,
            stream: null,
            audio: false,
            video: false,
        }))
    }

    /**
     * Pretend nobody can be reached, for looking at what that does.
     *
     * `localStorage.smBreak = 1` and reload. The real thing needs two machines
     * on networks that cannot see each other, which is not something anybody
     * can arrange on a friendly afternoon — and a failure state that has never
     * been looked at is a failure state nobody has designed.
     *
     * The same shelf as `smCrowd` and `smDebug`, and off unless somebody has
     * deliberately put something there. It marks people rather than breaking
     * connections, so what it exercises is everything downstream of the
     * verdict: the circle, the words, and the message between the two.
     */
    const breaking = () => {
        try {
            return Boolean(window.localStorage?.getItem('smBreak'))
        } catch {
            /* Private browsing refuses to answer, which is the same as no. */
            return false
        }
    }

    /** The last set of names we told the panel about, so it is told only on change. */
    let announced = ''

    /**
     * Say who cannot be reached, once per change.
     *
     * `draw` runs on every track event, every resize and every redraw; the
     * panel needs to hear about this when it becomes true and when it stops
     * being true, and not sixty times in between.
     */
    const announce = (unreachable) => {
        const names = unreachable.map((one) => one.name).filter(Boolean)
        const now = names.join('\0')

        if (now === announced) {
            return
        }

        announced = now
        toAll('streetmesh.stage.unreachable', { names })
    }

    function draw () {
        /* Pretend faces sit furthest from the badge, so the real ones stay
           where they would be without them. */
        const broken = breaking()
        const joined = (party ? party.people() : [])
            .map((one) => (broken ? { ...one, lost: true } : one))

        const others = [...crowd(), ...joined]

        announce(joined.filter((one) => one.lost))
        const mine = speaking || showing || Boolean(config.party)
        const count = others.length + (mine ? 1 : 0)

        /*
         * Named rather than cleared. `display = ''` removes the inline value
         * and falls back to the stylesheet, which says `none` — so the strip
         * stayed hidden however many faces were on it.
         */
        /*
         * A face and its gap, not a whole badge frame. The badge's frame is
         * wider than its circle by a padding it keeps on both sides; a face
         * keeps one. Sizing the row by the frame left a gap between faces twice
         * the size of the gap at the end of it.
         */
        /*
         * How wide a face may be, given how many there are and how much room is
         * left of the badge.
         *
         * Four faces and a badge want 380px at full size, which is more than
         * the narrowest phone has — so past that point they shrink together
         * rather than the last one falling off the edge. The badge does not: it
         * is the anchor everything is measured from, and an anchor that moved
         * when somebody joined would be worse than a slightly larger circle at
         * the end of the row.
         *
         * There is a floor. Below it a face is not a face, and a row that
         * cannot be read is not worth fitting.
         */
        const full = config.badge || 60
        const gap = config.lift || 15

        /*
         * Measured off the badge rather than worked out from configuration.
         *
         * Where the badge sits is a stylesheet's decision and it differs by
         * breakpoint — asking the element where it actually is gets the right
         * answer under any of them, and cannot drift from the CSS the way a
         * second copy of the arithmetic would.
         *
         * The gutter it leaves on the left matches the one it keeps on the
         * right, so a full row looks inset rather than jammed against the edge.
         */
        const anchored = badge.getBoundingClientRect()
        const gutter = Math.max(0, window.innerWidth - anchored.right)

        const room = anchored.left - gutter
        const wanted = count * (full + gap)

        const slot = count > 0 && wanted > room ? Math.max(SMALLEST + gap, room / count) : full + gap
        const face = Math.floor(slot - gap)

        stage.style.width = count > 0 ? Math.ceil(slot * count) + 'px' : '0'
        stage.style.display = count > 0 ? 'block' : 'none'

        const doc = stage.contentDocument
        const strip = doc?.getElementById('stage')

        /* The frame is still loading. Its `load` handler draws again. */
        if (!strip) {
            return
        }

        /* Every measurement in that document is expressed against this. */
        doc.documentElement.style.setProperty('--face', face + 'px')

        /* Furthest from the badge first: the group, then you. */
        const row = []

        for (const person of others) {
            const el = circle(doc, person.session)

            paint(el, { ...person, self: false })
            row.push(el)
        }

        if (mine) {
            const el = circle(doc, 'me')

            paint(el, {
                name: config.me,
                icon: config.myIcon,
                stream: capture.stream(),
                video: showing,
                audio: speaking,
                self: true,
            })

            row.push(el)
        }

        /*
         * Rearranged only when the arrangement is actually wrong.
         *
         * This used to empty the row and put everybody back into it every time
         * it ran. Taking a `<video>` out of the document and returning it is not
         * free: WebKit tears the playback down and starts it again, which is a
         * flicker — and once presence started arriving on a poll rather than on
         * a change, it ran every second whether anybody had moved or not.
         *
         * So the common case touches no DOM at all. When somebody does arrive
         * or leave, the row is rebuilt and everybody blinks once, which is a
         * moment when something visibly happened anyway.
         */
        const already = [...strip.children]
        const settled = already.length === row.length
            && row.every((el, at) => already[at] === el)

        if (!settled) {
            strip.replaceChildren(...row)
        }
    }

    /*
     * A navigation reloads this frame even though the page did not. Its
     * document is new, so the circles in the old one are gone — they are
     * rebuilt against streams that never stopped.
     */
    stage.addEventListener('load', () => {
        circles = new Map()
        draw()
    })

    /*
     * And every frame is told where it is the moment it can hear.
     *
     * This script is a module, so it is deferred and runs after the page has
     * parsed — by which time a frame may already have loaded and announced
     * itself to nobody. Waiting to be asked meant the panel never learned which
     * room it was in and offered an empty tab forever.
     */
    panel.addEventListener('load', () => sendContext())

    // ── the panel and the badge ──────────────────────────────────────────────

    const setOpen = (next) => {
        open = next
        panel.style.display = open ? 'block' : 'none'
        toAll('streetmesh.widget.toggle', { open })

        /* Opening it is reading it. Whatever arrived while it was shut has now
           been offered to somebody, which is all the badge was claiming. */
        if (open) {
            markRead()
        }
    }

    /**
     * Whether anything has been said that nobody has had the chance to read.
     *
     * The panel polls whether or not it is showing — it is hidden rather than
     * unloaded — so it hears every line either way and says so. What it cannot
     * know is whether anybody is looking at it, and this document can, which is
     * why the judgement is here and not there.
     *
     * The newest line in each space is remembered rather than a count, because
     * a count has to be reconciled and a high-water mark does not: a line is
     * either newer than the last one seen or it is not.
     */
    const seen = new Map()

    let unread = false

    const markRead = () => {
        if (!unread) {
            return
        }

        unread = false
        toAll('streetmesh.panel.waiting', { waiting: unread })
    }

    const said = (space, id) => {
        if (typeof space !== 'string' || !id) {
            return
        }

        const before = seen.get(space)

        seen.set(space, id)

        /*
         * The first thing heard about a space is a baseline, not news. A panel
         * that has just loaded reports whatever is already on screen, and
         * treating that as unread would light the badge on arrival for a
         * conversation that happened last week.
         */
        if (before === undefined || id <= before || open || unread) {
            return
        }

        unread = true
        toAll('streetmesh.panel.waiting', { waiting: unread })
    }

    /**
     * Stand the placeholder down once the badge has drawn itself.
     *
     * A circle painted on the frame keeps the corner from being empty while the
     * document inside it loads. It must then get out of the way: two circles
     * drawn one over the other show as a ring wherever their edges disagree.
     */
    const standDown = () => {
        badge.style.background = 'transparent'
    }

    badge.addEventListener('load', standDown)

    if (badge.contentDocument?.readyState === 'complete') {
        standDown()
    }

    /**
     * Which space this screen is, read from the page rather than remembered.
     *
     * An experience marks its own screen, and the mark is swapped with the rest
     * of the body on a navigation — so reading it is always the current answer.
     */
    const readContext = () => {
        const marked = document.querySelector('[data-streetmesh-space]')

        return marked
            ? { space: marked.dataset.streetmeshSpace || '', label: marked.dataset.streetmeshLabel || '' }
            : { space: '', label: '' }
    }

    let context = { ...readContext(), ...(config.context || {}) }

    const sendContext = () => {
        toAll('streetmesh.widget.context', context)
        party?.here(context.space)
    }

    Object.assign(config, {
        open: () => setOpen(true),
        close: () => setOpen(false),
        toggle: () => setOpen(!open),

        /** For a screen whose space is not known until something happens on it. */
        context (next) {
            context = { ...context, ...next }
            sendContext()
        },
    })

    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin) {
            return
        }

        const { method, params } = event.data || {}

        if (!method || method.indexOf('streetmesh.') !== 0) {
            return
        }

        if (method === 'streetmesh.badge.click') {
            setOpen(!open)
        } else if (method === 'streetmesh.panel.close' || method === 'streetmesh.badge.esc') {
            setOpen(false)
        } else if (method === 'streetmesh.panel.speak') {
            toggle('audio')
        } else if (method === 'streetmesh.panel.show') {
            toggle('video')
        } else if (method === 'streetmesh.panel.party') {
            /*
             * Somebody started, joined or left one. The page did not reload, so
             * this is the only way this document hears about it.
             */
            config.party = params?.party ?? null
            reconcileParty()
        } else if (method === 'streetmesh.chat.said') {
            said(params?.space, params?.said)
        } else if (method === 'streetmesh.surface.ready') {
            sendContext()
            toAll('streetmesh.stage.media', { speaking, showing })
            toAll('streetmesh.panel.waiting', { waiting: unread })
        } else {
            /* One surface talking to the others, carried without being read. */
            toAll(method, params)
        }
    })

    /*
     * A navigation swaps the body — and with it the mark an experience left and
     * the inline script that says which party this visitor is in. The frames
     * are reloaded; the media is not.
     */
    /*
     * Take this browser off the list on the way out.
     *
     * The beacon only, never `leave()` — `pagehide` also fires when a page goes
     * into the back-forward cache, and a mesh stopped there would never start
     * again when it came back. The poll re-registers by itself if it does.
     */
    window.addEventListener('pagehide', () => party?.depart())

    document.addEventListener('livewire:navigated', () => {
        context = readContext()
        reconcileParty()
        sendContext()
    })

    // ── and go ───────────────────────────────────────────────────────────────

    reconcileParty()
    sendContext()
    dressFrames()

    /**
     * Pick up whatever was being shared before a reload.
     *
     * Silent, because the permission was already given to this origin — the
     * browser asks once and remembers. What it may not be is a *first* request:
     * with nothing remembered nothing is asked for.
     *
     * One kind at a time: asking for a camera while already holding a
     * microphone ends the microphone's track on WebKit.
     */
    void (async () => {
        const held = remembered()

        if (held.speaking) {
            await capture.add('audio')
        }

        if (held.showing) {
            await capture.add('video')
        }
    })()
}()
