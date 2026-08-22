{{--
    The comms widget, included once by whatever draws this venue's chrome.

    The three iframes are in the markup rather than created by script, and that
    is what makes them survive a `wire:navigate`: Livewire replaces the body on
    every navigation, so anything appended to it by hand is gone the first time
    somebody follows a link. `@persist` keeps this subtree across the swap,
    which matters more here than it looks — the stage holds live peer
    connections and a microphone, and re-creating an iframe reloads it.

    The script that arranges them runs in this document and does nothing else —
    see `comms/host.js`.
--}}
@if (app(\StreetMesh\Server\Venue\Comms::class)->offered())
    @php
        $comms = app(\StreetMesh\Server\Venue\Comms::class);
        $shape = $comms->shape();
        $palette = $comms->palette();

        $badgeSize = $shape['badge'];

        /* Breathing room around the circle, with the circle centred in it. */
        $pad = $shape['pad'];
        $frame = $badgeSize + $pad;

        /*
         * Where the frame goes, so that the *circle* lands where it was asked
         * to. The frame is larger than the circle by half the padding on every
         * side, so pinning the frame to the corner instead would move the badge
         * every time the padding changed.
         */
        $edge = $shape['margin'] - $shape['lift'];

        /* The same sum on a narrow screen, where the corner is worth less than
           the room a face needs. */
        $tight = $shape['margin_narrow'] - $shape['lift'];

        /*
         * Worked out here rather than inside a directive's arguments. Blade
         * reads those with a parser that counts brackets, and a `view(...)`
         * call with an array in it is more than it can follow — the error it
         * gives names a line in the compiled file and no cause at all.
         */
        $host = $comms->forHost(request());

        $mutedIcon = trim(view('venue::comms.icon', [
            'icon' => 'microphone-slash',
            'class' => 'icon',
        ])->render());

        /*
         * The mark for somebody who cannot be reached.
         *
         * `xmark`, which this set already has. A truer glyph for it would be
         * `link-slash`, and adding one means copying several hundred characters
         * of curve data out of the Font Awesome source exactly — worth doing
         * with that file open, and not worth approximating from memory.
         */
        $unreachableIcon = trim(view('venue::comms.icon', [
            'icon' => 'xmark',
            'class' => 'icon',
        ])->render());
    @endphp

    <style>
        #streetmesh-badge,
        #streetmesh-panel,
        #streetmesh-stage {
            position: fixed;
            border: 0;
            background: transparent;
            color-scheme: normal;
        }

        #streetmesh-badge {
            bottom: {{ $edge }}px;
            right: {{ $edge }}px;
            width: {{ $frame }}px;
            height: {{ $frame }}px;
            z-index: 2147483002;

            /*
                A shadow that follows the circle rather than the frame.

                `drop-shadow` traces what is actually painted — including
                whatever the document inside draws — where `box-shadow` would
                outline this element's square box. It also renders outside the
                element, which is the half of the problem an iframe cannot solve
                for itself.
            */
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, .35));

            /*
                The circle, painted by *this* document rather than by the one
                inside the frame.

                An iframe is empty until its document arrives, so on a full
                reload the badge blinked out and back — persisting the subtree
                covers a navigation and can do nothing about a refresh. Drawing
                the circle here means the corner is already right on first
                paint, and the document loading over it is invisible.

                (Written without naming the directive, because Blade compiles
                one wherever it appears — including inside a CSS comment, which
                is how this stylesheet came to contain a Livewire element and
                every page on the server came to answer 500.)

                A radial stop rather than a border-radius, because the circle is
                smaller than the frame it is centred in.
            */
            background: radial-gradient(
                circle at center,
                {{ $palette['ink'] }} 0 {{ $badgeSize / 2 }}px,
                transparent {{ $badgeSize / 2 }}px
            );
        }

        /* This one is drawn in the page itself, so it reads the page's own
           class rather than being told. */
        .dark #streetmesh-badge {
            background: radial-gradient(
                circle at center,
                {{ $palette['paper'] }} 0 {{ $badgeSize / 2 }}px,
                transparent {{ $badgeSize / 2 }}px
            );
        }

        #streetmesh-panel {
            bottom: {{ $edge + $frame + 12 }}px;
            right: 40px;
            width: {{ (int) config('streetmesh.venue.comms.width', 380) }}px;
            height: {{ (int) config('streetmesh.venue.comms.height', 560) }}px;
            z-index: 2147483000;
            display: none;

            /*
                The edge and the shadow belong to the element rather than to the
                document inside it. An iframe clips its own content, so a shadow
                drawn in there has nowhere to fall.
            */
            border: 1px solid rgba(0, 0, 0, .14);
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, .28), 0 2px 8px rgba(0, 0, 0, .12);
        }

        /* A hairline of black is nothing against a dark page, so the edge
           changes rather than the shadow — which reads on both. */
        .dark #streetmesh-panel {
            border-color: rgba(255, 255, 255, .14);
            box-shadow: 0 12px 40px rgba(0, 0, 0, .6), 0 2px 8px rgba(0, 0, 0, .4);
        }

        #streetmesh-stage {
            bottom: {{ $edge }}px;
            right: {{ $edge + $frame }}px;
            width: 0;
            height: {{ $frame }}px;
            z-index: 2147483001;
            display: none;

            /* The faces get their shadows the same way the badge does, and for
               the same reason — see above. */
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, .35));
        }

        /*
            On a phone.

            The panel takes the screen, and the badge moves into the corner.

            The badge anchors everything to its left, so where it sits decides
            how many faces fit along the bottom — every millimetre it gives back
            to the corner is one a face cannot use. The faces still run left
            rather than stacking upward: that was tried and read worse.

            640px is the line SuperBotMan draws, and it is drawn here in CSS
            rather than in script so that turning a phone sideways is the
            browser's problem rather than a resize listener's.
        */
        @media (max-width: 639px) {
            /*
                Full screen, and in front of everything — including the badge
                and the faces, which would otherwise sit on top of the
                conversation they belong to.
            */
            #streetmesh-panel {
                inset: 0;
                width: 100%;
                height: 100%;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                z-index: 2147483003;
            }

            #streetmesh-badge {
                bottom: {{ $tight }}px;
                right: {{ $tight }}px;
            }

            /* Beside it, wherever it has moved to. */
            #streetmesh-stage {
                bottom: {{ $tight }}px;
                right: {{ $tight + $frame }}px;
            }
        }
    </style>

    @persist('streetmesh-comms')
        <iframe
            id="streetmesh-panel"
            src="{{ route('venue.comms.panel') }}"
            title="{{ __('Talking') }}"
            scrolling="no"
            allowtransparency="true"
            {{--
                So the party's code can be copied. A frame is not granted the
                clipboard by being same-origin, and without saying so the write
                is refused in a way that looks exactly like a button that does
                nothing.
            --}}
            allow="clipboard-write"
        ></iframe>

        <iframe
            id="streetmesh-stage"
            src="{{ route('venue.comms.stage') }}"
            title="{{ __('Cameras') }}"
            scrolling="no"
            allowtransparency="true"
            {{--
                Without this a same-origin iframe can still be refused the
                camera by permissions policy, and the refusal arrives as an
                ordinary "denied" — indistinguishable from somebody pressing No.
            --}}
            allow="camera; microphone; autoplay"
        ></iframe>

        <iframe
            id="streetmesh-badge"
            src="{{ route('venue.comms.badge') }}"
            title="{{ __('Talk to people here') }}"
            scrolling="no"
            allowtransparency="true"
        ></iframe>
    @endpersist

    {{--
        Deliberately *not* `data-navigate-once`. This runs again after every
        navigation, which is how the page learns that the visitor's party may
        have changed while `host.js` — a module, and so run once per URL
        however many times the body is swapped — kept hold of the media.
    --}}
    <script>
        window.streetmeshComms = Object.assign(window.streetmeshComms || {}, {
            frame: @json($frame),
            badge: @json($badgeSize),
            lift: @json($shape['lift']),

            {{--
                Handed over rather than read from a meta tag. The requests this
                makes are the page's now, and this application's chrome does not
                publish a token — it had one only inside the frames, which is
                exactly where this stopped happening.
            --}}
            csrf: @json(csrf_token()),

            {{--
                Who this is, and which party they are in.

                Named to the *page* rather than to the frames, because the page
                is what holds the camera now — a frame is reloaded by every
                navigation and cannot keep a stream across one.
            --}}
            ...@json($host, JSON_UNESCAPED_SLASHES),

            {{-- The one icon the faces draw with. --}}
            icons: {
                microphoneSlash: @json($mutedIcon),
                unreachable: @json($unreachableIcon),
            },

            {{--
                Which space this screen is, if it is one.

                An experience marks its own screen with `data-streetmesh-space`;
                this is here for a screen whose space is not known until
                something happens on it.
            --}}
            context: @json($commsContext ?? [], JSON_UNESCAPED_SLASHES),
        })
    </script>

    @vite('packages/laravel-venue/resources/js/comms/host.js')
@endif
