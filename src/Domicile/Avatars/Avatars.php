<?php

namespace StreetMesh\Server\Domicile\Avatars;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use StreetMesh\Server\Protocol\Blobs\BlobStore;
use StreetMesh\Server\Protocol\Identity\Identity;
use StreetMesh\Server\Protocol\Records\Record;
use StreetMesh\Server\Protocol\Records\RecordStore;

/**
 * Somebody deciding what they look like.
 *
 * An avatar is a thing a person asserts about themselves, which is what makes
 * it a different shape from everything else this server keeps. A chess result
 * is something that *happened to* somebody, and a venue signs it because the
 * venue is the only party in a position to say — that is an attestation, and
 * the record wraps one. Nobody attests to a face. There is no third party who
 * could, and a signature over one would be a signature over an opinion.
 *
 * So this writes a record with nothing nested inside it: the resident's own
 * key, over their own claim, in their own store. It is the first record here of
 * that kind and it will not be the last.
 *
 * What makes it checkable is not a signature but an address. Only the server
 * answering for `collegeman.stme.sh` can put a picture at
 * `collegeman.stme.sh/avatar/icon`, which is the same reason a published mark
 * is evidence about a venue rather than a claim about one.
 */
final readonly class Avatars
{
    /**
     * One name for two things: the collection the records go in, and the kind
     * of thing the bytes were kept for.
     *
     * `actor` rather than `profile` or `identity`, because ATProtocol already
     * groups what a person looks like and calls themselves under that word, and
     * a name their software half-recognises is worth more than a better one
     * nobody has seen.
     */
    public const COLLECTION = 'com.streetmesh.actor.avatar';

    public function __construct(
        private RecordStore $records,
        private BlobStore $blobs,
    ) {}

    /**
     * Take on a new face.
     *
     * The uploaded picture is re-encoded before anything else happens, so what
     * is stored, named and served is this server's own PNG rather than a
     * stranger's file. See `Icon`. A model cannot be re-encoded and is checked
     * instead; see `Model`, which says at length why that is a weaker promise.
     *
     * One transaction, because a projection pointing at a record that was never
     * written is worse than either failing: the permalink would answer with a
     * name nothing holds.
     */
    public function adopt(
        Identity $resident,
        string $uploaded,
        ?string $model = null,
        string $name = '',
    ): Avatar {
        $icon = Icon::from($uploaded);
        $body = $model === null ? null : Model::from($model);
        $did = (string) $resident->did;

        return DB::transaction(function () use ($did, $icon, $body, $name): Avatar {
            $blob = $this->blobs->put($did, $icon->bytes, self::COLLECTION);
            $model = $body === null ? null : $this->blobs->put($did, $body->bytes, self::COLLECTION);

            $record = $this->records->put($did, self::COLLECTION, [
                'name' => $name === '' ? __('Me') : $name,

                /*
                 * ATProtocol's shape for referring to bytes from inside a
                 * record, rather than a bare string of ours. Their software
                 * already knows how to follow one of these, and a record only
                 * somebody here can read is a record that has not left.
                 */
                'icon' => $blob->reference(),

                // The other half, for as long as somebody has built one.
                'model' => $model?->reference(),

                'createdAt' => now()->toIso8601ZuluString(),
            ]);

            return $this->project($did, $record);
        });
    }

    /**
     * Make the index agree with a record.
     *
     * Split out from writing one, because a resident is not the only party who
     * can write their face: a venue they granted permission to may write one
     * over the wire, and that record has to reach this table by the same route
     * or the projection would describe whichever avatar happened to be adopted
     * here. So this reads a record and nothing else, which is also what makes
     * the migration's promise true -- every column derived from a record, and
     * every one of them rebuildable by replaying them.
     *
     * Replacing rather than adding, because a resident may keep one. The record
     * it was projected from is not replaced: it stays, and so does the one
     * before it, which is how a person can see what they used to look like.
     */
    public function project(string $did, Record $record): Avatar
    {
        return Avatar::query()->updateOrCreate(['did' => $did], [
            'rkey' => $record->rkey,
            'name' => (string) ($record->value['name'] ?? __('Me')),
            'icon_cid' => self::linkIn($record->value, 'icon') ?? '',
            'model_cid' => self::linkIn($record->value, 'model'),
            'is_default' => true,
        ]);
    }

    /**
     * The face to draw for somebody, or none.
     *
     * Null is an ordinary answer rather than a failure — most people have not
     * chosen one, and what every caller does about it is show a letter.
     */
    public function defaultFor(string $did): ?Avatar
    {
        return Avatar::query()->where('did', $did)->where('is_default', true)->first();
    }

    /**
     * Every avatar somebody has written down, oldest first.
     *
     * The history, from the records rather than from the projection, because
     * the projection deliberately keeps only the current one.
     *
     * @return Collection<int, Record>
     */
    public function history(string $did): Collection
    {
        return $this->records->list($did, self::COLLECTION, asStranger: false);
    }

    /**
     * The content name a record's field points at, or null.
     *
     * Tolerant on the way in because this reads records that arrived from
     * somewhere else as well as ones written here, and a field that is missing,
     * null, or the wrong shape all mean the same thing to a reader: there is no
     * picture of that kind. Refusing would be this server deciding a resident
     * has no face because a venue wrote an unfamiliar dialect.
     *
     * @param  array<string, mixed>  $value
     */
    private static function linkIn(array $value, string $field): ?string
    {
        $link = $value[$field]['ref']['$link'] ?? null;

        return is_string($link) && $link !== '' ? $link : null;
    }
}
