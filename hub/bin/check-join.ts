/**
 * Check that a ticket opens a door, and that nothing else does.
 *
 *   ./check-join
 *
 * `check-ticket` verifies a signature in isolation. This stands a real hub up,
 * connects real websocket clients to it, and checks that the door behaves — the
 * difference being that a signature check can be perfect while the room admits
 * everybody anyway, and only one of those two things is what actually keeps
 * strangers out.
 *
 * Every ticket here is minted by the venue in PHP. Nothing is faked but the
 * room's contents, because the room has none yet.
 */

import { mint } from './application.ts'
import { Client } from 'colyseus.js'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { listen } from '@colyseus/tools'
import { hub, typeNameFor } from '../src/mod.ts'
import { VenueRoom } from '../src/room.ts'

const here = dirname(fileURLToPath(import.meta.url))
const PORT = 2599
const EXPERIENCE = 'com.streetmesh.games.chess'

let failures = 0

const check = (what: string, passed: boolean, detail = '') => {
  failures += passed ? 0 : 1
  console.log(`  ${passed ? '✓' : '✗'} ${what.padEnd(46)} ${detail}`)
}

/** And one for a room already open, so two people can share a table. */
function mintFor(room: string): { ticket: string; room: string } {
  return mint(room, 'black')
}

/** A room with no rules at all, which is all the door needs to be tested. */
class Empty extends VenueRoom {}

/*
 * Stood up exactly the way a deployed one is — the same `hub()` the venue's
 * generated config calls. A door checked in a server assembled differently from
 * the real one is a door nobody has checked.
 */
const server = await listen(hub([{ name: EXPERIENCE, room: Empty }]), PORT)

const client = new Client(`ws://127.0.0.1:${PORT}`)

console.log()

const minted = mint()

/*
 * The venue's room name travels with the join. Colyseus generates its own room
 * id, which means nothing to anybody else — so the name the ticket was minted
 * against has to be carried and held by the room.
 */
const type = typeNameFor(EXPERIENCE)

const seated = await client
  .joinOrCreate(type, { ticket: minted.ticket, room: minted.room })
  .then(
    (room) => room,
    (refused) => {
      check('a real ticket opens a room', false, String(refused?.message ?? refused))

      return null
    },
  )

if (seated) {
  check('a real ticket opens a room', true, seated.roomId)
  check('and the room knows who sat down', seated.sessionId !== '', seated.sessionId)
}

const refuse = async (what: string, options: Record<string, unknown>) => {
  try {
    const room = await client.joinOrCreate(type, { room: minted.room, ...options })
    await room.leave()
    check(what, false, 'it was let in')
  } catch (refused) {
    check(what, true, String((refused as Error).message).slice(0, 60))
  }
}

await refuse('no ticket, no seat', {})
await refuse('a made-up ticket is refused', { ticket: 'not.a.ticket' })
await refuse('an expired ticket is refused', { ticket: minted.expired })

/*
 * A ticket names one room. Presenting a good one at a different door is the
 * failure that matters most here, because it is the one a signature check alone
 * would never catch.
 */
await refuse('a ticket for another room opens nothing', {
  ticket: minted.ticket,
  room: `${EXPERIENCE}/somewhere-else`,
})

/*
 * Two people in one room, each seeing the other.
 *
 * This is what filtering on the venue's room name is for, and it is worth
 * checking rather than assuming: without it Colyseus opens a fresh room per
 * client and two players sit alone in separate games while everything appears
 * to work.
 */
const second = mint()
const secondRoom = await client.joinOrCreate(typeNameFor(EXPERIENCE), {
  ticket: second.ticket,
  room: second.room,
})

check('somebody else can take another room', secondRoom.roomId !== seated?.roomId, 'a different table')

const third = mintFor(minted.room)
const alongside = await client.joinOrCreate(typeNameFor(EXPERIENCE), {
  ticket: third.ticket,
  room: minted.room,
})

check('two tickets for one table reach one room', alongside.roomId === seated?.roomId, alongside.roomId)

// Presence is state, so it arrives rather than being asked for.
await new Promise((settle) => setTimeout(settle, 200))

const present = [...alongside.state.occupants.values()].map((o: { name: string }) => o.name)

check(
  'and each of them can see who else is there',
  present.length === 2,
  present.join(', ') || 'nobody',
)

await alongside.leave()
await secondRoom.leave()

if (seated) {
  await seated.leave()
}

/*
 * Rooms are told before the socket goes, so clients see a room closing rather
 * than a connection dropping. The two look identical to a browser and mean very
 * different things to somebody mid-game.
 */
await server.gracefullyShutdown(false)

console.log()
console.log(
  failures === 0
    ? '  The door only opens for what the venue signed.\n'
    : `  ${failures} step(s) did not work.\n`,
)

process.exit(failures === 0 ? 0 : 1)
