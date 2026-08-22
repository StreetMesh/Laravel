<?php

use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;
use StreetMesh\Server\Domicile\Residents\Residents;
use StreetMesh\Server\Protocol\Identity\Identity;

new #[Title('Directory')] class extends Component
{
    public string $search = '';

    /**
     * Everybody who lives here, narrowed by whatever has been typed.
     *
     * @return Collection<int, Identity>
     */
    public function listed(): Collection
    {
        return app(Residents::class)->all($this->search);
    }
};?>

{{--
    No padding of its own. The host's layout already pads the main area — Flux
    applies `p-6 lg:p-8` to it — and a screen that pads again is a screen with
    twice the margins of every other one, which is exactly how this looked.
--}}
<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Directory') }}</flux:heading>

        {{-- Interactive, from a package, with no wiring in the host. --}}
        <flux:input wire:model.live.debounce="search" :placeholder="__('Search')" class="max-w-64" />
    </div>

    @forelse ($this->listed() as $resident)
        {{--
            The name and the identifier, together, because they are two
            different things and the difference is the point of the exercise.

            The name is how anybody reaches them and can be changed. The
            identifier is who they are and cannot — it is what every record they
            ever sign is signed as, and what somebody holding one of those
            records years from now resolves.
        --}}
        <a href="{{ route('domicile.profile', $resident->handle) }}" wire:navigate class="block">
            <flux:card class="flex flex-col gap-1 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <flux:heading>{{ $resident->handle }}</flux:heading>
                <flux:text class="font-mono text-xs break-all">{{ $resident->did }}</flux:text>
            </flux:card>
        </a>
    @empty
        <flux:callout icon="user-group">
            <flux:callout.heading>
                {{ $this->search === '' ? __('Nobody lives here yet') : __('Nobody by that name') }}
            </flux:callout.heading>
            <flux:callout.text>
                {{ $this->search === ''
                    ? __('Residents keep their own records on this server.')
                    : __('Try part of an address.') }}
            </flux:callout.text>
        </flux:callout>
    @endforelse
</div>
