<?php

namespace StreetMesh\Server\Venue\Experiences;

use StreetMesh\Server\Venue\Gatherings\Gathering;

/**
 * Something a venue hosts.
 *
 * Not a capability, and the distinction is the one this interface exists to
 * make. A capability answers "what kind of server is this" — a domicile, a
 * venue, both — and says so on the wire, in a DID document a stranger reads
 * before deciding to come. An experience answers "what can I do here", which is
 * nobody's business until they have arrived and looked at the menu.
 *
 * Chess was written as a capability first, and it showed: two of the four
 * methods it had to implement returned empty strings, because it had no service
 * type to announce and no front page to greet anybody with. A class that can
 * only half-satisfy an interface is usually implementing the wrong one.
 */
interface Experience
{
    /**
     * The NSID, which is three things at once.
     *
     * It names the collection its records go in, the room type its hub serves,
     * and the experience itself. One name, because they are one thing seen from
     * three sides — and reverse-domain, so two experiences by different authors
     * cannot collide without somebody doing it deliberately.
     */
    public function name(): string;

    /** What it is called on the menu. */
    public function title(): string;

    /**
     * One sentence, for somebody deciding whether to go in.
     */
    public function description(): string;

    /** A Flux icon name, for the gallery. */
    public function icon(): string;

    /** The route its own screen lives at. */
    public function route(): string;

    /**
     * What the button into it says, or null for the venue's own word.
     *
     * "Launch" fits most things and fits nothing well. An experience knows
     * whether people play it, watch it, browse it or bid at it, and a menu
     * reads better when each entry says what it actually is.
     */
    public function action(): ?string;

    /**
     * A second way in, for somebody who only wants to look.
     *
     * The primary one asks something of people: `route()` is where you go to
     * take part, and taking part means arriving with a name another server
     * issued. That is the right toll for playing and much too high for
     * watching — a stranger who follows a link to see what is on should not
     * meet a form asking them to name their own server first.
     *
     * So an experience with things worth watching says where to look. The route
     * named here is expected to be readable by anybody; nothing enforces that,
     * because an experience that put its own door in front of it would only be
     * describing a second front entrance, which is its business.
     *
     * Null is the ordinary answer. Most things are not a spectator sport, and
     * one way in is enough.
     *
     * @return array{label: string, route: string}|null
     */
    public function watching(): ?array;

    /**
     * Who may watch this one while it is happening.
     *
     * Asked per gathering rather than per experience, because privacy is not a
     * property of the kind of thing: two games at the same venue may reasonably
     * differ, and an experience that grows a setting for it has somewhere to
     * put the answer.
     *
     * Required, with no default anywhere. A venue that assumed one would be
     * deciding on somebody else's behalf how visible their gathering is, and
     * the safe assumption and the useful one point in opposite directions — so
     * an experience has to say. Saying so is one line.
     *
     * This governs looking, never doing. Taking a chair is a different question
     * with a different answer, asked somewhere else.
     */
    public function audience(Gathering $gathering): Audience;

    /**
     * What a visitor has to agree to before this can do its job.
     *
     * Declared here rather than configured, so that installing an experience
     * asks for what it needs. A venue whose configuration and installed
     * packages disagreed would take somebody through a consent screen and then
     * fail to write the record it had just promised them.
     *
     * @return array<int, string> ATProtocol scope strings
     */
    public function scopes(): array;

    /**
     * Where this experience's room lives, or null if nothing about it is live.
     *
     * An absolute path to a directory holding a Node package whose entry point
     * default-exports `{ name, room }`. The venue copies it into the hub it
     * builds; it never imports it, and no PHP here runs a line of what is
     * inside.
     *
     * Declared rather than discovered, for the same reason scopes are. Going
     * looking would mean assuming a directory layout that belongs to somebody
     * else's package — an objection the hub's own `discover.ts` already makes
     * about itself.
     *
     * Null is an ordinary answer. A gallery or a reading room is a perfectly
     * good experience with nobody to keep in step.
     */
    public function room(): ?string;
}
