<?php

namespace StreetMesh\Server\Protocol\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use StreetMesh\Protocol\Curl;
use StreetMesh\Protocol\Handle;
use StreetMesh\Protocol\Jws;
use StreetMesh\Protocol\PlcDirectory;
use StreetMesh\Server\Protocol\Identity\DidResolver;
use StreetMesh\Server\Protocol\Identity\Identities;
use Throwable;

/**
 * Can a stranger check what this server signs?
 *
 * Everything else can be true while this is false. The keys can be right, the
 * documents well formed, the tests green — and if the endpoints are not actually
 * reachable at the name this server calls itself, nothing it signs can be
 * verified by anybody, and it will not find out until somebody else's server
 * quietly rejects a record.
 *
 * So this asks from outside. It signs something, then goes over the network as
 * an ordinary client with no access to this application at all, and checks the
 * result. What it exercises is not this code but the deployment: DNS, TLS, the
 * routes, and whether the configured host is the one strangers can reach.
 */
class CheckIdentity extends Command
{
    protected $signature = 'streetmesh:check';

    protected $description = 'Check that a stranger can verify what this server signs';

    public function handle(Identities $identities): int
    {
        $identity = $identities->forServer();
        $network = new Curl;
        $resolver = new DidResolver($network, new PlcDirectory($network));

        $this->newLine();
        $this->line("  <fg=gray>identity</> {$identity->did}");
        $this->line('  <fg=gray>handle  </> '.($identity->handle ?? '(none)'));
        $this->line('  <fg=gray>key     </> '.$identity->key()->multikey().' <fg=gray>('.$identity->signing_curve.')</>');
        $this->newLine();

        $failed = 0;

        $check = function (string $what, callable $test) use (&$failed): void {
            try {
                $detail = $test();
                $this->line(sprintf('  <fg=green>✓</> %-44s <fg=gray>%s</>', $what, $detail));
            } catch (Throwable $e) {
                $failed++;
                $this->line(sprintf('  <fg=red>✗</> %-44s <fg=red>%s</>', $what, $e->getMessage()));
            }
        };

        // Signed here, checked from out there. Everything below uses only what
        // this server publishes.
        $attestation = Jws::sign(
            ['type' => 'com.streetmesh.check', 'at' => now()->toIso8601String()],
            $identity->key(),
            $identity->keyId(),
        );

        $check('the name resolves to this identity', function () use ($network, $identity): string {
            $found = (new Handle($network))->resolve((string) $identity->handle);

            return $found === $identity->did
                ? $found
                : throw new \RuntimeException("resolves to {$found} instead");
        });

        $check('the document is reachable and claims the name', function () use ($resolver, $identity): string {
            $document = $resolver->document($identity->did);

            return in_array('at://'.$identity->handle, $document['alsoKnownAs'] ?? [], strict: true)
                ? 'both directions agree'
                : throw new \RuntimeException('the document does not claim this name back');
        });

        $check('a stranger finds the key we signed with', function () use ($resolver, $attestation, $identity): string {
            $key = $resolver->keyAt(Jws::keyId($attestation), new DateTimeImmutable);

            return $key->multikey === $identity->key()->multikey()
                ? substr($key->multikey, 0, 20).'…'
                : throw new \RuntimeException('published a different key than we hold');
        });

        $check('and can verify what we signed', function () use ($resolver, $attestation): string {
            $key = $resolver->keyAt(Jws::keyId($attestation), new DateTimeImmutable);

            Jws::verify($attestation, $key->multikey);

            return 'checked against what it published';
        });

        $this->newLine();

        if ($failed === 0) {
            $this->line('  <fg=green>A stranger can verify what this server signs.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line("  <fg=red>{$failed} check(s) failed — records this server signs cannot be verified by others.</>");
        $this->line('  <fg=gray>Usually the configured host is not the one strangers reach, or TLS is not trusted.</>');
        $this->newLine();

        return self::FAILURE;
    }
}
