# StreetMesh Protocol for PHP

The StreetMesh protocol in framework-free PHP: identity, encoding, signing.

> **Not on Packagist yet**, so `composer require streetmesh/protocol` will not
> find it. Until it is published, get it from
> [`Server`](https://github.com/StreetMesh/Server), which is also where it is
> developed — this repository is published from there, at `packages/protocol`.
> Issues and pull requests belong there.

Nothing here knows about a framework, a container, a configuration file, or a
database. Classes take bytes and return bytes. The one place that genuinely
needs the outside world — resolving somebody else's identity — asks for it
through a two-method interface you can implement over whatever HTTP client your
host already has.

This is one implementation among possible others. The protocol itself lives in
[StreetMesh/Protocol](https://github.com/StreetMesh/Protocol), and what makes
this package correct is [its conformance
vectors](https://github.com/StreetMesh/Protocol/tree/main/conformance) rather
than anything asserted here.

## What is in it

| | |
|---|---|
| `Plc` | Deriving a `did:plc` from its genesis operation, and minting one |
| `PlcDirectory` | Resolving a `did:plc` and reading its audit log |
| `Did` | `did:web` identifiers, and the URLs they resolve to |
| `Handle` | Resolving a readable name to an identity, in both directions |
| `Jws` | Compact JWS, `alg` EdDSA |
| `DagCbor` | Deterministic CBOR, as ATProtocol hashes and signs it |
| `Multikey` | Public keys as multibase multicodec, on Ed25519, secp256k1 and P-256 |
| `Ed25519`, `P256` | Signing and verification |
| `Address` | Addressing a place, and a spot within it |
| `Network`, `Curl` | The only way out, and a default you should probably replace |

## Two things worth knowing before you use it

**Nothing here signs a structure.** `Ed25519::sign()` takes bytes and there is
no overload that takes an array. That is deliberate: signing a structure means
the verifier has to rebuild the same bytes from whatever it decoded, and
everything in between — a framework tidying input, a JSON library ordering keys
differently, an empty string becoming null — gets a vote it should never have
had. Encode first, sign what you encoded.

**DAG-CBOR sorts map keys by length, then bytewise.** RFC 7049's canonical
order, not the plain lexicographic order RFC 8949 later adopted and most CBOR
libraries default to. Backwards, and every DID you compute is wrong in a way
that presents as a signature problem.

## Reaching the network

`Handle` and `PlcDirectory` need to fetch documents and read DNS. Both take a
`Network`, and both default to a plain cURL implementation that works out of the
box and is not meant to impress anybody:

```php
use StreetMesh\Protocol\{Handle, PlcDirectory};

$directory = new PlcDirectory;

$did = (new Handle)->verify(
    'alice.example.com',
    fn (string $did): array => $directory->resolve($did),
);
```

`verify()` rather than `resolve()` is what you almost always want. A handle
pointing at an identity proves the server hosting that name says so; it does not
prove the identity agrees. Without both directions, anybody able to publish a
name can hang it on a stranger.

In an application with its own HTTP client — retries, caching, pooling, metrics
— implement `Network` over that instead:

```php
final class MyNetwork implements StreetMesh\Protocol\Network
{
    public function get(string $url): ?string { /* ... */ }

    /** @return array<int, string> */
    public function txt(string $name): array { /* ... */ }
}
```

## Checking against the live network

```sh
php bin/check-repo.php atproto.com
php bin/check-repo.php did:plc:z72i7hdynmk6r22z27h6tvur
```

Downloads a real repository, takes it apart, and rebuilds it from nothing but
the records inside — then compares every name it produced against the name that
repository's own server had already given it. Read-only; it fetches public data
and writes nothing.

```
  ✓ every block hashes to the name it arrived under 13293 blocks
  ✓ the commit is signed by the key that identity publishes secp256k1
  ✓ the commit names the identity we asked about
  ✓ the tree rebuilds to the same root             bafyreihyl4gqkt7k6zav…
  ✓ every node we built is in their archive, named the same 2806 of 2806
  ✓ every record matches the name the tree gives it 10486 records
```

Nothing here is a fixture, which is the point. Passing against an arbitrary
stranger's repository means this agrees with a running network rather than with
its author — the only version of "correct" worth having for a format two parties
must agree on. It has been run against repositories up to 205,922 records and
54,922 tree nodes.

```sh
php bin/check-discovery.php bnewbold.net
```

Walks a name a person could type all the way to the server that can grant
permission over that account, using only what each hop publishes — DNS, the DID
document, the PDS, the protected-resource document, the authorization server.

```
  ✓ the name resolves, and answers to itself     did:plc:44ybard66vv44zksje25o7dz
  ✓ the chain reaches an authorization server    https://pds.robocracy.org
  ✓ it requires pushed authorization requests    checked while parsing
  ✓ it will read a client metadata document      checked while parsing
  ✓ it accepts a signature we can make           ES256 offered
```

Run it against somebody who self-hosts rather than a Bluesky-hosted account —
otherwise every chain ends at the same place and proves less than it appears to.
`bnewbold.net`, `mackuba.eu` and `natalie.sh` are on three independent servers.

## Tests

The vectors are the test suite. This package has almost none of its own: a
private set of expectations here would be a second definition of the protocol,
and passing it would only prove self-consistency.

```sh
composer conformance   # fetch the vectors
composer test          # phpstan, then run against them
```

CI pins a revision of `StreetMesh/Protocol` rather than tracking its default
branch, so "which version of the protocol does this implement" stays an
answerable question. Bumping that pin is how this package adopts a protocol
change, and it should be its own commit.

## License

MIT. The StreetMesh prose and documentation at the organization level are
CC BY-NC-SA 4.0.
