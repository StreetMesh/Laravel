<?php

namespace StreetMesh\Server\Tests;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use StreetMesh\Protocol\AtUri;
use StreetMesh\Protocol\Cid;
use StreetMesh\Server\Protocol\Records\Collections;
use StreetMesh\Server\Protocol\Records\Record;
use StreetMesh\Server\Protocol\Records\RecordStore;

/**
 * The six things that make this a repository rather than a table.
 *
 * Each is tested because each is a promise made to a later version of this
 * system — that adding a full repository implementation is additive rather than
 * a rewrite. A promise nobody checks is a promise that quietly stops being true.
 */
class RecordStoreTest extends TestCase
{
    private const ALICE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    private function store(): RecordStore
    {
        return $this->app->make(RecordStore::class);
    }

    public function test_a_record_is_addressed_by_subject_collection_and_key(): void
    {
        $record = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'win']);

        $address = $record->address();

        $this->assertSame(self::ALICE, $address->authority);
        $this->assertSame('com.streetmesh.games.chess', $address->collection);
        $this->assertSame($record->rkey, $address->rkey);

        // The address, not the row id, is how it is found again.
        $found = $this->store()->get(AtUri::parse((string) $address));

        $this->assertNotNull($found);
        $this->assertSame($record->id, $found->id);
    }

    public function test_a_written_record_cannot_be_edited(): void
    {
        $record = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'win']);

        $this->expectException(RuntimeException::class);

        // Not because editing is untidy, but because a reference to a record
        // would otherwise name whatever is there now rather than what was cited.
        $record->update(['value' => ['result' => 'loss']]);
    }

    public function test_a_record_can_name_another_record(): void
    {
        $game = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'win']);

        $correction = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', [
            'result' => 'draw',
            'corrects' => ['uri' => (string) $game->address(), 'cid' => $game->cid],
        ]);

        $cited = AtUri::parse($correction->value['corrects']['uri']);
        $original = $this->store()->get($cited);

        $this->assertNotNull($original);
        $this->assertSame($game->id, $original->id);

        // Naming the CID as well as the address is what makes it "that record,
        // as it was" rather than "whatever is at that address".
        $this->assertTrue(Cid::parse($correction->value['corrects']['cid'])->matches($original->value));
    }

    public function test_keys_sort_by_time_so_history_reads_in_order(): void
    {
        $written = [];

        foreach (['a', 'b', 'c', 'd'] as $move) {
            $written[] = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['move' => $move])->rkey;
        }

        $listed = $this->store()
            ->list(self::ALICE, 'com.streetmesh.games.chess')
            ->pluck('rkey')
            ->all();

        $this->assertSame($written, $listed);

        $sorted = $written;
        sort($sorted);

        $this->assertSame($sorted, $written, 'keys did not sort chronologically');
    }

    public function test_visibility_comes_from_the_collection_and_cannot_be_passed(): void
    {
        $game = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'win']);
        $message = $this->store()->put(self::ALICE, 'com.streetmesh.messages.direct', ['body' => 'hello']);

        $this->assertSame(Record::PUBLIC, $game->visibility);
        $this->assertSame(Record::PRIVATE, $message->visibility);

        /*
         * There is no argument to pass, and that is the guarantee rather than a
         * convention: a private record cannot be published by anything getting
         * a parameter wrong, because nothing accepts the parameter.
         */
        foreach ((new ReflectionClass(RecordStore::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'visib',
                    $parameter->getName(),
                    "RecordStore::{$method->getName()}() takes [\${$parameter->getName()}], which is a way to "
                    .'choose visibility — the failure this design exists to make impossible',
                );
            }
        }
    }

    public function test_a_private_record_is_not_served_to_a_stranger(): void
    {
        $this->store()->put(self::ALICE, 'com.streetmesh.messages.direct', ['body' => 'hello']);

        $this->assertCount(0, $this->store()->list(self::ALICE, 'com.streetmesh.messages.direct'));
        $this->assertCount(1, $this->store()->list(self::ALICE, 'com.streetmesh.messages.direct', asStranger: false));

        // And an export a stranger asks for carries nothing private either.
        $this->assertCount(0, $this->store()->exportFor(self::ALICE));
    }

    /**
     * A server can hold a kind of record it has never been told about, because
     * a venue it has never heard of may arrive with one a resident agreed to.
     * Requiring it declared first would mean a venue could only settle records
     * to operators who had already heard of it, which is not federation.
     *
     * Holding it privately is the whole safety of that: the failure which
     * cannot be undone is a private thing becoming public, and nothing on this
     * path can cause it.
     */
    public function test_a_collection_nobody_declared_is_held_privately_rather_than_refused(): void
    {
        $record = $this->store()->put(self::ALICE, 'com.example.something.new', ['result' => 'win']);

        $this->assertSame(Record::PRIVATE, $record->visibility);

        $this->assertCount(0, $this->store()->list(self::ALICE, 'com.example.something.new'));
        $this->assertCount(1, $this->store()->list(self::ALICE, 'com.example.something.new', asStranger: false));

        // And a stranger asking for everything gets none of it.
        $this->assertCount(0, $this->store()->exportFor(self::ALICE));
    }

    /**
     * The declared list decides what gets published, so nonsense in it is still
     * a failure rather than something quietly treated as public.
     */
    public function test_a_collection_declared_as_nonsense_is_still_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Collections(['com.example.thing' => 'sort-of-public']))->visibilityOf('com.example.thing');
    }

    public function test_a_record_knows_whether_it_still_says_what_it_said(): void
    {
        $record = $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'win']);

        $this->assertTrue($record->isIntact());

        // Reaching around the model, as a migration or a stray query might.
        Record::query()->whereKey($record->id)->toBase()->update(['value' => json_encode(['result' => 'loss'])]);

        $this->assertFalse($record->fresh()->isIntact());
    }

    public function test_an_export_carries_everything_of_theirs_in_order(): void
    {
        $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'win']);
        $this->store()->put(self::ALICE, 'com.streetmesh.games.chess', ['result' => 'draw']);
        $this->store()->put('did:plc:somebodyelse', 'com.streetmesh.games.chess', ['result' => 'loss']);

        $export = $this->store()->exportFor(self::ALICE);

        // Portability without a repository implementation: a person can leave
        // with what is theirs, and only what is theirs.
        $this->assertCount(2, $export);
        $this->assertSame([self::ALICE, self::ALICE], $export->pluck('did')->all());
    }
}
