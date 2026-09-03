<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use StreetMesh\Server\Domicile\Avatars\Avatar;
use StreetMesh\Server\Domicile\Avatars\Avatars;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Identity\Identity;

new
#[Title('Avatar settings')]
class extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $picture = null;

    public ?TemporaryUploadedFile $model = null;

    public string $name = '';

    public string $trouble = '';

    /**
     * Whoever is signed in, as somebody with an address rather than an account.
     *
     * Null for a person who has one but not the other — an account here without
     * a name under this server's own. They have nowhere to publish a face yet,
     * and the screen says so instead of failing.
     */
    public function resident(): ?Identity
    {
        $user = Auth::user();

        return $user === null ? null : app(Identities::class)->forUser($user);
    }

    /**
     * Every avatar this resident keeps, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Avatar>
     */
    public function wardrobe()
    {
        $resident = $this->resident();

        return $resident === null
            ? new \Illuminate\Database\Eloquent\Collection
            : app(Avatars::class)->allFor((string) $resident->did);
    }

    /** Wear one of them. */
    public function wear(int $id): void
    {
        $resident = $this->resident();

        if ($resident === null) {
            return;
        }

        $avatar = Avatar::query()->where('did', (string) $resident->did)->find($id);

        if ($avatar !== null) {
            app(Avatars::class)->prefer($avatar);
        }
    }

    public function avatar(): ?Avatar
    {
        $resident = $this->resident();

        return $resident === null ? null : app(Avatars::class)->defaultFor((string) $resident->did);
    }

    /**
     * Where the rest of the world looks.
     *
     * Deliberately the real address rather than a path on this page: what is
     * shown here should be the same bytes a venue would fetch, from the same
     * host, so that a picture appearing here and nowhere else is visible as the
     * problem it is. The content's name rides along so that a browser holding
     * the previous face does not show it back after a change.
     */
    public function published(): ?string
    {
        $avatar = $this->avatar();
        $resident = $this->resident();

        return $resident === null
            ? null
            : 'https://'.$resident->handle.'/avatar/icon'
                .($avatar === null ? '' : '?'.$avatar->icon_cid);
    }

    /**
     * Where the body is, when there is one.
     *
     * The other published address, and null rather than a link when nothing
     * has been built -- `/avatar` answers 404 in that case, and offering a
     * link to it would be this screen implying something is there.
     */
    public function publishedModel(): ?string
    {
        $avatar = $this->avatar();
        $resident = $this->resident();

        return $resident === null || $avatar?->model_cid === null
            ? null
            : 'https://'.$resident->handle.'/avatar?'.$avatar->model_cid;
    }

    public function save(): void
    {
        $this->trouble = '';

        /*
         * Kilobytes, and deliberately the same ceilings the blob store keeps:
         * a file refused there is refused after it has been uploaded, decoded
         * and half stored, which is a slow way to be told no.
         */
        $this->validate([
            'picture' => ['required', 'image', 'max:8192'],
            'model' => ['nullable', 'file', 'max:16384'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        $resident = $this->resident();

        if ($resident === null) {
            return;
        }

        /*
         * Reported rather than thrown. Everything `Icon` refuses is something
         * about the file somebody just chose — too large to decode, not really
         * an image, unreadable — and every one of those is a sentence for the
         * person looking at the screen rather than a stack trace.
         */
        try {
            app(Avatars::class)->adopt(
                $resident,
                (string) $this->picture?->get(),
                $this->model === null ? null : (string) $this->model->get(),
                trim($this->name),
            );
        } catch (\RuntimeException $refused) {
            $this->trouble = $refused->getMessage();

            return;
        }

        $this->reset('picture', 'model', 'name');
    }
};?>

{{--
    Choosing a face.

    A settings screen rather than one of this package's own, which is why it
    wears the host's settings chrome: `@include('partials.settings-heading')`
    and `<x-pages::settings.layout>` both belong to the application. That is the
    same arrangement every other screen here already has — this package ships
    screens written against the Livewire starter kit's layout, and a package
    that framed its own would look like a different site one click in.

    The point of the copy is the sentence under the heading: this is not a
    setting on this server. It is a thing published at the resident's own
    address, which is why anywhere they go can show it without asking here.
--}}
<section class="w-full">
    @include('partials.settings-heading')

    @php($resident = $this->resident())

    <x-pages::settings.layout
        :heading="__('Avatars')"
        :subheading="__('What you look like, published at your own address')"
    >
        @if ($resident === null)
            <flux:callout icon="user-group">
                <flux:callout.heading>{{ __('No address yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('An avatar is published at your address, and you do not have one on this server yet.') }}
                </flux:callout.text>
            </flux:callout>
        @else
            <div class="flex flex-col gap-6">
                <div class="flex items-center gap-4">
                    {{--
                        The letter is what everywhere else falls back to, so it
                        is what this falls back to. Somebody with no avatar
                        should be looking at exactly what a party is showing on
                        their behalf.
                    --}}
                    <flux:avatar
                        size="xl"
                        circle
                        :src="$this->published()"
                        :name="$resident->handle"
                        initials:single
                    />

                    <div class="flex min-w-0 flex-col gap-1">
                        <flux:text class="font-mono break-all">{{ $resident->handle }}/avatar/icon</flux:text>
                        <flux:text variant="subtle" size="sm">
                            @if ($this->avatar() === null)
                                {{ __('Your letter, drawn by this server until you publish a picture.') }}
                            @else
                                {{ __('Anybody may fetch this. Nobody has to ask.') }}
                            @endif
                        </flux:text>

                        @if ($this->publishedModel() !== null)
                            <flux:text class="font-mono break-all">{{ $resident->handle }}/avatar</flux:text>
                            <flux:text variant="subtle" size="sm">
                                {{ __('Your body, for places that draw one.') }}
                            </flux:text>
                        @endif
                    </div>
                </div>

                @if ($this->wardrobe()->count() > 1)
                    {{--
                        The ones already made.

                        Every choice writes a record and the old ones stand, so
                        a resident who has built four faces has four -- this is
                        where they pick which one everywhere else draws. The
                        icons come from the resident's own address rather than
                        from this page, so what is shown here is what a venue
                        would fetch.
                    --}}
                    <div class="flex flex-col gap-2">
                        <flux:subheading>{{ __('Yours') }}</flux:subheading>

                        <div class="flex flex-wrap gap-3">
                            @foreach ($this->wardrobe() as $kept)
                                <button
                                    type="button"
                                    wire:click="wear({{ $kept->id }})"
                                    class="flex flex-col items-center gap-1"
                                    title="{{ $kept->name }}"
                                >
                                    <flux:avatar
                                        size="lg"
                                        circle
                                        :src="route('streetmesh.blob.get', ['did' => $kept->did, 'cid' => $kept->icon_cid])"
                                        :name="$kept->name"
                                        :class="$kept->is_default ? 'ring-2 ring-accent' : 'opacity-60'"
                                    />
                                    <flux:text size="sm" :variant="$kept->is_default ? null : 'subtle'">
                                        {{ $kept->name }}
                                    </flux:text>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form wire:submit="save" class="flex flex-col gap-4">
                    <flux:input
                        type="file"
                        wire:model="picture"
                        accept="image/*"
                        :label="__('Picture')"
                        :description="__('Cropped square from the middle and re-encoded here, so what is published is this server\'s own copy.')"
                    />

                    <flux:input
                        type="file"
                        wire:model="model"
                        accept=".vrm,.glb,model/gltf-binary"
                        :label="__('Body')"
                        :description="__('A VRM. Kept as it arrived — this server cannot rewrite a model the way it rewrites a picture — and served at your address for spatial places to put you in.')"
                    />

                    <flux:input
                        wire:model="name"
                        :label="__('Alias')"
                        :placeholder="__('Me')"
                        :description="__('Nobody else sees this.')"
                    />

                    @if ($trouble !== '')
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.text>{{ $trouble }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Publish') }}</flux:button>
                        <flux:text wire:loading wire:target="save" variant="subtle" size="sm">
                            {{ __('Publishing…') }}
                        </flux:text>
                    </div>
                </form>
            </div>
        @endif
    </x-pages::settings.layout>
</section>
