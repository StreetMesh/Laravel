/**
 * A table survives somebody's socket dropping.
 *
 * The bug this exists for: a client blinked, the room emptied, Colyseus threw
 * it away, and a new one opened under the same name. Everything looked fine and
 * the game was gone.
 */
import { mint } from './application.ts'
import { Client } from 'colyseus.js'
import { listen } from '@colyseus/tools'
import { hub } from '../src/mod.ts'
import { VenueRoom } from '../src/room.ts'

const PORT = 2591
const EXPERIENCE = 'com.streetmesh.games.chess'

class Empty extends VenueRoom {}
const server = await listen(hub([{ name: EXPERIENCE, room: Empty }]), PORT)

const client = new Client(`ws://127.0.0.1:${PORT}`)
const first = mint()

const a = await client.joinOrCreate(EXPERIENCE.replaceAll('.', '_'), { ticket: first.ticket, room: first.room })
const before = a.roomId

await a.leave()                                   // the blip
await new Promise((r) => setTimeout(r, 1500))     // longer than it took in the cloud

const again = mint(first.room, 'white')
const b = await client.joinOrCreate(EXPERIENCE.replaceAll('.', '_'), { ticket: again.ticket, room: again.room })

console.log()
console.log(`  room before the drop : ${before}`)
console.log(`  room after coming back: ${b.roomId}`)
console.log(`  ${before === b.roomId ? '✓ the same table' : '✗ a different table — the game would be gone'}`)
console.log()

await b.leave()
await server.gracefullyShutdown(false)
