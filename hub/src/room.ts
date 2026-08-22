/**
 * A room nobody enters without a ticket.
 *
 * This is the base every experience builds on, and the only thing it knows how
 * to do is check that a venue said somebody may sit down. It knows nothing
 * about chess, or watch parties, or auctions — and nothing about federation
 * either, which is the point: the venue resolved the address and checked the
 * delegation, and all that arrives here is a signature.
 *
 * What an experience adds is rules. What it never has to add is any of this.
 */

import { Room, type Client } from '@colyseus/core'
import { announce } from './announce.ts'
import { forget, remember } from './answers.ts'
import { verifyTicket, type Ticket } from './ticket.ts'
import { Occupancy, Occupant, type OccupancyType } from './presence.ts'

export interface Seated {
  ticket: Ticket
}

/**
 * Which room, in the venue's words.
 *
 * Not the same as Colyseus's `roomId`, which is generated here and means
 * nothing to anybody else. The venue named this room when it minted the ticket,
 * and that name is what the ticket is checked against — so it has to travel
 * with the join and be held by the room.
 */
export interface JoinOptions {
  ticket?: string
  room?: string
}

/**
 * What the room did, on one line each.
 *
 * A hub is the one part of this nobody can attach a debugger to: it runs
 * somewhere else, and the questions it can be asked from outside are
 * deliberately narrow. When two people who joined the same table cannot see
 * each other, the thing worth knowing is whether they were ever in the same
 * room — and the room is the only thing that knows.
 *
 * The Colyseus id as well as the venue's name for it, because the whole class
 * of bug here is two rooms wearing one name.
 */
function note(what: string, room: { roomId: string; venueName: string; clients: unknown[] }): void {
  console.log(`[room] ${what} ${room.venueName} as ${room.roomId} (${room.clients.length} here)`)
}

export abstract class VenueRoom<State extends OccupancyType = OccupancyType> extends Room<State> {
  /**
   * How long a table stays up with nobody at it.
   *
   * Colyseus disposes a room the moment it empties, which is right for a lobby
   * and wrong for a game. A browser drops a socket for all sorts of reasons
   * that are not somebody leaving — a reload, a navigation, a laptop lid, a
   * connection that blinked — and if the table dies in that gap it takes the
   * game with it. A new room then opens under the same name, so the venue and
   * everybody else see a table that is fine and a game that never happened.
   *
   * Long enough to cover a reload and a bad minute of signal. Not so long that
   * an abandoned table hangs around: the venue is told when this expires, and
   * that is what settles a game nobody came back to.
   */
  private static readonly EMPTY_FOR_SECONDS = 120

  private emptying: ReturnType<typeof setTimeout> | null = null

  protected readonly seats = new Map<string, Ticket>()

  /**
   * Which seats the venue has filled, which is not the same as who is here.
   *
   * `seats` above is this room's own view, and it empties as people disconnect.
   * This is the venue's, and it is the one an experience wants whenever the
   * question is "are there two players" rather than "who can see this now".
   *
   * Added to and never subtracted from. Every ticket carries the venue's roster
   * as it stood when it was signed, so the union of the tickets this room has
   * seen is the fullest account it has — and a watcher's ticket, which names no
   * seats at all, must not be able to empty a table.
   */
  protected readonly taken = new Set<string>()

  /** The venue's name for this room, which every ticket must agree with. */
  protected venueRoom = ''

  /**
   * The venue that signed the ticket that opened this room.
   *
   * Kept so the room can call back without being configured with an address —
   * it arrives with the authority that opened the room, which means a hub
   * serving several venues cannot be talked into telling the wrong one.
   */
  private issuer = ''

  onCreate(options: JoinOptions): void {
    if (typeof options?.room !== 'string' || options.room === '') {
      throw new Error('A room has to be created under the name the venue gave it.')
    }

    this.venueRoom = options.room

    /*
     * Ours to close, not Colyseus's. See EMPTY_FOR_SECONDS.
     */
    this.autoDispose = false

    /*
     * An experience that wants state of its own sets it in `opened` and calls
     * this first, or extends Occupancy. Either way presence is there before
     * anybody can join, because the first join happens immediately after this
     * returns.
     */
    if (!this.state) {
      this.state = new Occupancy() as State
    }

    this.opened(options)

    /*
     * Findable by the name the venue knows it by. Colyseus keys rooms on an id
     * it invented, and the venue only ever knew the name it put in the ticket —
     * without this, a venue asking how a game ended has nothing to ask about.
     */
    remember(this, this.venueRoom)

    note('opened', { roomId: this.roomId, venueName: this.venueRoom, clients: this.clients })
  }

  onDispose(): void {
    if (this.emptying !== null) {
      clearTimeout(this.emptying)
      this.emptying = null
    }

    note('disposed', { roomId: this.roomId, venueName: this.venueRoom, clients: this.clients })

    forget(this.venueRoom)

    /*
     * The last word. A room is memory, and after this there is nobody left to
     * ask — a game that ended once both players had closed their tabs would
     * otherwise be a result nobody ever hears, and a table nobody is at would
     * go on being counted until the venue's own cache gave up.
     */
    this.tell()
  }

