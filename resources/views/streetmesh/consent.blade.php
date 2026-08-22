{{--
    Plain on purpose, and meant to be replaced.

    This package has no business deciding what a domicile looks like, so this is
    the least that can honestly be shown: who is asking, what for, and two
    answers of equal weight. An interface package overrides it by registering a
    `streetmesh` view namespace of its own.

    What must survive any replacement: the venue is named, the request is
    described in words rather than in scope strings, and refusing is exactly as
    easy as agreeing.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Permission') }}</title>
        <style>
            /*
             * Follows the reader rather than announcing itself. A page that is
             * dark whatever the rest of the machine is doing looks like it came
             * from somewhere else — which is the one impression a screen asking
             * for permission cannot afford to give.
             */
            :root {
                color-scheme: light dark;
                --paper: #ffffff;
                --ink: #18181b;
                --quiet: #52525b;
                --edge: #d4d4d8;
                --mark: #047857;
                --mark-ink: #ffffff;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --paper: #18181b;
                    --ink: #fafafa;
                    --quiet: #a1a1aa;
                    --edge: #3f3f46;
                    --mark: #00ff99;
                    --mark-ink: #18181b;
                }
            }

            body {
                font: 16px/1.6 system-ui, -apple-system, sans-serif;
                margin: 0;
                display: grid;
                place-items: center;
                min-height: 100vh;
                background: var(--paper);
                color: var(--ink);
            }

            /* The width of a sign-in form, because it is that kind of screen. */
            main { width: 100%; max-width: 24rem; padding: 2rem; box-sizing: border-box; }

            h1 { font-size: 1.125rem; font-weight: 600; margin: 0 0 .5rem; }
            p { margin: .5rem 0; }
            .quiet { color: var(--quiet); font-size: .875rem; }
            .venue { color: var(--mark); }
            ul { padding-left: 1.2rem; margin: .75rem 0; }

            form { display: flex; gap: .5rem; margin-top: 1.5rem; }

            button {
                font: inherit;
                font-size: .875rem;
                flex: 1;
                padding: .5rem .75rem;
                border-radius: 8px;
                border: 1px solid var(--edge);
                background: transparent;
                color: inherit;
                cursor: pointer;
            }

            button.yes {
                background: var(--mark);
                border-color: var(--mark);
                color: var(--mark-ink);
                font-weight: 500;
            }

            button:disabled { opacity: .5; cursor: default; }
        </style>
    </head>
    <body>
        <main>
            <h1><span class="venue">{{ $venue }}</span> {{ __('wants to connect') }}</h1>

            <p>{{ __("Permissions you're granting:") }}</p>

            <ul>
                @foreach ($asking as $sentence)
                    <li>{{ $sentence }}</li>
                @endforeach
            </ul>

            <p class="quiet">{{ __('You can revoke this at any time.') }}</p>

            {{--
                Answering takes a round trip, which is long enough to press
                again. Both go dead on the first press.

                Deferred by a tick rather than disabled as the form is
                submitted: the answer travels as the pressed button's own name
                and value, and a button disabled while the form is still being
                gathered contributes neither — the request would arrive with no
                answer in it. No framework here, so this is the whole of it.
            --}}
            <form method="POST" action="{{ route('streetmesh.oauth.approve') }}"
                  onsubmit="setTimeout(() => this.querySelectorAll('button').forEach(b => b.disabled = true), 0)">
                @csrf
                <input type="hidden" name="request_uri" value="{{ $permission->request_uri }}">

                <button type="submit" name="answer" value="yes" class="yes">{{ __('Allow') }}</button>
                <button type="submit" name="answer" value="no">{{ __('Cancel') }}</button>
            </form>
        </main>
    </body>
</html>
