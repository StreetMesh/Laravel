{{--
    The party tab.

    Laid out like a conversation rather than a form: the chat fills the pane and
    keeps its composer at the foot, exactly as the room's does, and everything
    about the party itself lives in one strip beneath it.

    That strip is mostly shut. Who is in a party and what the code is are things
    you need occasionally and read once; the conversation is what you are here
    for, so it gets the room.
--}}
@if (! $this->offered)
    <div class="p-3">
        <flux:text>{{ __('This venue does not do parties.') }}</flux:text>
    </div>
@elseif ($this->party === null)
    <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-3">
        @error('party')
            <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @enderror

        {{-- Somebody is knocking. Answered before anything else, because it is
             the part about to change what the rest of this says. --}}
        @foreach ($this->invitations as $invitation)
            <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="asked-{{ $invitation->id }}">
                <flux:text>{{ __(':who asked you into their party.', ['who' => $invitation->invited_by_name]) }}</flux:text>

                <div class="flex gap-2">
                    <flux:button size="sm" variant="primary" wire:click="accept({{ $invitation->id }})">{{ __('Join them') }}</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="decline({{ $invitation->id }})">{{ __('No thanks') }}</flux:button>
                </div>
            </div>
        @endforeach

        <flux:button icon="user-group" wire:click="start">{{ __('Start a party') }}</flux:button>

        <flux:separator text="{{ __('or') }}" />

        <form wire:submit="join" class="flex gap-2">
            <flux:input
                wire:model="joining"
                :placeholder="__('Code')"
                autocomplete="off"
                maxlength="8"
                class="flex-1 uppercase"
            />
            <flux:button type="submit">{{ __('Join') }}</flux:button>
        </form>
    </div>
@else
    {{--
        The conversation, which now gets the whole pane.

        It used to share it with a drawer that rose over the bottom, and to fade
        to half while that was open — a way of saying "this is not the thing you
        are talking to" that a reader had to interpret rather than see. The
        settings are a screen of their own now, so there is nothing to share
        with and nothing to say: what is on screen is what is being talked to.

        Text layers where voice supersedes: somebody cut off from the room's
        chat would miss whatever everybody around them is reacting to.
    --}}
    <div class="flex min-h-0 flex-1 flex-col">
        @livewire('venue::chat', [
            'space' => $this->party->room(),
            'placeholder' => __('Say something to your party'),
        ], key('party-'.$this->party->key))
    </div>

@endif
