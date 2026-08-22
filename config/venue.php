<?php

return [

    /*
     |--------------------------------------------------------------------------
     | What this venue asks visitors for
     |--------------------------------------------------------------------------
     |
     | Named in ATProtocol's own grammar, because a scope invented locally is a
     | word no other server on the network understands. `atproto` is always
     | included and does not need listing.
     |
     |   repo:com.streetmesh.games.chess?action=create
     |
     | Ask for the least that works. `action=create` says a venue may add
     | records and never alter or remove them, and a visitor reading that on
     | their own server's consent screen can see the difference.
     |
     | Empty means this venue asks only to confirm who somebody is — which is
     | the right setting until an experience is installed that writes something.
     |
     */

    'scopes' => [],

    /*
     |--------------------------------------------------------------------------
     | Where the realtime half is
     |--------------------------------------------------------------------------
     |
     | The address a browser opens a websocket to. Sent to the browser with its
     | ticket rather than written into a page, so that moving the realtime half
     | is an operator's decision rather than an edit to somebody's experience
     | templates.
     |
     | Null means this venue hosts nothing live, which is a perfectly good venue
     | — a shop needs no room.
     |
     */

    'hub' => env('STREETMESH_HUB'),

    /*
    |--------------------------------------------------------------------------
    | What a hub says to this venue with
    |--------------------------------------------------------------------------
    |
    | Everything else between the two is one-way and needs no secret: a ticket
    | is signed here and merely verified there, and a result is asked for
    | rather than announced. This is the one direction that cannot work that
    | way — a hub telling a venue something has to be a hub the venue can
    | recognise, and a hub holds no key of its own.
    |
    | The same value goes wherever the hub runs. Locally that is this file:
    | `./hub-serve` reads it from here so there is one copy rather than two
    | that have to agree.
    |
    | A comma-separated list is accepted, and that is how it is rotated: add
    | the new one, deploy both sides, take the old one off. Replacing a single
    | value in place means a moment where one side has changed and the other
    | has not, which is an outage.
    |
    | This venue will not serve requests without one, because a venue that
    | started anyway would look healthy and quietly never hear that a game had
    | ended.
    |
    */

    'secret' => env('STREETMESH_REALTIME_SECRET'),

    /*
     |--------------------------------------------------------------------------
     | Who may see what is on
     |--------------------------------------------------------------------------
     |
     | `anybody` — the menu is public. Somebody can look at what this venue
     | offers before deciding whether to hand over a name, which is how a venue
     | works in the world: a chess club posts its programme on the door.
     |
     | `visitors` — nothing is shown until somebody has arrived. For a venue
     | that is private about what it hosts, or whose menu is only meaningful to
     | people who are already members of something.
     |
     | Either way the experiences themselves are still behind the door. Seeing
     | that chess is on offer is not the same as sitting down at a table, and
     | somebody who clicks through from a public menu is sent to arrive and then
     | brought back to where they were going.
     |
     */

    'gallery' => env('STREETMESH_VENUE_GALLERY', 'anybody'),

    /*
     |--------------------------------------------------------------------------
     | Where to send somebody who has no address yet
     |--------------------------------------------------------------------------
     |
     | A venue cannot house anybody. Arriving here means holding a name that
     | some domicile issued, so a visitor who has never had one is standing at a
     | door they cannot open, and the only useful thing to tell them is where to
     | go and get one.
     |
     | Which domicile is the operator's call, and it is a recommendation rather
     | than a rule — an address from anywhere works. This is only the answer to
     | "I do not have one of those".
     |
     | A hostname, not a URL, because it is the same shape as the address being
     | asked for in the field above it.
     |
     | Null takes the offer off the screen, for a venue whose visitors already
     | live somewhere.
     |
     */

    'domicile' => env('STREETMESH_VENUE_DOMICILE', 'stme.sh'),

    /*
     |--------------------------------------------------------------------------
     | Whether people can be here together
     |--------------------------------------------------------------------------
     |
     | A party is a few people who arrived together and stay in earshot of each
     | other while they wander between the experiences this venue offers. It is
     | invite-only, it crosses everything installed, and its audio supersedes
     | whatever the room they are in offers — you cannot listen to two
     | conversations, so there is never a second one to hide in.
     |
     | On or off for the whole venue, and no experience is consulted. The
     | operator is the only party who knows what is installed here: an
     | experience author cannot know which venue their package lands in, and a
     | flag they set would let them switch on something this server's
     | infrastructure cannot carry.
     |
     | The cost of that is yours. A private voice channel between two people at
     | a competitive table is an earpiece, so a venue running anything with a
     | result worth cheating for should leave this off — and an experience that
     | says it is unsafe with parties will be named at boot rather than left for
     | somebody to discover during a rated game.
     |
     | `size` is capped at four however it is set. Media between people here is
     | peer-to-peer, every participant uploads a copy of their stream to every
     | other, and there is no relay to fall back on yet.
     |
     */

    'parties' => [

        'enabled' => env('STREETMESH_VENUE_PARTIES', false),

        'size' => env('STREETMESH_VENUE_PARTY_SIZE', 4),

    ],

    /*
     |--------------------------------------------------------------------------
     | Talking to the people around you
     |--------------------------------------------------------------------------
     |
     | One badge in the corner of every screen this venue serves, and everything
     | to do with talking behind it: the room you are in, the party you came
     | with, and your camera and microphone.
     |
     | It is drawn in iframes of its own rather than inline. A venue's screens
     | belong to whoever wrote the experience, and comms has to sit on top of
     | all of them without inheriting a stacking context, a stylesheet or a
     | reset it did not ask for — and without a re-render of somebody's game
     | taking the microphone with it.
     |
     | `assets` is what those documents load. They are this application's, named
     | here rather than hard-coded, so a host that builds its assets somewhere
     | else can say so without editing a package.
     |
     */

    'comms' => [

        'enabled' => env('STREETMESH_VENUE_COMMS', true),

        /** The badge is a circle of this many pixels. */
        'badge' => 60,

        /*
         | Breathing room around the circle inside its own frame.
         |
         | The badge's iframe is the circle plus this, with the circle centred,
         | so half of it separates the visible circle from the frame's edge. The
         | faces beside it are lifted by that same half or the row does not line
         | up — which is the whole reason this is one number in one place rather
         | than a 15 in the layout and a 7.5 in the stylesheet.
         |
         | Half of it also has to clear the drop shadow. An iframe is a box, and
         | a shadow reaching past its edge is a round badge casting a square
         | one — so this is sized from the shadow rather than chosen: 15 each
         | side against an offset of 4 and a blur of 10.
         |
         */

        'pad' => 30,

        /*
         | How far the visible circle sits from the corner of the screen.
         |
         | What the eye actually measures, so it is what is written down. Where
         | the frame goes is worked out from it — the frame is larger than the
         | circle, so pinning the frame instead means every change to `pad`
         | quietly moves the badge.
         |
         */

        'margin' => 40,

        /*
         | And how far from it on a phone.
         |
         | Closer, because the badge anchors everything to its left and a narrow
         | screen has no width to waste on a corner. Every millimetre here is a
         | millimetre a face can use.
         |
         */

        'margin_narrow' => 20,

        /** The popover, on a screen with room for one. */
        'width' => 380,
        'height' => 560,

        'assets' => ['resources/css/app.css', 'resources/js/app.js'],

        /*
         | The few colours the widget draws itself with.
         |
         | Here because two of its three documents load no stylesheet at all —
         | that is what keeps the badge from being the slowest thing on the page
         | — so they cannot reach a design token and had these written into them
         | by hand instead. One green in four files is four greens waiting to
         | disagree.
         |
         | `ink` and `paper` are the badge's two resting states, taken from the
         | mark. `accent` is the mark's green.
         |
         | There is deliberately no mid-green for text on a light background.
         | One was invented here and has been removed: a colour that bright is
         | unreadable under white, and the answer turned out not to be a darker
         | green but no green at all — the accent underlines the active tab and
         | the label simply gets heavier. Nothing here is a guess at a palette
         | that has not been designed yet.
         |
         */

        'palette' => [
            'ink' => '#14181A',
            'paper' => '#fafafa',
            'accent' => '#00FF99',
        ],

    ],

    /*
     |--------------------------------------------------------------------------
     | What this venue is called, in pictures
     |--------------------------------------------------------------------------
     |
     | A venue is the half of a server that strangers meet, and often the half
     | with a name of its own: Tabletop runs on StreetMesh the way a shop stands
     | on a high street. A domicile in the same container sets its own, so the
     | server answering for somebody's records is not wearing the sign over the
     | door of the games room.
     |
     | A public path with no variant or extension on it. A mark that carries its
     | own ground needs a second drawing for a dark surface, and every pack built
     | for this server puts `-small.svg` and `-dark-small.svg` beside each other
     | under one name — so naming the pair is enough, and there are not two paths
     | here that can disagree with each other.
     |
     | Unset is the server's own mark, which is the right answer for a venue
     | nobody has branded separately.
     |
     */

    'mark' => env('STREETMESH_VENUE_MARK'),

    /*
     |--------------------------------------------------------------------------
     | Building this server's hub
     |--------------------------------------------------------------------------
     |
     | A StreetMesh server has at most one hub, and what makes it this server's
     | hub is the rooms it serves. Only this server knows which those are, so
     | `php artisan hub:build` writes it out — flat and self-contained, with
     | everything copied in.
     |
     | `hub` is where the hub library lives and `into` is where the artifact is
     | written. Both default to the sensible place in a checkout, and are here
     | for a server arranged differently.
     |
     */
    'build' => [

        'hub' => env('STREETMESH_HUB_SOURCE'),

        'into' => env('STREETMESH_HUB_BUILD'),

    ],

    /*
     |--------------------------------------------------------------------------
     | Releasing this server's hub
     |--------------------------------------------------------------------------
     |
     | What `php artisan hub:deploy` sends the built hub to Colyseus Cloud with.
     |
     | Named here rather than read from the environment where they are used,
     | because `env()` answers null once the configuration is cached — which is
     | what a production deploy does, and is the one place these are wanted. A
     | command reading them directly cannot tell a credential that is missing
     | from a credential it is no longer allowed to see, and reports the wrong
     | one of those.
     |
     | `branch` is which branch of this repository the hub is released from, for
     | a server that does not call it `main`.
     |
     */
    'deploy' => [

        'application' => env('COLYSEUS_APPLICATION_ID'),

        'token' => env('COLYSEUS_TOKEN'),

        'branch' => env('COLYSEUS_BRANCH'),

    ],

];
