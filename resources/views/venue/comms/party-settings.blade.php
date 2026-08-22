{{--
    Everything about the party itself, as a screen rather than a strip.

    This was a drawer: a panel that slid up over the bottom of the conversation
    while the tabs stayed lit and the chat stayed visible behind it. Nothing on
    screen said which of the two was being talked to except that one of them was
    faded, which is a state somebody has to work out rather than see — and the
    mistake it invited was typing into a conversation that was not listening. The
    fade came off, and then the thing behind was live, which was worse.

    So it takes the panel. The header says what this is and the caret in the
    corner puts it back; see the note beside that header. The row of comms
    buttons stays below, because a microphone is not something to have to leave a
    screen to reach.

    Only reached with a party in hand — `settings()` will not open it otherwise.
--}}
<div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3">
    @error('party')
        <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
    @enderror

    {{--
        Faces beside names, fetched from each person's own server.

        Flux draws initials when there is no `src`, which is the same
        fallback the row of circles makes and for the same reason: most
        domiciles publish nothing, and a party where three people are
        letters and one is a picture is the ordinary case rather than a
        half-finished one.

        `initials:single` because a handle is not a name. Flux reads a
        string with no spaces in it as one word and takes two letters
        from it — `alice.home.test` becomes "Al" — which is right for a
        person called Alice Smith and wrong for an address. One letter
        is what everything else placeholding for a picture does, and it
        is what the circles beside this already draw.
    --}}
    <div class="flex flex-col gap-1">
        @foreach ($this->roster as $member)
            <div class="flex items-center justify-between gap-2" wire:key="with-{{ $member->did }}">
                <div class="flex min-w-0 items-center gap-2">
                    <flux:avatar
                        size="xs"
                        circle
                        :src="\StreetMesh\Protocol\PublishedAvatar::iconAt($member->handle)"
                        :name="$member->handle"
                        initials:single
                    />

                    <flux:text class="truncate">{{ $member->handle }}</flux:text>
                </div>
            </div>
        @endforeach
    </div>

    @if ($this->full)
        <flux:text size="sm">{{ __('This party is as big as it can be.') }}</flux:text>
    @else
        <flux:separator />

        <div class="flex flex-col gap-2">
            <flux:text size="sm">{{ __('Anybody can join with this word:') }}</flux:text>

            <div
                class="flex items-center gap-2"
                x-data="{
                    copied: false,

                    /*
                        Read off the page rather than captured.

                        `x-data` runs once, when this element is made,
                        so a word baked into it here stays the word it
                        was then — and pressing New word morphs a fresh
                        one into the span while the button goes on
                        copying the old one. The span is what Livewire
                        keeps current, so the span is what is asked.
                    */
                    copy () {
                        const word = this.$refs.word?.textContent.trim()

                        if (! word) {
                            return
                        }

                        const said = () => {
                            this.copied = true

                            setTimeout(() => (this.copied = false), 1500)
                        }

                        if (navigator.clipboard?.writeText) {
                            navigator.clipboard.writeText(word).then(said).catch(() => this.select())

                            return
                        }

                        this.select()
                    },

                    /*
                        A browser that will not give us the clipboard
                        gets the word selected instead, which is one
                        keystroke rather than a button that did nothing.
                    */
                    select () {
                        const range = document.createRange()

                        range.selectNodeContents(this.$refs.word)

                        const selection = window.getSelection()

                        selection.removeAllRanges()
                        selection.addRange(range)
                    },
                }"
            >
                <span
                    x-ref="word"
                    class="font-mono text-lg tracking-widest text-zinc-900 dark:text-white"
                >{{ $this->party->code }}</span>

                <flux:button size="xs" variant="subtle" @click="copy()">
                    <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy') }}'"></span>
                </flux:button>

                <flux:button size="xs" variant="subtle" wire:click="rotate">{{ __('New word') }}</flux:button>
            </div>

            @if ($this->here->isNotEmpty())
                <form wire:submit="invite" class="flex gap-2">
                    <flux:select wire:model="inviting" size="sm" class="flex-1">
                        <flux:select.option value="">{{ __('Or ask somebody here…') }}</flux:select.option>

                        @foreach ($this->here as $person)
                            <flux:select.option value="{{ $person->did }}">{{ $person->handle }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button size="sm" type="submit">{{ __('Ask') }}</flux:button>
                </form>
            @endif
        </div>
    @endif

    <flux:separator />

    {{--
        The way out, kept in here with everything else about the party
        rather than on show. Leaving is rare and irreversible-ish; the
        things beside it are the ones somebody opened this to do.

        Asks before it acts, the same way resigning a game does — one press
        to say so, a second to mean it. Clicking away is a change of mind
        rather than a commitment.
    --}}
    <div class="flex items-center justify-between gap-2">
        <flux:text size="sm">
            {{ trans_choice('Party of :count|Party of :count', $this->roster->count(), ['count' => $this->roster->count()]) }}
        </flux:text>

        <div class="flex items-center gap-2">
            {{--
                A button that looks like one. It was styled as text, which
                reads as a label somebody has coloured in rather than
                something to press — and the one thing in here that ends
                something should not be the one thing that looks inert.

                Asks before it acts, the same way resigning a game does —
                one press to say so, a second to mean it. Clicking away is a
                change of mind rather than a commitment, and the colour
                changes so the second press is visibly not the first.
            --}}
            <span x-data="{ asking: false }" @click.outside="asking = false">
                <flux:button
                    size="sm"
                    ::variant="asking ? 'danger' : 'filled'"
                    @click="asking ? (asking = false, $wire.leave()) : asking = true"
                >
                    <span x-text="asking ? '{{ __('Really leave?') }}' : '{{ __('Leave party') }}'"></span>
                </flux:button>
            </span>
        </div>
    </div>
</div>
