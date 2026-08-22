/**
 * What a venue may ask a hub about its own rooms.
 *
 * Two questions, and the venue has to know the name of the table to ask either.
 * That name came from the venue in the first place — it minted the ticket — so
 * nothing here is discoverable by somebody who did not already have it. There is
 * deliberately no endpoint that lists anything: the prototype had one, and it
 * published every live table to whoever asked.
 */

import type { Express, Request, Response } from 'express'

/**
 * Every room currently open, by the venue's name for it.
 *
 * The hub holds this because Colyseus does not: its own registry is keyed on
 * the room id it invented, and the only name the venue knows is the one it put
 * in the ticket. Kept here rather than in room metadata, which Colyseus
 * publishes in a listing — a table's occupants are nobody else's business.
 *
 * One process. A hub spread across several would need this somewhere shared,
 * and would need to say so rather than quietly answer "no such room" for half
 * of them.
 */
const open = new Map<string, Resultful>()

/**
 * Only what this file needs of a room, so that rooms may import from here
 * without this having to import them back.
 */
type Resultful = {
  result(): Record<string, unknown> | null
  present(): Array<{ name: string; seat: string }>
}

export function remember(room: Resultful, name: string): void {
  open.set(name, room)
}

export function forget(name: string): void {
  open.delete(name)
}

/**
 * This process, distinct from any other serving the same name.
 *
 * A hub keeps its rooms in memory, so two processes behind one address are two
 * hubs pretending to be one: people who joined the same table land in different
 * rooms and cannot see each other. It is invisible from outside — every process
 * answers every question plausibly — so this is here to make it countable. Ask
 * for the build a few times; more than one answer means more than one hub.
 */
const process_id = Math.random().toString(36).slice(2, 8)

/**
 * Which build this hub is running.
 *
 * The venue generates the hub from what it has installed and knows the
 * fingerprint of what it generated. Being able to ask a running hub the same
 * question is what lets a deploy skip when nothing changed — and a hub restart
 * ends every game in progress, so skipping is not a nicety.
 *
 * Unknown rather than absent when nothing set it. A hub started by hand is a
 * legitimate thing to be; it simply cannot answer this.
 */
function answerBuild(_request: Request, response: Response): void {
  response.json({ build: process.env.HUB_BUILD ?? 'unknown', process: process_id })
}

/**
 * How a game ends up in somebody's own records.
 *
 * The venue asks; the hub answers only for a room that is over. The venue signs
 * what comes back, which is why this is the one thing here worth being careful
 * about: it is the hub's only influence on what gets written into a person's
 * repository, and it cannot sign anything itself.
 */
function answerResult(request: Request, response: Response): void {
  const room = open.get(String(request.query.room ?? ''))
  const result = room?.result() ?? null

  if (result === null) {
    // No such table, or one still being played. Deliberately the same answer:
    // whether a game exists is not a question this should help anybody explore.
    response.status(404).json({})

    return
  }

  response.json(result)
}

/**
 * Who is actually at a table right now.
 *
 * The venue knows who sat down; only this knows who is still sitting there. A
 * seat survives somebody closing the tab — it has to, or their opponent could
 * take their chair while they reconnected — so a venue counting seats is
 * counting a history rather than a room.
 *
 * Asked about named rooms and never listing them.
 */
function answerPresence(request: Request, response: Response): void {
  const asked = request.query.room
  const names = Array.isArray(asked) ? asked.map(String) : asked === undefined ? [] : [String(asked)]
  const present: Record<string, Array<{ name: string; seat: string }>> = {}

  for (const name of names) {
    // Absent rather than empty for a room that is not open, so "nobody is
    // there" and "there is no room" stay different answers.
    const room = open.get(name)

    if (room) {
      present[name] = room.present()
    }
  }

  response.json(present)
}

/**
 * Bound onto the application the hub is already serving.
 *
 * Ordinary routes on an ordinary Express app, rather than a second HTTP server
 * of our own. Being bespoke here bought nothing and cost the ability to change
 * transport: WebTransport wants the Express application handed to it, and a
 * bare `http.Server` is not one.
 */
export function routes(app: Express): void {
  app.get('/build', answerBuild)
  app.get('/result', answerResult)
  app.get('/present', answerPresence)
}
