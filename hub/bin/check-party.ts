/**
 * Check that a table can see who is in a party.
 *
 *   ./check-party
 *
 * A party is not a room here any more and the hub knows nothing about how one
 * works — who is in it, and the handshake that gets them talking, are the
 * venue's, carried over ordinary HTTP. What is left for this process is the one
 * thing that has to be true in a *room*: that a table can see somebody is in a
 * party.
 *
 * That is the whole of what stops a party being a hidden channel. Somebody in
 * one has their voice superseded by it, which leaves them present at the table
 * and unhearable there; a table that could not show it would be a table where
 * people talk to a wall and cannot tell why. A venue that allows a private
 * channel has not thereby decided to hide it.
 *
 * The claim rides on the ticket the venue already signs for the table, so none
 * of this depends on a party being anything the hub can reach.
 *
 * Every ticket here is minted by the venue in PHP, exactly as check-join's are.
 */

import { mint } from './application.ts'
import { Client } from 'colyseus.js'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { listen } from '@colyseus/tools'
import { hub, typeNameFor } from '../src/mod.ts'
import { VenueRoom } from '../src/room.ts'

const here = dirname(fileURLToPath(import.meta.url))
const PORT = 2598
const PARTY = 'com.streetmesh.party'
const EXPERIENCE = 'com.streetmesh.games.chess'

let failures = 0

const check = (what: string, passed: boolean, detail = '') => {
  failures += passed ? 0 : 1
  console.log(`  ${passed ? '✓' : '✗'} ${what.padEnd(52)} ${detail}`)
}

/**
 * One real ticket, signed by the venue.
 *
 * `who` matters here: a room treats a second arrival under one subject as the
 * same person coming back, so two people minted under one name would be one
 * person throwing themselves out.
 */
function ticketFor(room: string, seat: string, who: string, party = ''): { ticket: string } {
  return mint(room, seat, who, party)
}

/** A table with no rules, which is all that is needed to look at its roster. */
class Table extends VenueRoom {}

const server = await listen(hub([{ name: EXPERIENCE, room: Table }]), PORT)

const client = new Client(`ws://127.0.0.1:${PORT}`)

console.log()

const partyRoom = `${PARTY}/01JQPARTY0000000000000000`

/*
 * There is no room to join by that name, and that is the change being guarded.
 *
 * A hub that still served one would mean the venue and this process disagreed
 * about who answers for a party — which is how it was, and how a moment of bad
 * signal became everybody rebuilding every connection they had.
 */
try {
  await client.joinOrCreate(typeNameFor(PARTY), {
    ticket: ticketFor(partyRoom, '', 'alice', partyRoom).ticket,
    room: partyRoom,
  })

  check('a party is not a room in the hub', false, 'something answered for one')
} catch (refused) {
  check('a party is not a room in the hub', true, String((refused as Error)?.message ?? refused))
}

/*
 * And now the part that matters. A table, with somebody at it who is in a
 * party, and everybody else at that table able to see so.
 */
const tableRoom = `${EXPERIENCE}/01JQTABLE000000000000000`

const table = await client.joinOrCreate(typeNameFor(EXPERIENCE), {
  ticket: ticketFor(tableRoom, 'white', 'alice', partyRoom).ticket,
  room: tableRoom,
})

const alongside = await client.joinOrCreate(typeNameFor(EXPERIENCE), {
  ticket: ticketFor(tableRoom, 'black', 'carol', '').ticket,
  room: tableRoom,
})

await new Promise((settle) => setTimeout(settle, 200))

const atTheTable = [...alongside.state.occupants.values()] as Array<{ name: string; party: string }>

check(
  'a table shows that somebody is in a party',
  atTheTable.some((occupant) => occupant.party === partyRoom),
  atTheTable.map((o) => `${o.name}:${o.party || 'alone'}`).join(', '),
)

check(
  'and shows that somebody else is not',
  atTheTable.some((occupant) => occupant.party === ''),
  'nobody is quietly in one',
)

await alongside.leave()
await table.leave()

await server.gracefullyShutdown(false)

console.log()
console.log(
  failures === 0
    ? '  A table can see who is in a party.'
    : `  ${failures} of these did not hold.`,
)
console.log()

process.exit(failures === 0 ? 0 : 1)
