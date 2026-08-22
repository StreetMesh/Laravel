<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Talk to people here') }}</title>

    {{--
        Self-contained on purpose. This document is one circle and one icon, and
        pulling the application's whole stylesheet in for it would make the
        first thing anybody sees the slowest — the panel behind it can afford
        that, and this cannot.
    --}}
    <style>
        /*
            No `color-scheme` here, deliberately.

            Declaring one makes the browser paint its own canvas behind the
            document — opaque, and in the theme's colour. These frames are
            transparent so that a circle reads as a circle, and setting
            `color-scheme: dark` put a dark rectangle behind the badge and every
            face. The colours below follow the class the page sets instead.
        */

        /*
            These documents load no stylesheet of their own, which is what keeps
            them fast — and means they get none of the reset the rest of the
            application has. Without this the browser default of `content-box`
            applies: padding is added to a width rather than counted inside it,
            so a 60px circle with 18px of padding is a 96px circle, and a
            container at `height: 100%` with padding below it is taller than the
            frame holding it and hangs its contents out of the bottom.

            Both of those were live at once, which is why the badge looked a
            size it was not and the faces beside it sat low.
        */
        *, *::before, *::after { box-sizing: border-box; }


        html, body {
            margin: 0;
            height: 100%;
            background: transparent;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }

        button {
            width: {{ $badge }}px;
            height: {{ $badge }}px;
            padding: {{ round($badge * 0.3) }}px;
            border: 0;
            border-radius: 9999px;
            background: {{ $palette['ink'] }};
            color: {{ $palette['paper'] }};
            cursor: pointer;
            display: block;
            transition: background .15s ease, transform .1s ease;

            /*
                No shadow here. An iframe clips its own content, and a shadow
                wide enough to look like one reaches past the frame's edge — so
                a round button cast a soft *square*. The host draws it instead,
                with a filter that follows the circle.
            */
        }

        /* The venue's accent, which is the mark's own green by default. Dark
           ink on it, because a colour that bright is unreadable under white —
           see the palette in the venue's config. */
        button:hover,
        button[data-open] { background: {{ $palette['accent'] }}; color: {{ $palette['ink'] }}; }

        button:active { transform: scale(.95); }

        button:focus-visible {
            outline: 3px solid {{ $palette['accent'] }};
            outline-offset: 3px;
        }

        /*
            Following this application's theme rather than the operating
            system's. Dark is a class the page sets on each frame's document —
            a venue with a light/dark setting of its own would otherwise have a
            badge that ignored it.
        */
        :root.dark button { background: {{ $palette['paper'] }}; color: {{ $palette['ink'] }}; }
        :root.dark button:hover,
        :root.dark button[data-open] { background: {{ $palette['accent'] }}; color: {{ $palette['ink'] }}; }

        /*
            Both icons fill the button and centre themselves in it.

            Without this they render at their own intrinsic size: the two glyphs
            have different viewBoxes — a speech bubble is wider than a cross —
            so one of them sat off-centre and the other looked a different size.
            Filling the box and letting preserveAspectRatio centre the artwork
            makes them agree.
        */
        button > span:not(.sr-only),
        button svg {
            display: block;
            width: 100%;
            height: 100%;
        }

        /*
            And `hidden` still means hidden.

            The rule above is more specific than the browser's own
            `[hidden] { display: none }`, so without this the closed icon and the
            open one were both drawn — a speech bubble with a cross through it,
            which reads as a bug and was one.
        */
        button > span[hidden] { display: none; }

        /*
            Something is happening in a conversation nobody is looking at.

            Offset from the frame's edge by the padding as well, because the
            circle is centred in a frame larger than itself — measured from the
            frame alone the dot floats off the badge entirely.
        */
        .waiting {
            position: absolute;
            top: {{ $lift + round($badge * 0.08) }}px;
            right: {{ $lift + round($badge * 0.08) }}px;
            width: 12px;
            height: 12px;
            border-radius: 9999px;
            background: #ef4444;
            border: 2px solid {{ $palette['ink'] }};
            display: none;
        }

        .waiting[data-on] { display: block; }

        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            padding: 0; margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body>
    <button type="button" id="badge">
        <span id="closed">@include('venue::comms.icon', ['icon' => 'comment', 'class' => 'w-full h-full'])</span>
        <span id="opened" hidden>@include('venue::comms.icon', ['icon' => 'xmark', 'class' => 'w-full h-full'])</span>
        <span class="sr-only">{{ __('Talk to people here') }}</span>
    </button>

    <span class="waiting" id="waiting"></span>

    <script>
        (function () {
            const button = document.getElementById('badge')
            const closed = document.getElementById('closed')
            const opened = document.getElementById('opened')
            const waiting = document.getElementById('waiting')

            const tell = (method, params = {}) =>
                window.parent.postMessage({ method, params }, window.location.origin)

            button.addEventListener('click', () => tell('streetmesh.badge.click'))

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    tell('streetmesh.badge.esc')
                }
            })

            window.addEventListener('message', (event) => {
                if (event.origin !== window.location.origin) {
                    return
                }

                const { method, params } = event.data || {}

                if (method === 'streetmesh.widget.toggle') {
                    closed.hidden = params.open
                    opened.hidden = !params.open
                    button.toggleAttribute('data-open', Boolean(params.open))

                    /* Opening it is reading it. */
                    if (params.open) {
                        waiting.removeAttribute('data-on')
                    }
                }

                /*
                 * Somebody said something while the panel was shut. The dot is
                 * the whole of the notification: a count would be a number
                 * nobody can act on, and a sound in a venue somebody is playing
                 * chess in is an intrusion.
                 */
                if (method === 'streetmesh.panel.waiting') {
                    waiting.toggleAttribute('data-on', Boolean(params.waiting))
                }
            })

            tell('streetmesh.surface.ready')
        })()
    </script>
</body>
</html>
