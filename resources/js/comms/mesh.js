import peer from '../media/peer.js'
import signals from '../media/signals.js'
import { say, trouble } from '../media/log.js'

/**
 * Everybody in a party, connected to everybody else.
 *
 * The venue says who is here and carries the handshake, both on the same poll.
 * A party is people talking, which is the host's business — the realtime half
 * is for the rules of an experience and the state a room has to agree on, and a
 * party has neither. It used to answer this, and issuing an identity of its own
 * meant a moment of bad signal read to everybody else as one person leaving and
 * a stranger arriving.
 *
 * So this browser names its own connection. Nothing is issued to it, so nothing
 * can be withdrawn; going quiet stops it being mentioned, and coming back is
 * saying the same name again.
 *
 * Framework-free, and that is deliberate rather than austere. This holds a
 * microphone and a set of peer connections that have to outlive every re-render
 * happening around them, and the surest way to guarantee that is to owe nothing
 * to whatever is doing the rendering.
 *
 * It draws nothing. What it does is call back when something changes, and the
 * thing that draws decides what that looks like.
 */

/**
 * How often to look for notes left for us.
 *
 * Settled is not idle: somebody turning a camera on is a fresh negotiation
 * arriving unannounced, so the slow pace is what a person waits through before
 * they are seen. The fast one is for while a connection is still being made,
 * which is the only time anybody is watching.
 */
const LOOKING_HARD = 500
const LOOKING = 1000

