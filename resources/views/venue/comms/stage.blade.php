<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Cameras') }}</title>

    {{--
        A shell, and nothing else.

        There is no script here. The camera, the microphone and the peer
        connections live in the page that holds this frame, because a
        navigation reloads every iframe on it and a stream cannot outlive the
        document that acquired it. What is left here is the stylesheet that
        keeps those faces out of the venue's CSS, and somewhere to put them.
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
            so a container at `height: 100%` with padding below it is taller
            than the frame holding it and hangs its contents out of the bottom.
        */
        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            margin: 0;
            height: 100%;
            background: transparent;
            overflow: hidden;
        }

        #stage {
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            height: 100%;

            /*
                Lifted onto the same line as the badge.

                The badge's circle is centred in a frame slightly larger than
                itself, so it floats half that padding above the bottom of its
                own iframe. These faces sit flush in a frame of their own, and
                without this they line up with nothing.
            */
            padding-bottom: {{ $lift }}px;
        }

        /*
            How big a face is, set by the page that draws them.

            Fixed until a party outgrows the screen it is on, and then smaller —
            four circles and a badge need more width than the narrowest phone
            has. Every measurement below is expressed against it so the whole
            circle scales together rather than a 52px face wearing a 25px band.
        */
        :root { --face: {{ $badge }}px; }

        .face {
            position: relative;
            width: var(--face);
            height: var(--face);
            flex: 0 0 var(--face);
            border-radius: 9999px;
            overflow: hidden;
            background: #27272a;

            /*
                The same gap the badge keeps from the face beside it.

                The badge's circle floats half its frame's padding in from that
                frame's edge, and this row sits flush against it — so half the
                padding is what separates the last face from the badge, and it
                has to be what separates the faces from each other too. A full
                `pad` here made every gap in the row twice the one at the end.
            */
            margin-left: {{ $lift }}px;
        }

        .face video,
        .face .portrait {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /*
            Your own camera is mirrored; everybody else's is not.

            A camera shows what it sees, which is you as other people see you —
            and that reads as backwards, because the only place anybody watches
            themselves move is a mirror. Raising a hand and watching the wrong
            one go up is the tell.

            Never applied to the others: what arrives from them is already the
            right way round, and flipping it would put their writing backwards.
        */
        .face.self video { transform: scaleX(-1); }

        /*
            Every one of these needs saying out loud. A `display` set anywhere
            else beats the `hidden` attribute, and the symptom is two marks that
            contradict each other sitting on one circle at the same time.
        */
        .face video[hidden],
        .face .portrait[hidden],
        .face .lost[hidden],
        .face .quiet[hidden] { display: none; }

        /*
            Somebody's own picture, fetched from their own server.

            The only thing drawn for a face with no video on it. There was a
            letter under this until a domicile started answering for every one
            of its residents — with a picture if they have chosen one and their
            initial if they have not — at which point the letter here was a
            second answer to a question that already had one, in a different
            style, from the party with less to go on.

            The ground stays because a picture with transparency in it is drawn
            over whatever is behind, and behind is now nothing.

            Called `portrait` and not `icon`, which it was for an afternoon. The
            glyphs this widget draws with are rendered as `<svg class="icon">`,
            so `.face .icon` matched the muted microphone sitting inside the
            band as well — absolutely positioned it, pinned it to that band's
            top-left corner, and painted a small gradient square behind it.
            Nothing about the symptom pointed at a name collision.
        */
        .face .portrait {
            position: absolute;
            inset: 0;
            background: linear-gradient(160deg, #3f3f46, {{ $palette['ink'] }});
        }

        /*
            Muted is drawn over the face rather than instead of it. Somebody who
            has their camera on and their microphone off is still there to look
            at, and replacing them with an icon would say they had gone.
        */
        .face .quiet {
            position: absolute;
            inset: auto 0 0 0;
            height: calc(var(--face) * 0.42);
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, .6);
            color: #fca5a5;
        }

        .face .quiet svg { width: calc(var(--face) * 0.22); height: calc(var(--face) * 0.22); }

        /*
            Somebody who cannot be reached at all.

            The same slot the muted mark uses, and never both at once — see the
            host, which decides between them. What separates them is colour and
            what is behind them: muted is a fact about somebody who is here,
            this is the absence of anybody to have facts about.

            Deliberately not red. Nothing has gone wrong that anybody did, and
            two browsers on networks that cannot see each other is a
            circumstance rather than an error — amber says "this is not working"
            without saying "this is broken".
        */
        .face .lost {
            position: absolute;
            inset: auto 0 0 0;
            height: calc(var(--face) * 0.42);
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, .72);
            color: #fbbf24;
        }

        .face .lost svg { width: calc(var(--face) * 0.22); height: calc(var(--face) * 0.22); }

        /*
            And the face behind it goes quiet too. The mark alone reads as
            something laid on top of a person who is otherwise present; dimming
            what is underneath is what makes the whole circle say "not here",
            which is the thing being reported.
        */
        .face.lost .portrait { opacity: .4; }

    </style>
</head>
<body>
    <div id="stage"></div>

</body>
</html>
