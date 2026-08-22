import { say, trouble } from './log.js';

/**
 * The microphone and camera on this machine.
 *
 * One capture, always. Asking the browser for a camera while it is already
 * holding a microphone is not additive on WebKit — the second request ends the
 * tracks from the first, and a sender left holding a dead track goes quiet with
 * the button still lit and nothing to say why. So adding a kind re-asks for
 * everything wanted in a single call and hands back a whole new set of tracks.
 *
 * Dropping a kind is the opposite: stop that track and leave the rest alone,
 * which costs no second permission prompt and disturbs nothing.
 */
export default function devices({ onChange, onLost = () => {} }) {
    const wanted = new Set();

    let capture = new MediaStream();

    /**
     * Kinds this machine has actually given us at some point.
     *
     * The difference between "would not give us the microphone" and "your
     * microphone stopped and would not start again", which are different
     * sentences because they are different events: one is a prompt being
     * declined, the other is something being taken away from somebody already
     * using it. Only the second is worth interrupting anybody about.
     */
    const established = new Set();

    /**
     * When we last went looking for a kind that had ended.
     *
     * The pass count inside `settle` stops it spinning on one call; this stops
     * it spinning across calls. A device that grants a track and ends it
     * immediately — a virtual camera being reconfigured, a headset arguing with
     * a dock — would otherwise be asked again the moment it failed, forever,
     * and on some browsers each ask is a prompt.
     *
     * Long enough that a real event never trips it: unplugging a microphone,
     * waking a laptop, changing the default input are all isolated. Something
     * doing it twice in five seconds is not a person.
     */
    const soughtAt = new Map();

    const CALM_MS = 5000;

    /**
     * These listeners live exactly as long as the capture they came from.
     *
     * One controller per generation, aborted before the tracks it belongs to
     * are stopped. Without it, a superseded track could still speak: the
     * handler below asks whether its *kind* is wanted, not whether the track is
     * still ours, so an `ended` arriving late from a track we had already
     * replaced would be read as the current one dying.
     *
     * That is not hypothetical. Asking WebKit for a camera while it holds a
     * microphone ends the microphone's track, and the event for it arrives
     * while the replacement is already installed.
     */
    let generation = new AbortController();

    /** Whatever ends a track — unplugged, revoked, taken by another tab. */
    function watch(track) {
        track.addEventListener(
            'ended',
            () => {
                /*
                 * Belt as well as braces. Aborting and the event the platform
                 * had already queued are a race, and this side of it costs one
                 * expression.
                 */
                if (!capture.getTracks().includes(track)) {
                    return;
                }

                if (!wanted.has(track.kind)) {
                    return;
                }

                /*
                 * Note it and ask for it back. Nothing else — and in particular
                 * not `wanted.delete`, which is what used to happen and is what
                 * turned an unplugged microphone into a decision to stop
                 * speaking that nobody made and nothing announced.
                 */
                const last = soughtAt.get(track.kind) ?? 0;

                if (Date.now() - last < CALM_MS) {
                    trouble(`devices: ${track.kind} keeps ending, so leaving it alone`, {
                        kind: track.kind,
                    });

                    wanted.delete(track.kind);

                    if (established.delete(track.kind)) {
                        onLost(track.kind);
                    }

                    void queue();

                    return;
                }

                soughtAt.set(track.kind, Date.now());

                say(`devices: ${track.kind} ended on its own, asking for it back`);

                void queue();
            },
            { signal: generation.signal },
        );
    }

    function replaceCapture(granted) {
        /* Before stopping anything, so nothing we are about to discard is heard
           from afterwards. */
        generation.abort();
        generation = new AbortController();

        for (const track of capture.getTracks()) {
            track.stop();
        }

        capture = granted;

        for (const track of capture.getTracks()) {
            watch(track);
        }
    }

    async function acquire(kinds) {
        const constraints = Object.fromEntries([...kinds].map((kind) => [kind, true]));

        return navigator.mediaDevices.getUserMedia(constraints);
    }

    /**
     * Make the hardware match what was asked for.
     *
     * The only thing here that touches a device. `wanted` is what the person
     * asked for and `capture` is what the machine is currently giving; they are
     * genuinely two things, and collapsing them would make a microphone dying
     * into the decision to stop speaking. What was missing was not one variable
     * but somebody whose job is to close the gap.
     *
     * Every path in — a button, a track ending, a recovery — now records intent
     * and asks for this. None of them touches a device themselves, which is
     * what makes the two impossible to leave disagreeing.
     */
    async function settle() {
        /*
         * Bounded rather than `while (true)`. `wanted` can change while we are
         * awaiting a prompt, and asking for a camera can end a microphone, so
         * one pass is not always enough — but a device that grants a track and
         * ends it immediately would spin here forever.
         */
        for (let pass = 0; pass < 3; pass++) {
            /*
             * Anything unwanted or dead, stopped and dropped. This is the whole
             * of putting a kind down: no second prompt, nothing else disturbed.
             */
            for (const track of capture.getTracks()) {
                if (!wanted.has(track.kind) || track.readyState !== 'live') {
                    track.stop();
                    capture.removeTrack(track);
                }
            }

            const live = new Set(
                capture.getTracks().filter((track) => track.readyState === 'live').map((track) => track.kind),
            );

            const missing = [...wanted].filter((kind) => !live.has(kind));

            for (const kind of live) {
                established.add(kind);
            }

            if (missing.length === 0) {
                return;
            }

            try {
                /* One capture, always — see the note at the top of this file. */
                replaceCapture(await acquire(wanted));
            } catch (error) {
                trouble(`devices: could not get ${missing.join(', ')}`, error);

                /*
                 * Only what is still unobtainable is given up. A camera that was
                 * refused must not take a working microphone with it, which is
                 * what asking for everything and failing used to do.
                 */
                for (const kind of missing) {
                    wanted.delete(kind);

                    if (established.delete(kind)) {
                        onLost(kind);
                    }
                }

                return;
            }
        }

        trouble('devices: gave up trying to match what was asked for', {
            wanted: [...wanted],
            holding: capture.getTracks().map((track) => `${track.kind}:${track.readyState}`),
        });
    }

    /**
     * One at a time, and a failure does not poison the rest.
     *
     * A chain rather than a lock, the same shape the signal sender uses: two
     * requests for a device overlapping is how a button pressed twice ends with
     * the microphone open and the light off. Both handlers are passed to
     * `then` deliberately — a rejection that broke the chain would freeze every
     * control on the widget for the life of the page, which is a worse bug than
     * any of the ones being fixed here.
     */
    let work = Promise.resolve();

    function queue() {
        const job = () => settle().then(onChange, (error) => {
            trouble('devices: settling failed', error);
            onChange();
        });

        work = work.then(job, job);

        return work;
    }

    return {
        stream: () => capture,

        holds: (kind) => wanted.has(kind),

        /**
         * What may be sent to somebody else.
         *
         * Filtered rather than handed over whole, because `wanted` and
         * `capture` can disagree and every disagreement in this direction is a
         * microphone nobody knows is on. A kind can leave `wanted` while a live
         * track of that kind stays in `capture` — a second `getUserMedia` that
         * ends the first one's tracks, a button pressed twice before the prompt
         * is answered, an acquisition that failed after another had already
         * succeeded — and this is the last thing standing between any of that
         * and the whole party hearing somebody who believes they are muted.
         *
         * Loud when it bites, because a track being masked here means something
         * upstairs is wrong and the mask is treatment rather than cure: it
         * stops anybody hearing you, and it does not close the microphone. The
         * light on the machine stays on.
         */
        tracks: () => {
            const offered = capture.getTracks();
            const sendable = offered.filter(
                (track) => wanted.has(track.kind) && track.readyState === 'live',
            );

            if (sendable.length !== offered.length) {
                trouble('devices: holding back a track nobody asked for', {
                    wanted: [...wanted],
                    held: offered.map((track) => `${track.kind}:${track.readyState}`),
                });
            }

            return sendable;
        },

        /**
         * Start capturing one more kind.
         *
         * Reports whether it got it, rather than throwing: a person declining a
         * camera prompt is an ordinary answer, not an exceptional one.
         *
         * The intent is recorded at once, before anything is awaited, so the
         * button lights the moment it is pressed rather than when the prompt is
         * answered. Everything after that is the reconciler's, on the queue.
         */
        async add(kind) {
            wanted.add(kind);

            await queue();

            return capture
                .getTracks()
                .some((track) => track.kind === kind && track.readyState === 'live');
        },

        /**
         * Put one down.
         *
         * Not awaited by anybody, and it does not need to be: the button reads
         * `holds`, which reads the intent, and the intent is already correct by
         * the time this returns.
         */
        drop(kind) {
            wanted.delete(kind);

            /* Put down on purpose, so its coming back later is a fresh start
               rather than a recovery, and its refusal is not a loss. */
            established.delete(kind);

            void queue();
        },
    };
}
