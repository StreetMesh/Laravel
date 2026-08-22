import { say, trouble } from './log.js';

const AUDIO = 'audio';
const VIDEO = 'video';

/**
 * One connection to one other person.
 *
 * This is perfect negotiation, the pattern the specification authors wrote for
 * exactly this problem, and it is deliberately unremarkable. Tracks are added
 * and removed; the browser decides when that needs a new round of negotiation
 * and says so; both sides may start one at any moment and the collision is
 * resolved by one of them being polite.
 *
 * An earlier version of this file avoided renegotiation altogether by declaring
 * empty audio and video lines up front and swapping tracks into them. That
 * dodges nothing and costs a great deal: a line with no track names no stream,
 * and a line that names no stream raises no track event in WebKit, so the other
 * side never learns it is there. It also meant setting directions by hand and
 * matching lines by position, both of which went wrong in their own ways.
 *
 * The rule now is that nothing here is clever. Where the platform has an
 * opinion, we follow it.
 */
export default function peer({ ice, polite, name, send, onTrack, onStatus }) {
    const pc = new RTCPeerConnection({ iceServers: ice });

    /*
     * One stream for everything we send, so each track is announced with a name
     * the other side can group by — which is what makes it a person rather than
     * two unrelated tracks that happen to arrive together.
     */
    const outgoing = new MediaStream();
    const arriving = new MediaStream();
    const senders = new Map();

    let offering = false;
    let ignoring = false;
    let status = 'new';
    let dismissed = false;

    function report(next) {
        if (status === next || dismissed) {
            return;
        }

        status = next;
        say(`${name} → ${next}`);
        onStatus(next);
    }

    /*
     * The browser raises this whenever what it has agreed no longer matches what
     * it has been given — a track added, a track removed. Answering it is the
     * whole of keeping a connection current.
     */
    pc.addEventListener('negotiationneeded', async () => {
        say(`${name}: negotiation needed (signalling ${pc.signalingState})`);

        try {
            offering = true;

            await pc.setLocalDescription();

            say(`${name}: offering`);
            send({ description: pc.localDescription });
        } catch (error) {
            trouble(`${name}: could not offer`, error);
        } finally {
            offering = false;
        }
    });

    pc.addEventListener('icecandidate', ({ candidate }) => {
        if (candidate) {
            send({ candidate });
        }
    });

    pc.addEventListener('connectionstatechange', () => {
        report(pc.connectionState);
    });

    pc.addEventListener('track', ({ track, streams }) => {
        say(`${name}: ${track.kind} line open`);

        /*
         * One of each kind, because that is what they are sending.
         *
         * A track this side never ends on its own. Somebody turning their
         * camera off calls `removeTrack`, and what arrives here is `mute` —
         * ending is reserved for a transceiver being stopped or the connection
         * going away. So the dead one stays.
         *
         * Turning it back on cannot reuse the line it was sent on: a
         * transceiver that has already sent is not a candidate for `addTrack`,
         * so a fresh one is negotiated and a second `track` event arrives here
         * for the same kind. Left alone, this stream ends up holding both.
         *
         * A media element plays the first audio track and draws the first video
         * track, and the first is the one that stopped — so the picture never
         * came back and the sound never returned, until a reload built the
         * stream again from nothing. Which is exactly what it looked like.
         */
        for (const stale of arriving.getTracks()) {
            if (stale.kind === track.kind && stale !== track) {
                say(`${name}: replacing the ${track.kind} they were sending`);
                arriving.removeTrack(stale);
            }
        }

        arriving.addTrack(track);
        onTrack(track.kind, !track.muted);

        track.addEventListener('unmute', () => {
            say(`${name}: ${track.kind} arriving`);
            onTrack(track.kind, true);
        });

        track.addEventListener('mute', () => onTrack(track.kind, false));

        track.addEventListener('ended', () => {
            arriving.removeTrack(track);
            onTrack(track.kind, false);
        });

        void streams;
    });

    return {
        name,
        arriving,

        status: () => status,

        /**
         * Open the connection before anybody has anything to send.
         *
         * A connection with no tracks in it has nothing to negotiate, so without
         * this the first person to press Speak waits for a handshake rather than
         * for a microphone. A channel nobody writes to is enough to make the
         * browser go and find the other side.
         *
         * Only the impolite side does it, so there is one opener and the polite
         * side has nothing to collide with at the start.
         */
        open() {
            if (!polite) {
                pc.createDataChannel('stage');
            }
        },

        /**
         * Send exactly these tracks, and nothing else.
         *
         * Swapping a track for another of the same kind needs no negotiation and
         * gets none. Adding a kind we were not sending, or dropping one we were,
         * changes what has been agreed — so the browser raises
         * `negotiationneeded` and the exchange above deals with it.
         */
        carry(tracks) {
            const wanted = new Map(tracks.map((track) => [track.kind, track]));

            for (const kind of [AUDIO, VIDEO]) {
                const track = wanted.get(kind) ?? null;
                const sender = senders.get(kind);

                if (track && sender) {
                    /*
                     * Already sending this exact track. The common case, because
                     * turning the camera on re-asks for everything and hands
                     * back a whole new set — so the audio sender meets a new
                     * audio track whenever the video changes, and sometimes the
                     * very same one.
                     */
                    if (sender.track === track) {
                        continue;
                    }

                    /*
                     * Awaited, and complained about when it fails.
                     *
                     * A rejection here leaves the sender holding the track that
                     * was just stopped, which is somebody speaking into a
                     * connection that carries nothing while their button stays
                     * lit — the failure this file's own history is about. It
                     * was unawaited and uncaught, so it could not be seen even
                     * by somebody looking for it.
                     *
                     * Reported rather than repaired. Removing the sender and
                     * adding it again would force a renegotiation on a
                     * connection that has just said it is unwell, and there is
                     * no evidence yet about how often this happens. This is how
                     * that evidence gets collected.
                     */
                    sender
                        .replaceTrack(track)
                        .then(() => {
                            for (const stale of outgoing.getTracks()) {
                                if (stale.kind === kind) {
                                    outgoing.removeTrack(stale);
                                }
                            }

                            outgoing.addTrack(track);
                        })
                        .catch((refused) => trouble(`${name}: could not swap their ${kind}`, refused));

                    continue;
                }

                if (track) {
                    outgoing.addTrack(track);
                    senders.set(kind, pc.addTrack(track, outgoing));

                    /*
                     * The signalling state with it, because this is the moment
                     * that decides whether anything follows. A connection still
                     * holding an offer nobody answered is not `stable`, and the
                     * browser will queue the renegotiation this add needs and
                     * never run it — which looks exactly like a track that was
                     * added and then ignored.
                     */
                    say(`${name}: sending them ${kind} (signalling ${pc.signalingState}, connection ${pc.connectionState})`);

                    continue;
                }

                if (sender) {
                    pc.removeTrack(sender);
                    senders.delete(kind);

                    for (const gone of outgoing.getTracks()) {
                        if (gone.kind === kind) {
                            outgoing.removeTrack(gone);
                        }
                    }

                    say(`${name}: no longer sending ${kind}`);
                }
            }
        },

        async absorb({ description, candidate }) {
            try {
                if (description) {
                    /*
                     * Both sides may offer at once. The impolite one carries on
                     * and ignores what arrived; the polite one gives way, which
                     * setRemoteDescription does by rolling back its own offer.
                     */
                    const collision = description.type === 'offer'
                        && (offering || pc.signalingState !== 'stable');

                    ignoring = !polite && collision;

                    if (ignoring) {
                        say(`${name}: our offers crossed, keeping ours`);

                        return;
                    }

                    await pc.setRemoteDescription(description);

                    if (description.type === 'offer') {
                        await pc.setLocalDescription();
                        send({ description: pc.localDescription });
                    }

                    return;
                }

                if (candidate) {
                    await pc.addIceCandidate(candidate);
                }
            } catch (error) {
                /* An address belonging to an offer we chose to ignore has nowhere to go. */
                if (!ignoring) {
                    trouble(`${name}: could not take what they sent`, error);
                }
            }
        },

        /**
         * Ask for another route to the same person.
         *
         * The remedy the platform provides for a connection that cannot find its
         * way: gather addresses again and negotiate them, keeping everything the
         * two sides already agreed about what they are sending each other.
         */
        reroute() {
            if (polite) {
                return;
            }

            pc.restartIce();
        },

        close() {
            dismissed = true;
            pc.close();
        },
    };
}