  /**
   * Tell the venue what this room looks like now.
   *
   * Called here whenever somebody arrives or leaves. An experience whose state
   * changes in a way the venue needs to know about — a game ending, which
   * nobody is leaving over — calls it too.
   *
   * Not awaited anywhere. The people in this room are playing a game and none
   * of them are waiting on the venue hearing about it, so a venue that is slow
   * or down must not hold anything up.
   */
  protected tell(): void {
    void announce(this.issuer, process.env.STREETMESH_REALTIME_SECRET ?? '', {
      room: this.venueRoom,
      occupants: this.present(),
      result: this.result(),
    })
  }

  /**
   * Called before a client is admitted, and a throw here is a refusal rather
   * than an error — which is why everything deciding whether somebody may be
   * here belongs in it rather than in `onJoin`.
   */
  async onAuth(client: Client, options: JoinOptions): Promise<Seated> {
    if (typeof options?.ticket !== 'string' || options.ticket === '') {
      throw new Error('A seat here needs a ticket from the venue.')
    }

    /*
     * Compared against the name this room was opened under, never against the
     * name in the ticket itself. A ticket that vouched for its own room would
     * open any room it was pointed at.
     */
    const ticket = await verifyTicket(options.ticket, this.venueRoom)

    /*
     * One seat, one occupant — but the occupant is a person, not a connection.
     *
     * A second connection from the same person takes the chair over rather than
     * being refused. Refusing was wrong in every case it actually came up: a
     * tab that crashed, a laptop that slept, a browser that navigated without
     * unloading the page. In all of them the old socket is still holding a seat
     * nobody is sitting in, and the person it belongs to cannot get back to
     * their own game until it times out.
     *
     * Two connections *are* still one occupant. The old one is shown the door
     * here, so the two can never disagree about the room.
     */
    this.replace(ticket.subject)

    return { ticket }
  }

  /**
   * Show somebody's earlier connection the door, if they have one.
   *
   * Cleared here as well as in `onLeave`, rather than waiting for it: the new
   * client is admitted immediately after this returns, and a seat still listed
   * under the old session would be a room that briefly holds the same person
   * twice.
   */
  private replace(subject: string): void {
    for (const [sessionId, seated] of this.seats) {
      if (seated.subject !== subject) {
        continue
      }

      this.seats.delete(sessionId)
      this.state.occupants.delete(sessionId)

      // 4103: taken over from somewhere else. A code rather than a silent
      // close, so the older screen can say so instead of looking broken.
      this.clients.find((client) => client.sessionId === sessionId)?.leave(4103)
    }
  }

  onJoin(client: Client, options: JoinOptions, auth: Seated): void {
    this.seats.set(client.sessionId, auth.ticket)
    this.issuer ||= auth.ticket.issuer

    for (const seat of auth.ticket.taken) {
      this.taken.add(seat)
    }

    this.state.occupants.set(
      client.sessionId,
      new Occupant({
        did: auth.ticket.subject,
        name: auth.ticket.name,
        seat: auth.ticket.seat,
        party: auth.ticket.party,
      }),
    )

    this.seated(client, auth.ticket)

    /*
     * Somebody is here, so the table is not closing. This is the ordinary case
     * after a reload: the same person, seconds later, and nothing was lost.
     */
    if (this.emptying !== null) {
      clearTimeout(this.emptying)
      this.emptying = null
    }

    note(`joined ${auth.ticket.name} (${auth.ticket.seat})`, {
      roomId: this.roomId,
      venueName: this.venueRoom,
      clients: this.clients,
    })

    this.tell()
  }

  /**
   * What this room will say happened, once it is over.
   *
   * Null while there is still a game on, and null forever in a room that has
   * no notion of an ending. A venue asks for this to write somebody a record,
   * so answering early would be the venue signing a result that had not
   * happened yet.
   *
   * Deliberately plain data. The venue signs whatever comes back, so it must
   * be something it can hand to a repository unchanged.
   */
  result(): Record<string, unknown> | null {
    return null
  }

  /**
   * Who is connected right now, and what they are sitting in.
   *
   * Not who has a seat — that is the venue's record and outlives a dropped
   * connection on purpose. This is the room.
   */
  present(): Array<{ name: string; seat: string }> {
    return [...this.seats.values()].map((ticket) => ({
      name: ticket.name,
      seat: ticket.seat,
    }))
  }

  onLeave(client: Client): void {
    const ticket = this.seats.get(client.sessionId)

    this.seats.delete(client.sessionId)
    this.state.occupants.delete(client.sessionId)

    if (ticket) {
      this.left(client, ticket)
    }

    note(`left ${ticket?.name ?? 'somebody'}`, {
      roomId: this.roomId,
      venueName: this.venueRoom,
      clients: this.clients,
    })

    this.tell()

    if (this.clients.length === 0) {
      this.emptying = setTimeout(() => {
        note('closing, nobody came back', {
          roomId: this.roomId,
          venueName: this.venueRoom,
          clients: this.clients,
        })

        this.disconnect()
      }, VenueRoom.EMPTY_FOR_SECONDS * 1000)
    }
  }

  /** Who is here, as the venue vouched for them. */
  protected occupants(): Ticket[] {
    return [...this.seats.values()]
  }

  protected opened(options: JoinOptions): void {}

  protected seated(client: Client, ticket: Ticket): void {}

  protected left(client: Client, ticket: Ticket): void {}
}
