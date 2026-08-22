<?php

namespace StreetMesh\Server\Venue\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StreetMesh\Server\Protocol\Permissions\Delegation;
use StreetMesh\Server\Venue\Media\Peer;
use StreetMesh\Server\Venue\Parties\Invitation;
use StreetMesh\Server\Venue\Parties\Parties;
use StreetMesh\Server\Venue\Parties\Party;
use StreetMesh\Server\Venue\Visitors;
use Throwable;

/**
 * Starting a party, asking somebody into it, and answering.
 *
 * Everything here is behind the door, and that is the whole of the access
 * control: a party is invite-only, so being in one is the only thing that lets
 * you do anything to it, and every method below asks `Parties` rather than
 * deciding for itself.
 *
 * Refusals come back as their own sentences rather than as codes. What goes
 * wrong here is social — the party filled up, the invitation went stale,
 * somebody is already in one — and every one of those is worth reading.
 */
final class PartyController
{
    public function __construct(
        private readonly Parties $parties,
        private readonly Visitors $visitors,
    ) {}

    /** Start one, with the person starting it already in it. */
    public function open(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request): array {
            $party = $this->parties->open($this->visitor($request));

            return ['party' => $party->key, 'room' => $party->room()];
        });
    }

    /**
     * A way into the party's own room.
     *
     * Separate from the gathering ticket, because they are two rooms and
     * somebody in a party is in both at once — one for what they are doing, one
     * for who they are doing it near.
     */
    public function ticket(Request $request, string $key): JsonResponse
    {
        $party = Party::query()->keyed($key)->first();

        if ($party === null) {
            return response()->json(['error' => 'There is no party by that name.'], 404);
        }

        return $this->attempt(function () use ($party, $request): array {
            $this->parties->vouchFor($party, $this->visitor($request));

            return [
                /*
                 * The party's name, which is where its notes are left and where
                 * its conversation is kept. Nothing joins anything by it any
                 * more; it is a space rather than a room.
                 */
                'room' => $party->room(),

                /*
                 * How a browser gets through its own router. A property of
                 * peer-to-peer rather than an operator's decision, so it is
                 * sent rather than configured — see `Media\Peer`.
                 */
                'ice' => Peer::ice(),
            ];
        });
    }

    /**
     * Ask somebody in, by the identifier the room they are both in shows.
     *
     * A DID rather than a handle. What a screen has to hand is whatever the
     * presence roster gave it, and that is the venue's own word about who
     * somebody is — a handle typed into a box would be a name somebody could
     * choose to be somebody else's.
     */
    public function invite(Request $request, string $key): JsonResponse
    {
        $party = Party::query()->keyed($key)->first();

        if ($party === null) {
            return response()->json(['error' => 'There is no party by that name.'], 404);
        }

        $did = (string) $request->string('did');

        return $this->attempt(function () use ($request, $party, $did): array {
            $invitation = $this->parties->invite(
                $party,
                $this->visitor($request),
                $did,
                (string) $request->string('name'),
            );

            return ['invitation' => $invitation->id, 'expires' => $invitation->expires_at->toIso8601String()];
        });
    }

    public function accept(Request $request, Invitation $invitation): JsonResponse
    {
        return $this->attempt(function () use ($request, $invitation): array {
            $this->parties->accept($invitation, $this->visitor($request));

            return ['party' => $invitation->party->key, 'room' => $invitation->party->room()];
        });
    }

    public function decline(Request $request, Invitation $invitation): JsonResponse
    {
        return $this->attempt(function () use ($request, $invitation): array {
            $this->parties->decline($invitation, $this->visitor($request));

            return ['declined' => true];
        });
    }

    /**
     * Walk out.
     *
     * Idempotent on purpose: leaving a party you are not in is not an error,
     * it is the state you were asking for.
     */
    public function leave(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request): array {
            $visitor = $this->visitor($request);
            $party = $this->parties->partyOf($visitor);

            if ($party !== null) {
                $this->parties->leave($party, $visitor);
            }

            return ['left' => true];
        });
    }

    /**
     * Whoever is here, which the middleware has already made certain of.
     *
     * Asserted rather than checked again. `visitor` runs in front of every
     * route below and sends anybody without a permission to the door, so a null
     * here would mean the route was wired up wrong — which is worth failing
     * loudly for rather than answering as though nobody was home.
     */
    private function visitor(Request $request): Delegation
    {
        $visitor = $this->visitors->current($request);

        if ($visitor === null) {
            abort(403, 'Nobody is here.');
        }

        return $visitor;
    }

    /**
     * Run something that may refuse, and say why if it does.
     *
     * Every refusal in `Parties` is a sentence somebody should read, so they
     * all come back the same way rather than each caller remembering to catch.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (Throwable $refused) {
            return response()->json(['error' => $refused->getMessage()], 403);
        }
    }
}
