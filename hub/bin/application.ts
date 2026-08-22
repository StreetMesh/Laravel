/**
 * Where the StreetMesh server these checks run against lives.
 *
 * Every one of them needs a real ticket, and only the application can mint one
 * — it holds the identity and the key that a ticket is signed with, which is
 * the whole reason checking one here proves anything.
 *
 * This library used to sit at the repository root, one directory from `bin/`,
 * so four files each wrote `../../bin/mint-ticket.php` and were each correct.
 * It lives inside the package now, which in an installed server is
 * `vendor/streetmesh/laravel/hub` — a different distance from the application
 * every time, and not knowable from here.
 *
 * So it is looked for rather than counted to: walk up until something is a
 * Laravel application. One answer, in one place, instead of four copies of an
 * arithmetic that was silently wrong the moment anything moved.
 */

import { execFileSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

export function applicationRoot(from: string = dirname(fileURLToPath(import.meta.url))): string {
  let at = from

  while (at !== dirname(at)) {
    if (existsSync(join(at, 'artisan'))) {
      return at
    }

    at = dirname(at)
  }

  throw new Error(
    `No StreetMesh server above ${from}. These checks need one they can boot — ` +
      'see the README.',
  )
}

/**
 * One real ticket, signed by the venue.
 *
 * Arguments are passed through as the minting script takes them: room, seat,
 * subject, party — each optional, each defaulting to something usable.
 */
export function mint(...args: string[]): { ticket: string; room: string; expect: Record<string, string> } {
  return JSON.parse(
    execFileSync('php', [join(applicationRoot(), 'bin/mint-ticket.php'), ...args], {
      encoding: 'utf8',
    }),
  )
}
