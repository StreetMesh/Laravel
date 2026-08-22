/**
 * Check that a ticket minted by the venue is one this half will accept.
 *
 *   php ../bin/mint-ticket.php | node --experimental-strip-types bin/check-ticket.ts
 *
 * This is the seam most likely to be wrong, and least likely to be caught by
 * either side's own tests: PHP signs, TypeScript verifies, and everything
 * between them is a format two languages have to agree on exactly — base58 for
 * the key, a multicodec prefix, a compressed curve point that has to have its y
 * coordinate solved for, base64url without padding, and an ECDSA signature as a
 * raw r‖s pair rather than the DER most libraries hand you.
 *
 * A test written in TypeScript that minted its own tickets would pass with all
 * of that wrong.
 *
 * It reads the venue's real DID document over the network, so it also checks
 * that what the venue publishes is what it signs with.
 */

import { verifyTicket } from '../src/ticket.ts'

const input = await new Promise<string>((resolve) => {
  let read = ''
  process.stdin.on('data', (chunk) => (read += chunk))
  process.stdin.on('end', () => resolve(read))
})

const minted = JSON.parse(input) as {
  ticket: string
  expired: string
  room: string
  expect: Record<string, string>
}

let failures = 0

const check = (what: string, passed: boolean, detail = '') => {
  failures += passed ? 0 : 1
  console.log(`  ${passed ? '✓' : '✗'} ${what.padEnd(46)} ${detail}`)
}

console.log()

try {
  const ticket = await verifyTicket(minted.ticket, minted.room)

  check('a ticket PHP signed verifies here', true, ticket.issuer)
  check('it seats who the venue said', ticket.subject === minted.expect.sub, ticket.subject)
  check('in the seat the venue said', ticket.seat === minted.expect.seat, ticket.seat || '(none)')
  check('under the name the venue gave them', ticket.name === minted.expect.name, ticket.name)
} catch (refused) {
  check('a ticket PHP signed verifies here', false, (refused as Error).message)
}

/*
 * The refusals matter more than the acceptance. A ticket is the only thing
 * standing between a stranger and a seat, so each of these is a way in if it
 * does not hold.
 */
const refuse = async (what: string, compact: string, room: string) => {
  try {
    await verifyTicket(compact, room)
    check(what, false, 'it was accepted')
  } catch (refused) {
    check(what, true, (refused as Error).message)
  }
}

await refuse('a ticket for another room opens nothing', minted.ticket, 'some-other-room')

const [header, payload, signature] = minted.ticket.split('.')

const altered = JSON.parse(Buffer.from(payload, 'base64url').toString())
altered.seat = 'black'

await refuse(
  'a ticket whose seat was edited is refused',
  `${header}.${Buffer.from(JSON.stringify(altered)).toString('base64url')}.${signature}`,
  minted.room,
)

/*
 * Genuinely signed and genuinely out of date. Editing a good ticket's expiry
 * would break its signature and be refused for that instead — which reads as a
 * passing test while the expiry check never runs.
 */
await refuse('a ticket that has run out is refused', minted.expired, minted.room)

console.log()
console.log(
  failures === 0
    ? '  Two languages agree about what a venue signed.\n'
    : `  ${failures} disagreement(s).\n`,
)

process.exit(failures === 0 ? 0 : 1)
