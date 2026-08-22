<?php

namespace StreetMesh\Server\Tests;

use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use StreetMesh\Server\Domicile\Residents\AvailableAddress;
use StreetMesh\Server\Domicile\Residents\Handle;
use StreetMesh\Server\Domicile\Residents\Residents;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;
use StreetMesh\Server\Tests\Fixtures\Resident;

/**
 * Giving somebody who lives here an address.
 *
 * The account is local and the address is not, and everything here is about
 * that difference. A domicile that hands out accounts without addresses has
 * residents who cannot leave the building — which is what this server did for
 * as long as it existed, while every local thing worked perfectly.
 */
class ResidentTest extends TestCase
{
    /**
     * Its subject is somebody who lives here, so here is where they live.
     *
     * The suite's default is a venue at `games.test` visited by people from
     * elsewhere. This half means the opposite by "here".
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->livesHere($app);
    }

    private function residents(): Residents
    {
        return $this->app->make(Residents::class);
    }

    private function user(string $email = 'alice@home.test'): Resident
    {
        return Resident::create([
            'name' => 'Alice',
            'email' => $email,
            'password' => 'irrelevant',
        ]);
    }

    private function serverExists(): void
    {
        $this->app->make(Identities::class)->forServer();
    }

    public function test_somebody_who_lives_here_is_given_a_name_under_this_servers_own(): void
    {
        $this->serverExists();

        $settled = $this->residents()->settle(
            $this->user(),
            Handle::for('alice', $this->residents()->host()),
        );

        $this->assertSame('alice.home.test', $settled['identity']->handle);
        $this->assertFalse($settled['identity']->is_server);
    }

    /**
     * The address has to be findable *from the account*, because that is the
     * direction every screen asks in: somebody is signed in, and we want to know
     * what they are called on the network.
     */
    public function test_the_address_can_be_found_from_the_account(): void
    {
        $this->serverExists();
        $user = $this->user();

        $this->residents()->settle($user, Handle::for('alice', $this->residents()->host()));

        $found = $this->app->make(Identities::class)->forUser($user);

        $this->assertNotNull($found);
        $this->assertSame('alice.home.test', $found->handle);
    }

    /**
     * The key they are handed is theirs, and is not the one this server keeps.
     *
     * This server holds a rotation key too — it has to, or it could never
     * change their handle for them — but a different one, and lower in the
     * order of authority. PLC lets a higher key undo what a lower one did, so
     * whatever this server does with its key, they can overrule with theirs.
     *
     * The same key in both places would make leaving something this server
     * grants rather than something they do.
     */
    public function test_what_lets_somebody_leave_is_theirs_alone(): void
    {
        $this->serverExists();

        $settled = $this->residents()->settle(
            $this->user(),
            Handle::for('alice', $this->residents()->host()),
        );

        $ours = $settled['identity']->fresh()?->rotationKey();

        $this->assertNotNull($ours, 'without one this server could never rename anybody');
        $this->assertNotSame($settled['rotationKey']->multikey(), $ours->multikey());
    }

    public function test_a_name_somebody_already_has_is_not_free(): void
    {
        $this->serverExists();
        $handle = Handle::for('alice', $this->residents()->host());

        $this->assertFalse($this->residents()->taken($handle));

        $this->residents()->settle($this->user(), $handle);

        $this->assertTrue($this->residents()->taken($handle));
    }

    public function test_nobody_is_given_a_second_address(): void
    {
        $this->serverExists();
        $user = $this->user();

        $this->residents()->settle($user, Handle::for('alice', $this->residents()->host()));

        $this->expectException(RuntimeException::class);

        $this->residents()->settle($user, Handle::for('alice2', $this->residents()->host()));
    }

    /**
     * An account with no address cannot go anywhere, and an address with no
     * owner is a name nobody can sign in as and claim. Half of a resident is
     * worse than none, so a failure has to leave neither half behind.
     */
    public function test_a_failure_partway_through_leaves_nobody_half_settled(): void
    {
        $this->serverExists();
        $user = $this->user();
        $before = Identity::query()->count();

        $this->residents()->settle($user, Handle::for('alice', $this->residents()->host()));

        try {
            $this->residents()->settle($this->user('bob@home.test'), Handle::for('alice', $this->residents()->host()));
        } catch (RuntimeException) {
            // The name is taken, which is the failure being provoked.
        }

        $this->assertSame($before + 1, Identity::query()->count());
    }

