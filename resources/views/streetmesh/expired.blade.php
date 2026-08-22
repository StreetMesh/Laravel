{{--
    Nothing here to answer, said plainly.

    Plain and meant to be replaced, for the same reason the consent screen is:
    this package has no business deciding what a domicile looks like. What must
    survive any replacement is that somebody is told nothing was granted, and
    given somewhere to go.

    The two ways to arrive are a screen left open too long and a reload after
    already deciding. Neither is a mistake, and the wording deliberately blames
    nobody — a person who walked away from their desk has done nothing wrong.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Nothing to answer') }}</title>
        <style>
            :root {
                color-scheme: light dark;
                --paper: #ffffff;
                --ink: #18181b;
                --quiet: #52525b;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --paper: #18181b;
                    --ink: #fafafa;
                    --quiet: #a1a1aa;
                }
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 2rem;
                background: var(--paper);
                color: var(--ink);
                font: 16px/1.5 system-ui, sans-serif;
            }

            main { max-width: 26rem; }
            h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
            p { color: var(--quiet); margin: 0 0 1rem; }
        </style>
    </head>
    <body>
        <main>
            <h1>{{ __('That request has expired') }}</h1>

            <p>
                {{ __('Nothing was shared and nothing was granted. Requests last a few minutes, and this one was left too long or has already been answered.') }}
            </p>

            @if ($venue !== null)
                <p>{{ __('You can start again from :venue.', ['venue' => $venue]) }}</p>

                <p><a href="{{ 'https://'.$venue }}">{{ $venue }}</a></p>
            @else
                <p><a href="{{ url('/') }}">{{ __('Go to the front page') }}</a></p>
            @endif
        </main>
    </body>
</html>
