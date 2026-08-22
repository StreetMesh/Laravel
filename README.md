# StreetMesh Protocol for Laravel

The StreetMesh protocol, bound to the framework.

> **Not on Packagist yet**, so `composer require streetmesh/protocol-laravel`
> will not find it. Until it is published, get it from
> [`Server`](https://github.com/StreetMesh/Server), which is also where it is
> developed — this repository is published from there, at
> `packages/protocol-laravel`. Issues and pull requests belong there.

The protocol itself lives in
[`streetmesh/protocol`](https://github.com/StreetMesh/Protocol-PHP), which knows
nothing about Laravel and takes bytes in and gives bytes out. That package knows
*how* to verify a signature; this one knows *when*, and gives the other one the
application's HTTP client, cache and database to work through.

## What it provides

**A record store shaped like a repository.** Records are addressed by subject,
collection and key rather than by a row id, written once, and named by the hash
of what they say. Listing somebody's history in the order it happened is an
index scan rather than a sort, because the key sorts by time.

**Bindings.** The framework-free layer defines what it needs from the outside
world in a two-method interface and ships a plain cURL implementation so it
works alone. Here that is replaced by the application's own HTTP client, with
its timeouts and — the part that matters — its cache. Identity documents are
fetched constantly and change rarely.

**One guarantee that is easy to lose and expensive to lose.** Laravel blanks and
trims request input as a kindness to HTML forms. Applied to a signed document it
is corruption: a signature covers bytes, so turning an empty string into null
changes what is verified and the check fails for a document that was never
wrong. The failure looks exactly like forgery and is data-dependent, so it
appears intermittent. This package turns that off for the paths that carry
signatures, so no implementor has to know.

## Identity

A server needs one of its own before anything federated works — a venue signs
attestations with it, a domicile answers for itself with it.

```php
use StreetMesh\Server\Protocol\Identity\Identities;

$server = app(Identities::class)->forServer();          // made once, then found
$server->did;                                            // did:web:games.test
```

Two endpoints come with it, both unauthenticated and both necessarily so. A
record is meant to be checkable years later by somebody with no relationship to
this server; if finding out which key signed it required an account, the record
would only be as durable as an arrangement between two parties.

```
GET /.well-known/did.json        who this server is, and its key
GET /.well-known/atproto-did     which identity this hostname stands for
```

Both directions have to agree. A name pointing at an identity proves only that
whoever serves the name says so; the document claiming it back is the identity
agreeing. Either alone lets somebody hang a familiar name on a stranger.

### Residents keep the key that lets them leave

```php
['identity' => $identity, 'rotationKey' => $rotation] = app(Identities::class)
    ->forResident('alice.games.test');
```

The rotation key is generated and **handed back rather than kept**. It is the
key that can point an identity at a different server, so a resident whose only
copy lives here could move only with this server's cooperation — which is the
arrangement this project exists to argue against. This server keeps no copy, so
it can neither move them nor refuse to.

What the caller does with it is the interface layer's problem, and a real one: a
passkey cannot hold it, so it means a recovery phrase or an exported file, and a
design for people who lose things.

### `did:web` now, `did:plc` when you mean it

`did:web` needs nothing but a hostname you already control. `did:plc` costs a
public, permanent entry in shared infrastructure and buys two things `did:web`
cannot offer: an identifier that survives its subject moving, and a dated key
history so something signed years ago can still be checked.

Minting a `did:plc` is never a side effect here. It is asked for explicitly.

Keys are **P-256 by default** because it is the only curve that works for
`did:web` now and `did:plc` later — an identity minted on Ed25519 could never
move to the method that makes it portable.

### Requirements

Keys are encrypted at rest, so `APP_KEY` must be set. A server without one
cannot hold an identity at all.

## Records

```php
use StreetMesh\Server\Protocol\Records\RecordStore;

$record = app(RecordStore::class)->put(
    did: 'did:plc:z72i7hdynmk6r22z27h6tvur',
    collection: 'com.streetmesh.games.chess',
    value: ['result' => 'win', 'venue' => 'did:web:games.example'],
);

(string) $record->address();   // at://did:plc:…/com.streetmesh.games.chess/3mq…
```

Notice what you cannot pass: a key, a content hash, or a visibility. Keys are
minted, hashes computed, and visibility looked up from the collection — so none
of the three can be got wrong by a caller, because no caller gets to say.

### Visibility belongs to the collection

```php
'collections' => [
    'com.streetmesh.games.chess'   => Record::PUBLIC,
    'com.streetmesh.messages.direct' => Record::PRIVATE,
],
```

A chess result is public because chess results are public. Deciding it per
record would mean there is an input somewhere saying whether *this one* is
private, and an input is something that can be wrong, forged, or flipped by a
bug in a form. Publishing cannot be undone — a record replicated out of a public
collection cannot be recalled — so the failure worth designing against is a
private thing becoming public, and the way to prevent it is to leave nothing to
flip.

A collection nobody has declared cannot be written to at all. That friction is
deliberate: adding a kind of record should be a decision made once in
configuration, not a consequence of a mistyped name.

### Identity

A server needs one of its own before anything federated works — a venue signs
attestations with it, a domicile answers for itself with it.

```php
use StreetMesh\Server\Protocol\Identity\Identities;

$server = app(Identities::class)->forServer();          // made once, then found
$server->did;                                            // did:web:games.test
```

Two endpoints come with it, both unauthenticated and both necessarily so. A
record is meant to be checkable years later by somebody with no relationship to
this server; if finding out which key signed it required an account, the record
would only be as durable as an arrangement between two parties.

```
GET /.well-known/did.json        who this server is, and its key
GET /.well-known/atproto-did     which identity this hostname stands for
```

Both directions have to agree. A name pointing at an identity proves only that
whoever serves the name says so; the document claiming it back is the identity
agreeing. Either alone lets somebody hang a familiar name on a stranger.

### Residents keep the key that lets them leave

```php
['identity' => $identity, 'rotationKey' => $rotation] = app(Identities::class)
    ->forResident('alice.games.test');
```

The rotation key is generated and **handed back rather than kept**. It is the
key that can point an identity at a different server, so a resident whose only
copy lives here could move only with this server's cooperation — which is the
arrangement this project exists to argue against. This server keeps no copy, so
it can neither move them nor refuse to.

What the caller does with it is the interface layer's problem, and a real one: a
passkey cannot hold it, so it means a recovery phrase or an exported file, and a
design for people who lose things.

### `did:web` now, `did:plc` when you mean it

`did:web` needs nothing but a hostname you already control. `did:plc` costs a
public, permanent entry in shared infrastructure and buys two things `did:web`
cannot offer: an identifier that survives its subject moving, and a dated key
history so something signed years ago can still be checked.

Minting a `did:plc` is never a side effect here. It is asked for explicitly.

Keys are **P-256 by default** because it is the only curve that works for
`did:web` now and `did:plc` later — an identity minted on Ed25519 could never
move to the method that makes it portable.

### Requirements

Keys are encrypted at rest, so `APP_KEY` must be set. A server without one
cannot hold an identity at all.

## Records are written once

A correction is a new record that says what it corrects. Not tidiness — if a
record could change after being cited, then an address would name whatever is
there now rather than what was cited, and every signature over it and every copy
of it elsewhere would drift apart silently.

## Attestations

A venue signs what happened; a domicile checks it before keeping it. Neither
side trusts the other, and neither has to still exist for the statement to stay
checkable.

```php
use StreetMesh\Server\Protocol\Attestations\Attestations;

$attestation = app(Attestations::class)->verify($compact, receivedAt: now());

$attestation->issuer;                    // did:plc:… — who said it
$attestation->claim('result');           // what they said
$attestation->checkedAgainstHistory();   // and how confident you get to be
```

`receivedAt` should be when this server saw the document, not when the document
says it was issued. The second is asserted by the party being checked and can be
backdated; the first is asserted by the party doing the checking and cannot.

### `checkedAgainstHistory()` is not decoration

Asked which key an identity was using at a past moment, `did:plc` answers from
its audit log and `did:web` cannot answer at all — it publishes a document and
no history, so the best available is the key in use now.

Verifying a year-old record against a key that might have been rotated last week
is a different act from verifying it against the key that was demonstrably
current when it was signed. Returning a bare key would make those two
indistinguishable at the call site, which is precisely where the difference
matters.

## History

A record proves only that it has not changed since it was named. The commit log
is what proves somebody agreed to it, and that the set held is the set they
agreed to.

```php
use StreetMesh\Server\Protocol\Records\CommitLog;

app(CommitLog::class)->commit($did, $signingKey);   // after writing
app(CommitLog::class)->verify($did, $publicKey);    // three checks, all must hold
```

Verification asks three things: is every commit signed by them, does each name
the one before it, and does the head still describe the records actually here. A
record added or removed without committing to it fails the third.

### What this does not do

**It does not stop a server lying about its residents.** The signing key is held
by the server somebody lives on, so a dishonest one can sign as them — and no
signature scheme can take that away.

What it does is make lying leave a mark. Every commit names the one before it by
its content, so a rewritten past produces a link that does not fit, and anybody
who saw the earlier link can show the two histories disagree. Detection rather
than prevention, which is the same guarantee ATProtocol offers and for the same
reason.

Private records are committed to as well. Committing only to the public ones
would let a server add or drop a private record with nothing to show for it, and
the people most needing that protection are exactly the ones whose records are
private.

### The root is one other software can compute

A commit signs a single value standing for every record. How that value is
derived decides whether anybody else can check it.

The default is a Merkle Search Tree, the same structure ATProtocol uses: a key's
layer follows from its own hash, so two servers holding the same records build
the same tree — node for node — without ever coordinating. A commit is therefore
a claim a stranger can verify rather than one only this server can.

The implementation was checked by rebuilding a live repository of 1672 records
from nothing but its records, producing all 463 of its nodes under the names its
own network had already given them.

`RecordTree` remains an interface, and `isInteroperable()` reports what a binding
is worth — a server with reason to prefer something else should not have to fork
the package, and a chain signed over a root nobody else can compute is a thing to
choose deliberately rather than discover.

## Portability

`RecordStore::exportFor()` returns everything of somebody's, in order. A person
can leave with what is theirs whether or not this server ever speaks the wider
protocol, which is most of what portability means and needs no repository
implementation to be real.

## Tests

```sh
composer test
```

## License

MIT.
