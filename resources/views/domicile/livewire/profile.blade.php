<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;

new class extends Component
{
    public string $handle = '';

    public function mount(string $handle): void
    {
        $this->handle = strtolower(trim($handle));
    }

    /**
     * Whoever this page is about, or nobody.
     *
     * Only residents. The server has an identity of its own and it is not a
     * person — asking for it by name should find nothing here rather than a
     * profile for a machine.
     */
    public function resident(): ?Identity
    {
        $identity = app(Identities::class)->byHandle($this->handle);

        return $identity?->is_server ? null : $identity;
    }

    /**
     * Where their picture is, if they have published one.
     *
     * Their address rather than a path here, because that is the address
     * everybody else fetches from — a profile showing a copy this server had
     * lying around would be vouching for something rather than pointing at it.
     */
    public function face(): ?string
    {
        $resident = $this->resident();

        if ($resident === null) {
            return null;
        }

        /*
         * Always, for somebody who lives here. The address answers for every
         * resident — with their picture if they have one and their letter if
         * they have not — so there is no case where this page has to decide
         * what to draw. It points, and their server draws.
         *
         * The content's name rides along when there is one, so a browser
         * holding the previous face does not show it back after a change.
         */
        $avatar = app(Avatars::class)->defaultFor((string) $resident->did);

        return 'https://'.$resident->handle.'/avatar/icon'
            .($avatar === null ? '' : '?'.$avatar->icon_cid);
    }

    public function title(): string
    {
        return $this->resident()?->handle ?? __('Nobody here');
    }
};?>

{{--
    One resident, at an address anybody can link to.

    This is where `collegeman.stme.sh` sends somebody. That name is a hostname,
    and a hostname is for machines resolving a handle — a person typing it into
    a browser is asking about a person, and gets sent here.
--}}
<div class="flex flex-col gap-6">
    @php($resident = $this->resident())

    @if ($resident === null)
        <flux:callout icon="user-group">
            <flux:callout.heading>{{ __('Nobody here') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Nobody at this server goes by that name.') }}
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('domicile.directory')" size="sm" variant="outline" wire:navigate>
                    {{ __('Directory') }}
                </flux:button>
            </x-slot>
        </flux:callout>
    @else
        {{--
            The face, beside the name.

            This is the other half of what serving an avatar from a resident's
            own address buys: somebody who has met "collegeman" somewhere else
            can come here and see whether that is what collegeman looks like.
            A picture anybody could have set on any server would prove nothing;
            this one is served by the only server that answers for the name.
        --}}
        <div class="flex items-center gap-4">
            <flux:avatar
                size="xl"
                circle
                :src="$this->face()"
                :name="$resident->handle"
                initials:single
            />

            <flux:heading size="xl" class="break-all">{{ $resident->handle }}</flux:heading>
        </div>

        {{--
            The name and the identifier, together, because they are two
            different things and the difference is the point of the exercise.

            The name is how anybody reaches them and can be changed. The
            identifier is who they are and cannot — it is what every record they
            ever sign is signed as, and what somebody holding one of those
            records years from now resolves.
        --}}
        <flux:card class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <flux:text class="text-xs uppercase">{{ __('Address') }}</flux:text>
                <flux:text class="font-mono break-all">{{ $resident->handle }}</flux:text>
            </div>

            <div class="flex flex-col gap-1">
                <flux:text class="text-xs uppercase">{{ __('Identifier') }}</flux:text>
                <flux:text class="font-mono text-xs break-all">{{ $resident->did }}</flux:text>
            </div>
        </flux:card>
    @endif
</div>
