<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://protocol.streetmesh.com/brand/dark/svg/streetmesh-mark-dark.svg">
  <img alt="StreetMesh" src="https://protocol.streetmesh.com/brand/svg/streetmesh-mark.svg" width="96">
</picture>

# StreetMesh Hub

**The authoritative half of a venue: rooms, and who is allowed in them.**

> Developed in [`Server`](https://github.com/StreetMesh/Server), at `hub/`, and
> published here. Issues and pull requests belong there. Not on npm yet, so
> `npm install @streetmesh/hub` will not find it.

Anything people do together needs one place that decides what is true *right
now* — whose turn it is, where the video is paused, what the current bid stands
at. Not a place that collects opinions from the participants and takes the
majority view: a place that decides, and refuses a browser saying otherwise,
whether that browser is confused or lying.

That is this. It is the only part of StreetMesh authoritative over the present
moment, and it is not authoritative over anything else — what *happened* belongs
to the venue, which signs the record, and to the person whose records it goes
into.

**Hub itself knows no rules.** It provides a room and a door. What makes a room
a game of chess rather than a watch party is an experience, and experiences are
somebody else's package.

> Chess is the example used throughout this README, because it is the smallest
> thing that needs the whole of it: two people, strict turns, a rule about what
> is legal, and an outcome worth keeping afterwards. Where it is a poor guide,
> the text says so — a watch party needs less than chess and an auction needs
> more, and neither is built in here either.

## What it knows, and what it deliberately does not

Hub knows nothing about federation. It cannot resolve a handle, has never heard
of a DID directory, and holds no permission over anybody's records.

What it receives is a **ticket**: a short-lived assertion, signed by the venue
with the key that venue already publishes, saying *this person may sit in this
seat in this room*. Hub fetches the venue's DID document, checks a signature,
and that is the whole of its security model.

So it holds **no credential**. No shared secret, no private key, nothing to
steal and nothing it could use to assert anything back to the venue. If this
process were entirely compromised, what an attacker would gain is the ability to
lie about what is happening in a room — not to forge a record, not to reach
anybody's domicile, and not to impersonate the venue.

```
venue  ──signs a ticket──▶  browser  ──presents it──▶  hub
                                                        │
venue  ◀──── asks what happened, when it wants to ──────┘
```

Trust runs one way. Hub never calls the venue, never pushes, never asserts.

## State, and what survives a restart

**Hub is authoritative over the moment. The venue is authoritative over the
record.**

A room is a fast, rebuildable view of state the venue owns. Restart Hub and
rooms reopen from the venue; something nobody is currently doing has no room at
all. Which gives one rule an experience author has to think about:

> Anything that must survive a restart has to reach the venue before it is
> acknowledged to a participant.

How much that costs depends entirely on what is being built, which is why it is
declared rather than decided here:

- a **watch party** can lose where the video was paused — everyone re-seeks, and
  nothing was lost that anybody minded losing
- an **auction** cannot lose a bid, ever, so every bid pays for a round trip
  before the bidder is told it landed
- **chess** sits between them, and even moves within itself: a fast game can
  acknowledge here and persist a beat later, where a crash between the two costs
  one move; a game played over days cannot afford that at all

The framework asks an author which of those they are building, rather than
choosing for them and being wrong most of the time.

## Checking it

```sh
./check-ticket    # does a ticket PHP signed verify here?
./check-join      # does it open a door, and does nothing else?
./check-blip      # does an announcement over the shared secret land?
./check-party     # can a table see that somebody is in a party?
```

The second matters because the first can pass while the room admits everybody
anyway — a signature check being correct and a door being shut are two different
properties, and only one of them keeps strangers out. `check-join` stands a real
hub up and connects real websocket clients to it.

`check-ticket` mints a real ticket in PHP and verifies it here, which is the
seam least likely to be caught by either side's own tests. Between the two languages sit base58, a
multicodec prefix, a compressed curve point whose y coordinate has to be
recovered by solving the curve equation, base64url without padding, and an ECDSA
signature as a raw r‖s pair rather than the DER most libraries hand you. A test
written here that minted its own tickets would pass with every one of those
wrong.

It needs a StreetMesh server running locally — see
[`Server`](https://github.com/StreetMesh/Server).

**Node does not read the system keychain.** The script points it at Herd's
certificate authority, because without that every fetch of a `.test` DID
document fails and the failure looks exactly like a ticket that will not verify.
That cost the prototype time twice, which is why it is a script rather than a
command to remember.

## Where this fits

| Where | What it is |
| --- | --- |
| [**Glossary**](https://github.com/StreetMesh/Protocol/blob/main/GLOSSARY.md) | Every term here in plain words — venue, ticket, attestation, domicile — and whether each one is ours or borrowed. Start here if any of the above was unfamiliar. |
| [<code>Protocol</code>](https://github.com/StreetMesh/Protocol) | What StreetMesh is. Guides, decisions, conformance vectors. |
| [<code>Protocol&#8209;PHP</code>](https://github.com/StreetMesh/Protocol-PHP) | The framework-free implementation. |
| [<code>Protocol&#8209;Laravel</code>](https://github.com/StreetMesh/Protocol-Laravel) | The same, bound to Laravel — including minting the tickets this checks. |
| [<code>Server</code>](https://github.com/StreetMesh/Server) | Where to start if you want to run one. |
| <code><b>Hub</b></code> | This. The authoritative multiplayer host. |

## License

MIT.
