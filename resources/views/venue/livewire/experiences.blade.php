<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use StreetMesh\Server\Venue\Experiences\Experience;
use StreetMesh\Server\Venue\Experiences\Experiences;

new #[Title('Experiences')] class extends Component
{
    /**
     * Everything this venue can offer.
     *
     * @return array<int, Experience>
     */
    public function offered(): array
    {
        return app(Experiences::class)->all();
    }
};?>

{{--
    No padding of its own. The host's layout already pads the main area — Flux
    applies `p-6 lg:p-8` to it — and a screen that pads again is a screen with
    twice the margins of every other one, which is exactly how this looked.
--}}
<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">{{ __('Experiences') }}</flux:heading>
        <flux:text>{{ __('What there is to do here.') }}</flux:text>
    </div>

    @if ($this->offered() === [])
        <flux:callout icon="squares-2x2">
            <flux:callout.heading>{{ __('Nothing installed yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('An experience is a package: chess is one, and a shop would be another. Installing one puts it here.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->offered() as $experience)
                <flux:card class="flex flex-col gap-3">
                    <flux:icon :name="$experience->icon()" class="size-6" />

                    <div class="flex flex-col gap-1">
                        <flux:heading>{{ $experience->title() }}</flux:heading>
                        <flux:text class="text-sm">{{ $experience->description() }}</flux:text>
                    </div>

                    {{--
                        Two ways in, where an experience offers them.

                        The primary one asks something: taking part means
                        arriving with a name another server issued. The second
                        asks nothing, because looking should not cost what
                        playing costs — a stranger wanting to see what is on met
                        a form asking them to name their own server first, which
                        is a toll nobody pays to look at a chessboard.
                    --}}
                    @php($watching = $experience->watching())

                    <div class="mt-auto flex gap-2">
                        <flux:button :href="route($experience->route())" size="sm" variant="primary" wire:navigate>
                            {{ $experience->action() ?? __('Launch') }}
                        </flux:button>

                        @if ($watching !== null)
                            <flux:button :href="route($watching['route'])" size="sm" wire:navigate>
                                {{ __($watching['label']) }}
                            </flux:button>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
