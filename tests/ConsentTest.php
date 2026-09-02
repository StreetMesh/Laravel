<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\ClientAssertion;
use StreetMesh\Protocol\ClientMetadata;
use StreetMesh\Protocol\Glb;
use StreetMesh\Protocol\Jwk;
use StreetMesh\Protocol\Network;
use StreetMesh\Protocol\P256;
use StreetMesh\Protocol\Pkce;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Protocol\Permissions\Permissions;
use StreetMesh\Server\Protocol\Permissions\Spent;
use StreetMesh\Server\Tests\Fixtures\Resident;

/**
 * What a resident is actually agreeing to.
 *
 * The one screen in the whole exercise a person reads, and the reason a scope
 * nothing can describe is a scope that must not be enforced anywhere. A
 * permission invisible here is a permission granted by nobody, however
 * carefully the endpoint that spends it checks its arithmetic.
 */
class ConsentTest extends TestCase
{
    private const VENUE = 'https://games.test/client-metadata.json';

    private P256 $venueKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venueKey = P256::generate();

        $this->app->instance(Network::class, (new FakeNetwork)
            ->serve(self::VENUE, [
                'client_id' => self::VENUE,
                'redirect_uris' => ['https://games.test/connect/callback'],
                'jwks_uri' => 'https://games.test/jwks.json',
            ])
            ->serve('https://games.test/jwks.json', ClientMetadata::keySet([
                'atproto' => Jwk::forP256($this->venueKey),
            ])));
    }

    public function test_a_permission_to_keep_bytes_is_a_sentence_somebody_can_read(): void
    {
        $asking = $this->asking('atproto blob:image/png');

        $this->assertContains('Keep pictures in your files', $asking);
    }

    /**
     * Both halves of the grammar say their piece.
     *
     * A venue that builds avatars asks for two different things — to write a
     * kind of record, and to keep the bytes it refers to — and neither implies
     * the other. A screen that mentioned one of them would be describing half a
     * permission.
     */
    public function test_a_venue_that_builds_avatars_asks_for_two_things_and_says_both(): void
    {
        $asking = $this->asking(implode(' ', [
            'atproto',
            (string) Scope::forRepo([Avatars::COLLECTION], [Scope::CREATE]),
            'blob?accept=image/png&accept='.Glb::MIME,
        ]));

        $this->assertContains(
            'Add '.Avatars::COLLECTION.' to your records, and never change or remove them',
            $asking,
        );
        $this->assertContains('Keep pictures and models in your files', $asking);
    }

    /**
     * Two types that mean the same word are one word.
     *
     * "Pictures and pictures" reads as a bug in the screen rather than as a
     * permission, and somebody skimming would learn nothing from the second
     * half of it.
     */
    public function test_two_types_that_say_the_same_word_say_it_once(): void
    {
        $this->assertContains(
            'Keep pictures in your files',
            $this->asking('atproto blob?accept=image/png&accept=image/jpeg'),
        );
    }

    /**
     * An unfamiliar type is read as itself.
     *
     * A sentence saying a venue may keep "files" has told somebody nothing, and
     * the honest fallback for a type this server has no word for is the type.
     */
    public function test_a_type_with_no_plain_word_is_shown_as_itself(): void
    {
        $this->assertContains('Keep audio/ogg in your files', $this->asking('atproto blob:audio/ogg'));
    }

    public function test_a_permission_to_keep_anything_says_so(): void
    {
        $this->assertContains('Keep anything at all in your files', $this->asking('atproto blob:*/*'));
    }

    /**
     * A permission that grants nothing is still a sentence.
     */
    public function test_asking_for_nothing_says_that_too(): void
    {
        $this->assertContains('Confirm who you are, and nothing else', $this->asking('atproto'));
    }

    /**
     * The screen, as a signed-in resident reaches it.
     *
     * @return array<int, string>
     */
    private function asking(string $scope): array
    {
        $permissions = new Permissions($this->app->make(Network::class), $this->app->make(Spent::class));
        $issuer = 'https://games.test';

        $pushed = $permissions->push([
            'client_id' => self::VENUE,
            'redirect_uri' => 'https://games.test/connect/callback',
            'scope' => $scope,
            'code_challenge' => Pkce::generate()->challenge(),
            'code_challenge_method' => 'S256',
            'client_assertion' => ClientAssertion::for(self::VENUE, $issuer, $this->venueKey),
        ], $issuer, Jwk::forP256(P256::generate())->thumbprint());

        $user = Resident::create([
            'name' => 'Alice',
            'email' => 'alice@home.test',
            'password' => 'irrelevant',
        ]);

        $response = $this->actingAs($user)
            ->get(route('streetmesh.oauth.authorize', ['request_uri' => $pushed->request_uri]))
            ->assertOk();

        /** @var array<int, string> $asking */
        $asking = $response->viewData('asking');

        return $asking;
    }
}
