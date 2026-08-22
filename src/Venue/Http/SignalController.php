<?php

namespace StreetMesh\Server\Venue\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StreetMesh\Protocol\PublishedAvatar;
use StreetMesh\Server\Venue\Media\Mailbox;
use StreetMesh\Server\Venue\Media\Presence;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Parties\Party;
use StreetMesh\Server\Venue\Visitors;

/**
 * Carrying the few notes that let two browsers in a party find each other.
 *
 * Ordinary HTTP, deliberately. This is not gameplay and it is not hot: an offer,
 * an answer, a handful of addresses, and then it is over for good — after which
 * the audio and video go directly between the two browsers and nothing arrives
 * here at all.
 *
 * Being in the party is the whole of the access control, checked on both verbs.
 * A party is private by construction, so anybody who is not in one has no
 * business either reading what is waiting in it or leaving anything there.
 *
 * It carries who is here as well, on the same request. That is not thrift for
 * its own sake: presence and notes arriving together is what stops a note ever
 * being early — there is no moment when an offer has landed from somebody this
 * browser has not been told about yet.
 *
 * Polled rather than pushed, which is what it should stop being. The server this
 * runs on has Reverb configured, and the cost of asking grows with the number of
 * people in the party — see the note in `decisions/parties.md`.
 */
final class SignalController
{
    public function __construct(
        private readonly Parties $parties,
        private readonly Visitors $visitors,
        private readonly Mailbox $mailbox,
        private readonly Presence $presence,
    ) {}

    /**
     * Take everything waiting for this connection, and hear who else is here.
     *
     * Asking is also how a browser says it is still here — there is nothing to
     * keep alive beyond the poll that was already running. A browser that stops
     * asking stops being mentioned, which is the whole of leaving by accident.
     *
     * The name is the venue's word, read from whoever is making the request
     * rather than from anything they sent. A browser names its own connection,
     * because that is a tab and nobody else can know it; it does not get to name
     * the person, because the person was settled at the door.
     */
    public function collect(Request $request, string $key): JsonResponse
    {
        $party = $this->partyFor($request, $key);

        if ($party === null) {
            return $this->refuse();
        }

        $as = (string) $request->string('as');

        $answer = ['signals' => $this->mailbox->drain($party->room(), $as)];

        if ($as !== '') {
            $answer += $this->presence->seen(
                $party->room(),
                $as,
                (string) $this->visitors->current($request)?->handle,
                (string) $request->string('at'),
            );

            $answer['present'] = array_map($this->withFace(...), $answer['present']);
        }

        return response()->json($answer);
    }

    /**
     * Where to fetch somebody's picture, worked out here rather than in a
     * browser.
     *
     * A handle is a hostname, so the address follows from the name and nothing
     * has to be sent by the person it belongs to — which is the point, and is
     * why `PublishedMark` refuses to read a venue's logo out of a document it
     * was handed. But the *derivation* is not something to do twice. Turning a
     * name that arrived over the wire into an address a browser is sent to has
     * exactly one correct implementation, and a second one written in
     * JavaScript would be a second definition of a rule that has to hold.
     *
     * So the venue says it, from its own word for who somebody is — the same
     * handle it already vouches for two lines above. The browser is handed an
     * address and never builds one.
     *
     * Null for a name that is not a hostname, and null is drawn as a letter.
     *
     * @param  array{id: string, name: string, space: string}  $who
     * @return array{id: string, name: string, space: string, icon: string|null}
     */
    private function withFace(array $who): array
    {
        return $who + ['icon' => PublishedAvatar::iconAt($who['name'])];
    }

    /**
     * Leave a note for somebody else in the party.
     *
     * Who it is from is taken from the request rather than from the session,
     * and that is not a hole: everybody who can reach this is in the same
     * private party, and what is being named is a browser tab rather than a
     * person. The identity that matters was settled at the door.
     */
    public function post(Request $request, string $key): JsonResponse
    {
        $party = $this->partyFor($request, $key);

        if ($party === null) {
            return $this->refuse();
        }

        /*
         * Going, said out loud. Answered before anything about notes, because a
         * departure names nobody to be for and would otherwise be refused for
         * lacking one.
         */
        if ($request->boolean('gone')) {
            $this->presence->gone($party->room(), (string) $request->string('from'));

            return response()->json(['left' => true]);
        }

        $to = (string) $request->string('to');

        if ($to === '') {
            return response()->json(['error' => 'A note needs somebody to be for.'], 422);
        }

        /** @var array<string, mixed> $note */
        $note = $request->array('data');

        $this->mailbox->post(
            $party->room(),
            (string) $request->string('from'),
            $to,
            $note,
        );

        return response()->json(['left' => true]);
    }

    /**
     * The party this request is about, if the person making it is in it.
     *
     * One question rather than two, because every answer here is the same: a
     * party that does not exist and a party you are not in are indistinguishable
     * from outside, and telling them apart would say whether a given party
     * exists to somebody with no business knowing.
     */
    private function partyFor(Request $request, string $key): ?Party
    {
        $visitor = $this->visitors->current($request);

        if ($visitor === null) {
            return null;
        }

        $party = Party::query()->keyed($key)->whereNull('disbanded_at')->first();

        if ($party === null || $this->parties->memberOf($party, $visitor) === null) {
            return null;
        }

        return $party;
    }

    private function refuse(): JsonResponse
    {
        return response()->json(['error' => 'There is no party of yours by that name.'], 404);
    }
}
