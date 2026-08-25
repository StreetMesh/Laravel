<?php

namespace StreetMesh\Server\Venue\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use StreetMesh\Protocol\Discovery\HandleDoesNotResolve;
use StreetMesh\Protocol\Discovery\IdentityDisownsHandle;
use StreetMesh\Protocol\Discovery\IdentityDoesNotResolve;
use StreetMesh\Server\Protocol\Permissions\Delegations;
use StreetMesh\Server\Venue\Experiences\Experiences;
use StreetMesh\Server\Venue\Visitors;
use Throwable;

/**
 * The door.
 *
 * Somebody arrives having never been here, types a name issued somewhere else,
 * and is sent to their own server to be asked. They come back holding nothing
 * this venue gave them — the permission belongs to them, granted by the server
 * they chose, and can be taken back there without asking us.
 *
 * There is no account to create and no password to invent, which is the whole
 * point rather than a convenience.
 */
final class ConnectController
{
    /**
     * Where anything that went wrong at the door is reported.
     *
     * Deliberately not `handle`, which is the name of the field. Keyed on the
     * field, Flux drew the message under the input *and* the form drew it again
     * in a callout — the same sentence twice, six lines apart. This is one
     * screen with one field, so a refusal is about the attempt rather than
     * about the box, and it is said once.
     */
    public const REFUSAL = 'connect';

    public function __construct(
        private readonly Delegations $delegations,
        private readonly Visitors $visitors,
        private readonly Experiences $experiences,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $handle = strtolower(ltrim(trim((string) $request->input('handle')), '@'));

        if ($handle === '') {
            throw ValidationException::withMessages([self::REFUSAL => __('Type the address you use.')]);
        }

        try {
            $begun = $this->delegations->begin(
                $handle,

                /*
                 * What is installed, rather than what is configured. A venue
                 * whose configuration and packages disagreed would take
                 * somebody through a consent screen and then fail to write the
                 * record it had just promised them.
                 */
                $this->experiences->scopes((array) config('streetmesh.venue.scopes', [])),

                route('venue.callback'),
            );
        } catch (Throwable $failed) {
            /*
             * Said as a sentence about their address rather than as whatever the
             * discovery chain threw, which names documents nobody outside this
             * project has heard of — but *which* sentence matters, because these
             * are four different situations and only one of them is the typo the
             * old single message assumed.
             */
            report($failed);

            throw ValidationException::withMessages([
                self::REFUSAL => $this->refusal($handle, $failed),
            ]);
        }

        return redirect()->away($begun['url']);
    }

    /**
     * What to tell somebody the door would not open for.
     *
     * Four things arrive here and they used to leave as one sentence, which sent
     * people looking for a typo in an address that was often perfectly correct.
     * Matched on type rather than on message text: the sentences inside those
     * exceptions are prose for a log, and making them load-bearing would mean
     * rewording one could quietly change what a visitor is told.
     *
     * The disowned case is deliberately not softened. A name pointing at an
     * identity that does not answer to it is a server claiming something untrue,
     * and dressing that as a misspelling is how a check stops being one.
     */
    private function refusal(string $handle, Throwable $failed): string
    {
        return match (true) {
            $failed instanceof HandleDoesNotResolve => __(
                'Nothing at :handle answers as a StreetMesh address.',
                ['handle' => $handle],
            ),

            $failed instanceof IdentityDisownsHandle => __(
                'The server for :handle gave an identity that does not answer to that name, so this venue will not take it.',
                ['handle' => $handle],
            ),

            $failed instanceof IdentityDoesNotResolve => __(
                ':handle is a real address, but this venue cannot look up the identity behind it.',
                ['handle' => $handle],
            ),

            /*
             * Everything past discovery: a server that will not take a signature
             * this one can make, a request it refused, a network that went away
             * mid-handshake. Nothing the visitor can do about any of it, and
             * telling them to check their spelling would be a lie.
             */
            default => __(
                'Their server would not finish letting you in. Nothing is wrong with :handle — try again shortly.',
                ['handle' => $handle],
            ),
        };
    }

    /**
     * They are back, with an answer.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            /*
             * Deciding not to is not the same as something going wrong.
             *
             * Somebody who pressed Cancel is answered by being let back into
             * the venue, at the menu anybody can read. Returning them to the
             * door with "permission was not given" told them what they had just
             * decided and then asked the same question again, which is how a
             * refusal ends up feeling like a failed attempt.
             *
             * Where they were heading is dropped with it. They abandoned that
             * journey, and a remembered destination outliving the decision
             * would take them somewhere unasked-for on their next arrival.
             */
            if ($request->query('error') === 'access_denied') {
                $request->session()->forget(Visitors::INTENDED_KEY);

                return redirect()->route('venue.experiences');
            }

            /*
             * Anything else did go wrong, and saying so beats leaving somebody
             * at a door wondering whether it worked.
             */
            return redirect()->route('venue.connect')->withErrors([
                self::REFUSAL => __('Your server did not give permission.'),
            ]);
        }

        try {
            $delegation = $this->delegations->complete(
                (string) $request->query('state'),
                (string) $request->query('code'),
                route('venue.callback'),
            );
        } catch (Throwable $failed) {
            report($failed);

            return redirect()->route('venue.connect')->withErrors([
                self::REFUSAL => __('That answer could not be used. Please try arriving again.'),
            ]);
        }

        $this->visitors->seat($request, $delegation);

        $intended = $request->session()->pull(Visitors::INTENDED_KEY);

        return redirect()->to(is_string($intended) ? $intended : route('venue.experiences'));
    }

    /**
     * Giving the permission back, and staying.
     *
     * To the front of the venue rather than to the door. Somebody who has just
     * revoked has not left the building — they are standing in it, holding
     * nothing, and the sensible next thing is the front page, where the way
     * back in is offered if they want it.
     *
     * It used to land on the door itself, which reads as being shown out.
     *
     * The root rather than a route name: the application owns its front page
     * and is free to call it whatever it likes. `home` is what this project's
     * host happens to name it, and a package that assumed so would break in
     * anybody else's.
     */
    public function leave(Request $request): RedirectResponse
    {
        $this->visitors->leave($request);

        return redirect()->to('/');
    }
}
