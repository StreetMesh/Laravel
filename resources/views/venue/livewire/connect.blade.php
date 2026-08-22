<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Venue\Http\ConnectController;

/*
 * A door, not a page inside the building.
 *
 * No sidebar, no navigation, nothing behind it. It used to render into the
 * application shell and compensate with a narrow column, which meant somebody
 * who had not arrived yet was shown the furniture of a place they were not in.
 *
 * Its own frame rather than the host's auth layout, which it borrowed for a
 * while on the grounds that both are a form on an empty page. They are not the
 * same moment: that one signs you in to this server, this one takes a name
 * another server issued. Sharing a frame made the venue's door look like a
 * login screen, which is the exact confusion the whole /connect path exists to
 * avoid.
 *
 * Named rather than assumed. A package cannot draw its own chrome, and saying
 * which frame it wants is the contract — see the stub in tests/fixtures.
 */
new #[Layout('layouts::door')] #[Title('Connect')] class extends Component
{
    public string $handle = '';

    /**
     * The address of whoever is signed in here, or none.
     *
     * A server can be both a domicile and a venue, and when it is, the person
     * at this form usually already lives here — asking them to type an address
     * this server issued them is asking a question we can answer. It stays a
     * text field, though, because living here is no reason to be unable to
     * arrive as somebody else.
     *
     * Empty on a venue-only server, where nobody is ever signed in.
     */
    public function mine(): string
    {
        $user = auth()->user();

        if ($user === null) {
            return '';
        }

        return (string) (app(Identities::class)->forUser($user)?->handle ?? '');
    }

    /**
     * Where to send somebody who has no address yet, or nowhere.
     *
     * A venue houses nobody, so the only honest answer to "I do not have one of
     * those" is the name of a server that does. Which one is the operator's,
     * and a venue that names none simply does not make the offer.
     *
     * Nowhere, too, for somebody already signed in here: a server can be both a
     * domicile and a venue, and telling a resident of this one to go and get an
     * account is telling them to do what they have already done.
     */
    public function elsewhere(): string
    {
        if ($this->mine() !== '') {
            return '';
        }

        $domicile = trim((string) config('streetmesh.venue.domicile', ''));

        return $domicile === '' ? '' : 'https://'.$domicile.'/register';
    }
};?>

{{--
    The layout gives this a column and a width. One field, one decision,
    nothing to scan.
--}}
<div class="flex w-full flex-col gap-6">
    {{--
        Ranged left, not centred.

        Centred text is what you do to a card floating in the middle of a
        screen. In a column with a form under it, every line starting at the
        same place as the field is the thing that makes it read as one form
        rather than a title and then some furniture.
    --}}
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">{{ __('Connect') }}</flux:heading>
        <flux:text>{{ __('Sign in with your StreetMesh account.') }}</flux:text>
    </div>

    {{--
        A plain form post rather than a Livewire action, because what happens
        next is a redirect to somebody else's server. Livewire would have to be
        told to do that, and a form already knows how.
    --}}
    {{--
        The wait is real and worth showing.

        What happens after this is not a page on this server: the venue goes and
        finds the server named in the field, asks it what it supports and starts
        a handshake with it — one that can take a moment, or considerably longer
        if that server is slow, distant or asleep. A button that stays bright
        and pressable through all of it invites a second press, and a second
        press starts a second handshake.

        Flux draws the waiting state itself, given the one thing it cannot know:
        a submit button that carries `disabled` swaps its label for a spinner
        and stops taking clicks. So there is nothing to draw here — only
        something to say when.
    --}}
    <form
        method="POST"
        action="{{ route('venue.connect.start') }}"
        class="flex flex-col gap-4"
        x-data
        x-on:submit="$refs.go.disabled = true"

        {{--
            And undone on the way back.

            The next thing this form does is leave for another server, so Back
            is a route people genuinely take — and a browser restoring this page
            from its cache restores the DOM exactly as it left, spinner and all.
            Without this, arriving back here shows a door that is already
            busying itself with a handshake nobody started.
        --}}
        x-on:pageshow.window="$refs.go.disabled = false"
    >
        @csrf

        {{--
            The label is read and not shown.

            There is one field on this screen and the placeholder already says
            what shape the answer takes, so a heading above it is a word nobody
            reads twice. A real `<label>` rather than `aria-label`, because
            screen readers are not the only thing that follows one — clicking it
            still focuses the field, and it survives translation.
        --}}
        <flux:field>
            {{--
                `for` and `id` written out rather than left to Flux, which pairs
                them in the browser. A label that is only associated once
                JavaScript has run is a label that is not associated in the
                markup, and this one is invisible — so if the pairing ever
                failed there would be nothing on screen to notice it by.
            --}}
            <flux:label class="sr-only" for="handle">{{ __('Your address') }}</flux:label>

            {{--
                A house, inset in the field.

                The one thing this box wants is the address of the server
                somebody lives at, and the icon says "home" before the
                placeholder has to spell it out — which matters most to the
                person who has never seen a handle-shaped address before and is
                deciding what kind of thing is being asked for.
            --}}
            <flux:input
                id="handle"
                name="handle"
                icon="home"
                placeholder="username.stme.sh"
                :value="old('handle', $this->mine())"
                autofocus
                autocomplete="username"
                autocapitalize="off"
                spellcheck="false"
            />
        </flux:field>

        @error(ConnectController::REFUSAL)
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        <flux:button type="submit" variant="primary" class="w-full" x-ref="go">
            {{ __('Continue') }}
        </flux:button>
    </form>

    @if ($this->elsewhere() !== '')
        {{--
            The other way in, for somebody who has nothing to type.

            Outside the form on purpose — it is a link, not a second thing the
            form can do, and a button inside a form is one stray `type` away
            from submitting it.

            Separated and worded as a question, because these two are not a
            pair of equal choices: almost everybody here has an address and
            wants the field. This is for the person who does not, and it should
            be findable without competing with the thing above it.
        --}}
        <flux:separator :text="__('No address yet?')" />

        <flux:button :href="$this->elsewhere()" class="w-full">
            {{ __('Create an account') }}
        </flux:button>
    @endif
</div>
