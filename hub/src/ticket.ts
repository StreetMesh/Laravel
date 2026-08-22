/**
 * A permission slip to sit somewhere.
 *
 * The venue has already done everything hard: resolved a federated address,
 * checked a delegation, decided who sits in which seat. This half can do none
 * of that and never needs to. It receives an assertion signed with the venue's
 * own key — the same key already published in its DID document — and has only
 * to check a signature.
 *
 * That is why there is no shared secret on the join path, and why this process
 * holds no credential of any kind. It cannot impersonate the venue, cannot be
 * stolen from usefully, and cannot assert anything back: everything it knows
 * arrived signed by somebody else.
 */

import { importMultikey } from './multikey.ts'

export interface Ticket {
  /** The venue that issued it, as a DID. */
  issuer: string
  /** Who is sitting down, as a DID. */
  subject: string
  /** What to call them on screen. The venue's word, not theirs. */
  name: string
  /** Which room, and which seat in it. */
  room: string
  seat: string
  /**
   * Every seat the venue has filled, including this one.
   *
   * The venue's record rather than this room's — somebody who has closed their
   * tab still holds their chair. Nothing here can work this out: a room sees
   * connections, and a connection is not a seat.
   */
  taken: string[]
  /**
   * The room name of the party this person is here with, or empty.
   *
   * The venue's word, like everything else on a ticket. A browser that could
   * say which party it was in would be a browser that could put itself in
   * somebody else's.
   */
  party: string
}

interface Cached {
  keys: Map<string, CryptoKey>
  until: number
}

/**
 * Documents are cached briefly, because a key rotation must actually take
 * effect. Long enough that a room filling up does not re-fetch per person;
 * short enough that a retired key stops working in minutes rather than at the
 * next restart.
 */
const DOCUMENT_SECONDS = 300

const documents = new Map<string, Cached>()

/**
 * `did:web` only, and that is a deliberate limit for now.
 *
 * The identifier *is* the address, so resolving it needs nothing but a fetch.
 * `did:plc` would mean talking to a directory, which is a dependency this
 * process does not need in order to check that a venue signed something —
 * venues are servers with hostnames, which is exactly what `did:web` is for.
 */
function documentUrl(did: string): string {
  if (!did.startsWith('did:web:')) {
    throw new Error(`[${did}] is not a did:web identifier, which is all this reads.`)
  }

  const [host, ...path] = did.slice('did:web:'.length).split(':').map(decodeURIComponent)

  return path.length === 0
    ? `https://${host}/.well-known/did.json`
    : `https://${host}/${path.join('/')}/did.json`
}

async function keysFor(did: string, now: number): Promise<Map<string, CryptoKey>> {
  const held = documents.get(did)

  if (held && held.until > now) {
    return held.keys
  }

  const response = await fetch(documentUrl(did))

  if (!response.ok) {
    throw new Error(`[${did}] published no document we could read.`)
  }

  const document = (await response.json()) as {
    id?: string
    verificationMethod?: { id?: string; publicKeyMultibase?: string }[]
  }

  /*
   * A document reached at the right address that names somebody else is not
   * that identity's document, however it was reached.
   */
  if (document.id !== did) {
    throw new Error(`The document at ${documentUrl(did)} claims to be [${document.id}].`)
  }

  const keys = new Map<string, CryptoKey>()

  for (const method of document.verificationMethod ?? []) {
    if (method.id && method.publicKeyMultibase) {
      keys.set(method.id, await importMultikey(method.publicKeyMultibase))
    }
  }

  documents.set(did, { keys, until: now + DOCUMENT_SECONDS * 1000 })

  return keys
}

/**
 * One segment of a compact JWS, read as JSON.
 *
 * Anything at all can arrive at this door, so a segment that is not base64url
 * or not JSON is an ordinary refusal rather than something to throw a parser
 * error about. Letting one escape would report "unexpected token" to somebody
 * whose actual problem is that they have no ticket.
 */
function readSegment(segment: string, called: string): unknown {
  let decoded: string

  try {
    decoded = Buffer.from(segment, 'base64url').toString()
  } catch {
    throw new Error(`That ticket's ${called} is not base64url.`)
  }

  try {
    const parsed: unknown = JSON.parse(decoded)

    if (parsed === null || typeof parsed !== 'object') {
      throw new Error('not an object')
    }

    return parsed
  } catch {
    throw new Error(`That ticket's ${called} is not readable.`)
  }
}

/**
 * Check a ticket, and say who it seats.
 *
 * @param expectedRoom the room actually being joined, which is not the same as
 *                     the room the ticket names until it has been compared
 */
export async function verifyTicket(
  compact: string,
  expectedRoom: string,
  now: number = Date.now(),
): Promise<Ticket> {
  const [header, payload, signature] = compact.split('.')

  if (!header || !payload || !signature) {
    throw new Error('That is not a compact JWS.')
  }

  const head = readSegment(header, 'header') as { alg?: string; kid?: string }

  /*
   * Pinned to what we are willing to check, never to what the document asks
   * for. Accepting an algorithm named in an unverified header is the classic
   * JOSE footgun — it lets a document choose how it will be checked.
   */
  if (head.alg !== 'ES256') {
    throw new Error(`That ticket is signed with [${head.alg}], which is not accepted here.`)
  }

  if (!head.kid) {
    throw new Error('That ticket names no key.')
  }

  const issuer = head.kid.split('#')[0]
  const keys = await keysFor(issuer, now)
  const key = keys.get(head.kid)

  if (!key) {
    throw new Error(`[${issuer}] does not publish a key called [${head.kid}].`)
  }

  const verified = await crypto.subtle.verify(
    { name: 'ECDSA', hash: 'SHA-256' },
    key,
    Buffer.from(signature, 'base64url'),
    Buffer.from(`${header}.${payload}`),
  )

  if (!verified) {
    throw new Error('That ticket does not verify against the key it names.')
  }

  const claims = readSegment(payload, 'claims') as Record<string, unknown>

  if (typeof claims.exp !== 'number' || claims.exp * 1000 < now) {
    throw new Error('That ticket has expired.')
  }

  /*
   * The room is compared rather than trusted. A ticket is issued for one place,
   * and without this a ticket for a table anybody may sit at would open every
   * room on the server.
   */
  if (claims.room !== expectedRoom) {
    throw new Error(`That ticket is for [${String(claims.room)}], not [${expectedRoom}].`)
  }

  if (typeof claims.sub !== 'string' || claims.sub === '') {
    throw new Error('That ticket seats nobody.')
  }

  return {
    issuer,
    subject: claims.sub,
    name: typeof claims.name === 'string' ? claims.name : claims.sub,
    room: expectedRoom,
    seat: typeof claims.seat === 'string' ? claims.seat : '',

    /*
     * Absent on a ticket signed before venues said this, which is an empty
     * roster rather than a refusal — an older venue is not a broken one.
     */
    taken: Array.isArray(claims.taken) ? claims.taken.filter((seat) => typeof seat === 'string') : [],

    /*
     * Absent on a ticket from a venue that does not do parties, which is
     * nobody's party rather than a refusal — the same reading `taken` gets.
     */
    party: typeof claims.party === 'string' ? claims.party : '',
  }
}