export default function mesh({ ticketUrl, signalsUrl, csrf, tracks, onPeople, onTrouble }) {
    const connections = new Map()

    /**
     * Notes that arrived before the room mentioned who sent them.
     *
     * Both sides learn of each other from the same room state, but not at the
     * same instant — and whoever is impolite offers the moment it hears. So an
     * offer can be collected by a browser that has not yet built the connection
     * it belongs to.
     *
     * These are kept rather than dropped, which is the whole of this fix. An
     * offer is sent once and never repeated: discarding one leaves the sender
     * holding a local offer nobody will ever answer, and a connection stuck
     * there is not `stable` — so every later attempt to add a camera queues a
     * renegotiation the browser will never run. The symptom is a track that is
     * added, reported as sent, and then silently ignored for the life of the
     * page.
     */
    const waiting = new Map()

    /**
     * Peers we have already asked to find another way round.
     *
     * `failed` has two quite different meanings and only one of them is worth
     * telling anybody about. A connection that worked and then stopped —
     * because a laptop slept, or a machine moved between networks — is often
     * repaired by gathering addresses again, and the platform provides
     * `restartIce` for exactly that. A connection that never had a route in the
     * first place is not repaired by anything this browser can do alone.
     *
     * Trying once tells the two apart. What is left after a restart has been
     * attempted and failed is the real thing: these two cannot reach each
     * other, and somebody should be told rather than left watching an empty
     * circle for as long as their patience holds.
     */
    const rerouted = new Set()

    /**
     * Where to fetch each person's picture, by connection.
     *
     * The venue says this rather than the browser working it out from a name.
     * A handle is a hostname, so the address does follow from the name — but
     * turning a string that arrived over the wire into an address a browser is
     * sent to has one correct implementation and it is in PHP, and a second one
     * written here would be a second definition of a rule that has to hold.
     *
     * Null for somebody whose server publishes nothing, which is most people,
     * and which draws as the letter it always did.
     */
    const faces = new Map()

    let post = null
    let ice = []
    let stopped = false
    let where = ''

    /**
     * What this browser calls itself for as long as this document lives.
     *
     * Minted here rather than kept anywhere, and that is deliberate. A note
     * carries no mark saying which attempt it belongs to, so an answer to an
     * offer from a connection that has since been thrown away would be applied
     * to its replacement — which puts that connection into a state it never
     * recovers from. A name that changes when the page reloads is what makes
     * yesterday's notes addressed to nobody.
     *
     * Which is exactly what the room's own identifier did, and the only part of
     * it worth keeping.
     */
    const session = crypto.randomUUID?.() ?? [...crypto.getRandomValues(new Uint8Array(16))]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('')

    /**
     * Getting back in, when the room goes away.
     *
     * A laptop that sleeps takes the websocket with it, and the venue is
     * briefly unreachable while everything else wakes up — so both joining and
     * staying joined can fail for reasons that have nothing to do with anybody.
     * Trying once and giving up means a party that quietly stops existing after
     * lunch, with the media still running and nobody to send it to.
     *
     * Backed off rather than hammered, and capped: whatever is wrong is not
     * going to be fixed by asking faster.
     */
    let rejoining = null
    let attempts = 0

    const tryAgain = () => {
        if (stopped) {
            return
        }

        attempts = Math.min(attempts + 1, 5)

        const wait = Math.min(1000 * 2 ** (attempts - 1), 15000)

        say(`mesh: lost the party room, trying again in ${wait}ms`)

        clearTimeout(rejoining)
        rejoining = setTimeout(() => void api.join(), wait)
    }

    /** Everything that belonged to the connection we just lost. */
    const forgetEveryone = () => {
        for (const connection of connections.values()) {
            connection.close()
        }

        connections.clear()
        waiting.clear()
        rerouted.clear()
        changed()
    }

    /**
     * Who is here, as the thing drawing wants it.
     *
     * Whether somebody is sending video is answered by whether there is a video
     * track, not by whether it is carrying packets this instant. A track that
     * has just arrived reports itself muted until media flows — and in Chrome
     * whether it flows can depend on something consuming it, which makes
     * hiding the picture until it unmutes circular: hidden because muted, muted
     * because hidden.
     *
     * The track is also the more honest answer to the question being asked. A
     * muted overlay should mean "not sharing a microphone", not "no packet in
     * the last few milliseconds".
     */
    const people = () =>
        [...connections.keys()].map((id) => {
            const held = connections.get(id).arriving

            return {
                session: id,
                name: connections.get(id).name,
                icon: faces.get(id) ?? null,
                status: connections.get(id).status(),

                /*
                 * Somebody this browser cannot reach, having already tried the
                 * one remedy there is. See `rerouted` — what makes this worth
                 * reporting is that it is settled, not that it is going badly.
                 */
                lost: connections.get(id).status() === 'failed' && rerouted.has(id),
                stream: held,
                audio: held.getAudioTracks().length > 0,
                video: held.getVideoTracks().length > 0,
            }
        })

    const changed = () => onPeople(people())

    const settled = () => [...connections.values()].every((one) => one.status() === 'connected')

    /**
     * Open a line to one other person.
     *
     * Politeness comes from comparing the two session identifiers, which gives
     * opposite answers on the two sides without either being told. Perfect
     * negotiation needs exactly one polite party and nothing else about who.
     */
    const connect = (id, name) => {
        const connection = peer({
            ice,
            polite: session < id,
            name,
            send: (note) => post.post(id, note),
            /* A track came or went. What it is doing right now is read off the
               stream when we draw — see `people`. */
            onTrack: changed,
            onStatus: (state) => {
                say(`${name} is ${state}`)

                /*
                 * `disconnected` is not this. The browser reports it for a
                 * lull it usually recovers from by itself within seconds, and
                 * anything drawn for it would appear on every brief hiccup —
                 * which is worse than drawing nothing, because a warning that
                 * cries wolf is one people learn to read past.
                 */
                if (state === 'failed' && !rerouted.has(id)) {
                    rerouted.add(id)

                    say(`${name}: no route — asking for another`)
                    connection.reroute()
                }

                /*
                 * And a connection that comes back is not carrying a grudge.
                 * Without this, one recovered failure would leave somebody
                 * marked unreachable for the life of the party.
                 */
                if (state === 'connected') {
                    rerouted.delete(id)
                }

                changed()
            },
        })

        connections.set(id, connection)

        connection.open()
        connection.carry(tracks())

        /* Anything they said before we existed. */
        const held = waiting.get(id)

        if (held) {
            waiting.delete(id)

            say(`mesh: ${held.length} note(s) were waiting from ${name}`)

            for (const note of held) {
                void connection.absorb(note)
            }
        }

        say(`mesh: opened a line to ${name}`)
    }

    const regard = (present) => {
        let moved = false

        for (const { id, name, icon } of present) {
            /*
             * Kept beside the connection rather than inside it. A peer is a
             * negotiation; what somebody looks like has nothing to do with one,
             * and threading it through `peer()` would put a cosmetic field in
             * the middle of the only code here that has to be exactly right.
             */
            faces.set(id, icon ?? null)

            if (!connections.has(id)) {
                connect(id, name)
                moved = true
            }
        }

        for (const id of [...connections.keys()]) {
            if (!present.some((person) => person.id === id)) {
                connections.get(id).close()
                connections.delete(id)
                rerouted.delete(id)
                faces.delete(id)
                moved = true
            }
        }

        /*
         * Only when somebody actually did.
         *
         * This is asked once a second now, where the room told it only when
         * something had happened — so saying "the party changed" every time
         * would be saying it to a browser that has to redraw a row of video to
         * find out it did not. Everything else that changes about a person —
         * their connection settling, a camera coming on — announces itself from
         * where it happens.
         */
        if (moved) {
            changed()
        }
    }

    const api = {
        async join() {
            if (stopped) {
                return false
            }

            /* Whatever the last attempt left behind. */
            post?.stop()
            forgetEveryone()

            let admitted

            try {
                const response = await fetch(ticketUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                })

                admitted = await response.json()

                if (!response.ok) {
                    throw new Error(admitted.error ?? 'refused')
                }
            } catch (refused) {
                trouble('could not get a way into the party', refused)
                onTrouble('Your party would not let you in.')
                tryAgain()

                return false
            }

            ice = admitted.ice ?? []

            post = signals({
                url: signalsUrl,
                session,
                csrf,
                where: () => where,
                onAnswer: async ({ signals: notes = [], present = [], resumed = false }) => {
                    /*
                     * Away long enough to have been forgotten. Everybody else
                     * has already torn down the connection they had to this
                     * browser, so there is nothing here worth keeping either —
                     * and rebuilding is cheaper than finding out one failed
                     * handshake at a time.
                     */
                    if (resumed && connections.size) {
                        say('mesh: we were away long enough to be forgotten, starting again')
                        forgetEveryone()
                    }

                    /*
                     * Who is here before what they said, always. They arrive on
                     * one response for exactly this reason: a note can never be
                     * early, because the presence explaining it is in the same
                     * answer.
                     */
                    regard(present)

                    if (notes.length) {
                        say(`collected ${notes.length} note(s)`)
                    }

                    for (const { from, data } of notes) {
                        say(`a ${data.description ? data.description.type : 'candidate'} from ${from}`)

                        const connection = connections.get(from)

                        if (!connection) {
                            /* Held until the room says who they are — see
                               `waiting` above. */
                            waiting.set(from, [...(waiting.get(from) ?? []), data])

                            continue
                        }

                        await connection.absorb(data)
                    }
                },
                pace: () => (settled() ? LOOKING : LOOKING_HARD),
            })

            post.start()

            attempts = 0
            say(`mesh: at the party as ${session}`)

            return true
        },

        /**
         * Tell the party where this browser is.
         *
         * Kept rather than sent, because the poll is going anyway and this is
         * not worth a request of its own. Nothing draws it yet; it is here
         * because a party that knows where its members are can offer to take
         * somebody to one of them, and adding it later would be a retrofit.
         */
        here(space) {
            where = space ?? ''
        },

        /**
         * Say we are going, without waiting to be missed.
         *
         * Separate from `leave` because closing a tab is not leaving the party
         * — the membership is in the venue's database and outlives the page.
         * This only takes the browser off the list of who is looking.
         */
        depart() {
            post?.gone()
        },

        /** Send every peer exactly what is being captured now. */
        carry() {
            for (const connection of connections.values()) {
                connection.carry(tracks())
            }
        },

        people,

        leave() {
            if (stopped) {
                return
            }

            stopped = true
            clearTimeout(rejoining)
            post?.stop()

            for (const connection of connections.values()) {
                connection.close()
            }

            connections.clear()

            post?.gone()
        },
    }

    return api
}
