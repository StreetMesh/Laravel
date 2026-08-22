<?php

return [

    /*
     |--------------------------------------------------------------------------
     | Who this server is
     |--------------------------------------------------------------------------
     |
     | The name strangers reach this server by. Under `did:web` it decides the
     | server's own identifier, so it has to be the real one rather than a local
     | alias — and it is read when an identity is first made, so changing it
     | afterwards renames nothing that already exists.
     |
     | `origin` is the same server as a full address, and is what this server
     | publishes in its DID document as the place to reach it. Derived from the
     | host when it is not set, which is right for every server that serves
     | itself on https and its own name.
     |
     */

    'host' => env('STREETMESH_HOST'),

    'origin' => env('STREETMESH_ORIGIN'),

    /*
     | P-256 unless something is very unusual. `did:plc` permits only secp256k1
     | and P-256, and P-256 is the one PHP handles without an extension.
     */

    'curve' => env('STREETMESH_CURVE', 'p256'),

    /*
     |--------------------------------------------------------------------------
     | What this server offers
     |--------------------------------------------------------------------------
     |
     | Installing a package is how a capability arrives, and for a server that
     | does one thing that is the whole of it. These switches are for the case
     | that is not: two servers built from one codebase, installing the same
     | packages, which are not the same server.
     |
     | Unset means offered. Setting one to false is an operator saying "not
     | this one here" — declared, rather than deduced from something adjacent,
     | so a forgotten line cannot quietly change what a server is.
     |
     | Named for what each capability calls itself, so an installed package
     | nobody here has heard of gets a switch on the same terms.
     |
     */
    'capabilities' => [

        'domicile' => env('STREETMESH_DOMICILE'),

        'venue' => env('STREETMESH_VENUE'),

    ],

    /*
     |--------------------------------------------------------------------------
     | The front page
     |--------------------------------------------------------------------------
     |
     | What a stranger sees at the root, before signing in. A server has one
     | root, so if it offers more than one capability it has to say which one
     | greets people. Null follows whatever is installed, which is enough for a
     | server offering only one thing.
     |
     */

    'front_page' => env('STREETMESH_FRONT_PAGE'),

    /*
     |--------------------------------------------------------------------------
     | The home page
     |--------------------------------------------------------------------------
     |
     | What somebody signed in sees. The one surface where two installed
     | capabilities genuinely overlap, so it is a collection of panels rather
     | than a page either of them owns.
     |
     | Null shows everything on offer, in the order capabilities were
     | registered. Naming them instead is how an operator decides what their
     | server is for — and a name nothing provides is skipped rather than fatal,
     | so removing a package does not break a page.
     |
     */

    'home_page' => null,

    /*
     |--------------------------------------------------------------------------
     | Asking somebody else's server for permission
     |--------------------------------------------------------------------------
     |
     | A venue publishes what it is at a URL, and that URL is its identifier —
     | there is nothing to register anywhere and no secret to share. These are
     | the parts of that document an operator gets to decide.
     |
     | `redirect_route` names the route that receives somebody coming back from
     | their own server having approved something. A name rather than an address
     | because the same value has to be published here and sent with every
     | authorization request, and their server refuses if the two disagree — so
     | there is one of it, and moving the route cannot break it.
     |
     | `redirect` overrides that with an absolute URL, for an operator whose
     | venue sits behind something that rewrites addresses.
     |
     | `scopes` are asked for in addition to `atproto`, which is always included
     | and is the claim to be following this profile at all. Anything ATProtocol
     | does not define is an extension of ours and has to be named as one — a
     | scope invented locally is a word no other server on the network knows.
     |
     */

    /*
    |--------------------------------------------------------------------------
    | The PLC directory
    |--------------------------------------------------------------------------
    |
    | Where residents' identifiers are published. A `did:plc` is the hash of the
    | operation that created it, so it says nothing about where its subject
    | lives and survives them renaming or moving — which `did:web` cannot do,
    | because a `did:web` *is* an address.
    |
    | What the directory is trusted for is narrow and worth being exact about:
    | every operation is signed by a key the subject holds, so it can neither
    | forge an identity nor reassign one. It can only decline to answer.
    |
    | Point this at a directory of your own while developing. Operations sent to
    | the public one are permanent, and ones naming a `.test` host would be
    | permanent litter.
    |
    */

    'plc' => [
        'directory' => env('STREETMESH_PLC_DIRECTORY', 'https://plc.directory'),

        /*
         | Whether this server also *keeps* a directory, at `/plc`.
         |
         | For development, and it exists so that a local identity costs nothing
         | to arrange. The alternative was a container, a compose file and a
         | daemon to remember — a Docker dependency bolted to a Laravel
         | application for the sake of four endpoints, paid for by every
         | developer downstream.
         |
         | Point `directory` above at it and there is no second host to set up:
         |
         |     STREETMESH_PLC_HOST=true
         |     STREETMESH_PLC_DIRECTORY="${APP_URL}/plc"
         |
         | What it will not do is recover an identity. The real directory lets a
         | higher-priority rotation key fork a chain and nullify what a lower
         | one did, which is how somebody takes an identity back from a server
         | that has gone bad; this refuses the conflict instead. That is the
         | right trade for a development directory and the wrong one for a
         | registry anybody relies on.
         |
         | Use the real directory in production. Identities kept here are as
         | permanent as this server's database and resolvable by nobody else.
         |
         */

        'host' => env('STREETMESH_PLC_HOST', false),
    ],

    'oauth' => [
        'redirect_route' => 'venue.callback',
        'redirect' => env('STREETMESH_OAUTH_REDIRECT'),
        'scopes' => [],
    ],

    'network' => [
        'timeout' => 10,
        'cache_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bytes that are not records
    |--------------------------------------------------------------------------
    |
    | A picture or a model, kept for a resident. The rows live in this server's
    | database and the bytes live on a disk, so that an operator can point a
    | bucket and a CDN at them without this package knowing.
    |
    | `limits` is the whole of what may be stored: a type not named here is
    | refused. That is the opposite of the choice made for record collections,
    | where anything undeclared is private rather than refused, and the reason
    | is that the two fail in opposite directions. An undeclared record is
    | private and therefore harmless. An undeclared blob would be a file of a
    | stranger's choosing served back from a resident's own hostname — the same
    | origin their identity documents are answered from.
    |
    | PNG alone, for now, because it is the only thing anything here produces:
    | an uploaded icon is re-encoded before it is stored, so whatever somebody
    | had is not what is kept. Models join this list when models are built.
    |
    */

    'blobs' => [
        /*
         | Follows the application's own disk unless told otherwise, rather
         | than naming one. Hard-coding `local` here meant an operator who had
         | pointed their whole application at a bucket still had blobs written
         | to the container's filesystem — which on most hosts is thrown away at
         | the next deploy, so every resident's picture would quietly vanish and
         | the setting that looked like it governed this would have had no
         | effect on it.
         */
        'disk' => env('STREETMESH_BLOB_DISK', env('FILESYSTEM_DISK', 'local')),

        'limits' => [
            'image/png' => 512 * 1024,
        ],
    ],

];
