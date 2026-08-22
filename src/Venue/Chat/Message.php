<?php

namespace StreetMesh\Server\Venue\Chat;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something somebody said, somewhere.
 *
 * @property int $id
 * @property string $space
 * @property string $did
 * @property string $name
 * @property string $body
 * @property Carbon $created_at
 */
class Message extends Model
{
    /**
     * How much of a conversation is worth arriving into.
     *
     * Enough to see what is being talked about, not so much that opening a busy
     * table means reading a morning's backlog before anybody can say hello.
     */
    public const RECENT = 50;

    /**
     * The longest thing anybody can say at once.
     *
     * Not a stylistic opinion. A text column will take a novel and a screen
     * will not, and the failure of leaving it open is one person able to make
     * everybody else's chat unusable.
     */
    public const LONGEST = 2000;

    protected $table = 'streetmesh_chat_messages';

    protected $guarded = [];

    /**
     * The tail of a conversation, oldest first.
     *
     * Fetched newest-first and turned around, because "the last fifty" is a
     * question about the end of a list and reading the whole thing to find it
     * is how a busy space gets slow.
     *
     * @return Collection<int, Message>
     */
    public static function recentlyIn(string $space): Collection
    {
        return self::query()
            ->where('space', $space)
            ->latest('id')
            ->limit(self::RECENT)
            ->get()
            ->reverse()
            ->values();
    }
}
