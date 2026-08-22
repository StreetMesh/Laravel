/**
 * Telling a venue what happened in one of its rooms.
 *
 * The only thing this hub says unprompted. Everything else is the other way
 * round — a ticket is signed by the venue and merely verified here, and a
 * result is asked for rather than offered — and that asymmetry is deliberate:
 * a hub that could assert things would be a hub a venue had to authenticate.
 *
 * Two things make asking impossible, and both happen when nobody is looking.
 * A table empties, and the venue has no reason to ask. A game ends after both
 * players have closed their tabs, and there is nobody left to knock — the room
 * is disposed shortly afterwards and the result is gone for good.
 *
 * So: a shared secret, because a hub holds no key of its own. It is worth being
 * plain about what that buys. The venue believes the state of a room it opened
 * and the result of a game it started. Nothing here says who anybody is; that
 * came from a ticket the venue signed and it does not come back this way.
 */

/**
 * Where a venue is, worked out from the ticket that opened the room.
 *
 * Not configured. Every ticket names the venue that signed it — the hub already
 * resolved that DID to fetch the key it verified with — so the address to call
 * back on arrives with the authority to open the room in the first place.
 *
 * A hub serving several venues therefore cannot be talked into calling the
 * wrong one, and an operator has one fewer setting to keep in step with
 * reality.
 */
export function venueFor(issuer: string): string | null {
  // did:web:games.example        → https://games.example
  // did:web:games.example:venues:one → https://games.example/venues/one
  if (!issuer.startsWith('did:web:')) {
    return null
  }

  const [host, ...path] = issuer.slice('did:web:'.length).split(':').map(decodeURIComponent)

  if (!host) {
    return null
  }

  return path.length === 0 ? `https://${host}` : `https://${host}/${path.join('/')}`
}

export type Announcement = {
  room: string
  occupants: Array<{ name: string; seat: string }>
  result?: Record<string, unknown> | null
}

/**
 * Say it, and do not wait to be thanked.
 *
 * A venue that is down or slow must not hold up a room: the people in it are
 * playing a game, and none of what is being said here is anything they are
 * waiting on. A failure is logged and dropped — the venue asks for occupancy
 * when it next needs it, and a result that did not arrive is one the next
 * messenger carries.
 */
export async function announce(issuer: string, secret: string, what: Announcement): Promise<void> {
  const venue = venueFor(issuer)

  if (!venue || !secret) {
    return
  }

  try {
    const answer = await fetch(`${venue}/realtime`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${secret}`,
      },
      body: JSON.stringify(what),
    })

    if (!answer.ok && answer.status !== 404) {
      // 404 is ordinary: a venue may have forgotten a gathering this is still
      // holding. Anything else is worth seeing in a log.
      console.warn(`[hub] ${venue} answered ${answer.status} about ${what.room}`)
    }
  } catch (unreachable) {
    console.warn(`[hub] could not reach ${venue} about ${what.room}:`, (unreachable as Error).message)
  }
}