    /**
     * A handle is a hostname. Everything rejected here is rejected because DNS
     * would not carry it, not because of house style — a name outside these
     * rules is one that resolves nowhere.
     */
    #[DataProvider('impossibleNames')]
    public function test_a_name_that_could_not_be_a_hostname_is_refused(string $label): void
    {
        $this->expectException(InvalidArgumentException::class);

        Handle::for($label, 'home.test');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function impossibleNames(): array
    {
        return [
            'empty' => [''],
            'only spaces' => ['   '],
            'a dot, which would claim somebody else' => ['alice.bob'],
            'leading hyphen' => ['-alice'],
            'trailing hyphen' => ['alice-'],
            'underscore' => ['alice_smith'],
            'a space inside' => ['alice smith'],
            'too long' => [str_repeat('a', 64)],
            'kept for the server' => ['www'],
            'kept for the protocol' => ['xrpc'],
        ];
    }

    /**
     * The label is the resident's to choose and the host is not. Given one
     * string, somebody would eventually type a whole hostname into the box and
     * claim a name this server has no business issuing.
     */
    public function test_a_resident_cannot_choose_which_server_they_appear_to_live_on(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Handle::for('alice.somewhere-else.test', 'home.test');
    }

    /**
     * The screen listed nobody, always.
     *
     * Not a query that was wrong — there was no query. It shipped as a
     * demonstration that a package can own a Livewire component, returning a
     * hardcoded empty array, and read as "nobody lives here yet" however many
     * people did.
     */
    public function test_the_screen_lists_everybody_who_lives_here(): void
    {
        $this->serverExists();

        foreach (['alice', 'bob'] as $name) {
            $this->residents()->settle(
                $this->user($name.'@home.test'),
                Handle::for($name, $this->residents()->host()),
            );
        }

        $this->get('/directory')
            ->assertOk()
            ->assertSee('alice.home.test')
            ->assertSee('bob.home.test')
            ->assertDontSee('Nobody lives here yet');
    }

    /**
     * The server is not a resident. It holds no records of its own and belongs
     * to nobody, so listing it among the people who live here is a category
     * error rather than a stray row.
     */
    public function test_the_server_is_not_one_of_its_own_residents(): void
    {
        $this->serverExists();

        $this->assertCount(0, $this->residents()->all());
    }

    public function test_searching_narrows_to_a_name(): void
    {
        $this->serverExists();

        foreach (['alice', 'bob'] as $name) {
            $this->residents()->settle(
                $this->user($name.'@home.test'),
                Handle::for($name, $this->residents()->host()),
            );
        }

        $found = $this->residents()->all('ali');

        $this->assertCount(1, $found);
        $this->assertSame('alice.home.test', $found->first()?->handle);
        $this->assertCount(0, $this->residents()->all('nobody'));
    }

    public function test_a_name_is_the_same_name_however_it_was_typed(): void
    {
        $this->assertSame('alice.home.test', (string) Handle::for('  ALICE  ', 'home.test'));
    }

    /**
     * A form has to refuse the same names the issuing does, or somebody fills
     * it in successfully and then fails at the point of actually being given
     * the address.
     */
    public function test_a_form_refuses_a_name_that_could_not_be_issued(): void
    {
        $this->serverExists();
        $rule = new AvailableAddress($this->residents());

        /*
         * The shape the framework actually hands a rule, rather than the
         * shape this rule happens to use. A spy narrower than the contract
         * passes here and would be the wrong thing to assert against.
         */
        $refusals = [];
        $collect = function (string $message, ?string $attribute = null) use (&$refusals): PotentiallyTranslatedString {
            $refusals[] = $message;

            return new PotentiallyTranslatedString($message, app('translator'));
        };

        $rule->validate('address', 'alice', $collect);
        $this->assertCount(0, $refusals, 'a name nobody holds should be free');

        $rule->validate('address', 'alice_smith', $collect);
        $this->assertCount(1, $refusals);

        $this->residents()->settle($this->user(), Handle::for('alice', $this->residents()->host()));

        $rule->validate('address', 'alice', $collect);
        $this->assertCount(2, $refusals);
        $this->assertStringContainsString('already has that address', $refusals[1]);
    }
}
