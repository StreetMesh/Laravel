{{--
    What a page says when it cannot reach the server it came from.

    Livewire's own answer is `showHtmlModal`: a dialog inset fifty pixels from
    every edge holding an iframe painted `#17161A`, into which it writes the
    response body. That is a reasonable thing to do with a stack trace and the
    wrong thing to do with nothing at all — and nothing at all is what a request
    that never completed returns. A laptop closed overnight wakes to a poll
    against a dead connection and the reader finds a black rectangle over their
    work containing no words, dismissable only by a click that nothing suggests.

    So the empty case is answered here, and a response that actually says
    something is left alone: a trace in an ugly modal is still information, and
    taking it away would cost more in a broken afternoon than it saves.

    It lives in this package because every StreetMesh server has it, and because
    what it is about — a browser and a server that have lost each other — is not
    the business of any one capability. Include it after `@@livewireScripts`.
--}}

<div id="streetmesh-trouble" hidden>
    <span data-say></span>
    <button type="button" data-again>{{ __('Reload') }}</button>
</div>

<style>
    /*
        A strip along the top, and nothing modal.

        Whatever has gone wrong, what is underneath is still readable and still
        worth looking at. Covering it up buys nothing and costs the reader the
        thing they were in the middle of.
    */
    #streetmesh-trouble {
        position: fixed;
        inset: 0 0 auto 0;
        z-index: 2147483000;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #17161A;
        color: #fff;
        font: 500 12px/1.4 system-ui, -apple-system, sans-serif;
    }

    #streetmesh-trouble[hidden] { display: none; }

    #streetmesh-trouble button {
        margin-left: auto;
        border: 0;
        border-radius: 999px;
        padding: 4px 10px;

        /* The venue publishes its palette as custom properties and other
           surfaces do not, so this is the venue's green where there is one and
           StreetMesh's own where there is not. */
        background: var(--sm-accent, #00FF99);
        color: var(--sm-ink, #14181A);
        font: inherit;
        cursor: pointer;
    }

    #streetmesh-trouble button[hidden] { display: none; }
</style>

<script>
    (function () {
        const trouble = document.getElementById('streetmesh-trouble')
        const say = trouble.querySelector('[data-say]')
        const again = trouble.querySelector('[data-again]')

        /*
         * How many requests in a row have come back with nothing.
         *
         * One is a blip and worth no more than a word — a page that polls will
         * usually clear it by itself moments later. Several in a row is
         * something that will not fix itself, and that is the only point at
         * which there is any use in offering a button.
         */
        let missed = 0
        let reloading = false

        const PATIENCE = 3

        const complain = (words, offerReload) => {
            say.textContent = words
            again.hidden = ! offerReload
            trouble.hidden = false
        }

        again.addEventListener('click', () => window.location.reload())

        const watch = () => {
            window.Livewire.hook('request', ({ succeed, fail }) => {
                succeed(() => {
                    missed = 0
                    trouble.hidden = true
                })

                fail(({ status, content, preventDefault }) => {
                    /*
                     * An expired session. Livewire asks, with `confirm()`,
                     * whether to refresh — a browser dialog out of nowhere, and
                     * from inside a frame, out of nowhere the reader cannot even
                     * see. Reloading is the same repair without the question:
                     * the page comes back as whoever the visitor now is, and if
                     * that is somebody who has to arrive again then saying so is
                     * the honest outcome.
                     */
                    if (status === 419) {
                        preventDefault()

                        if (! reloading) {
                            reloading = true
                            window.location.reload()
                        }

                        return
                    }

                    /*
                     * Nothing came back. Reported as 503 when the request never
                     * completed at all, where `preventDefault` happens to do
                     * nothing — but no modal is raised for that case either, so
                     * saying so is the whole of the job.
                     */
                    if (status === 503 || ! (content ?? '').trim()) {
                        preventDefault()

                        missed += 1

                        complain(
                            missed < PATIENCE
                                ? @js(__('Reconnecting…'))
                                : @js(__('Cannot reach the server.')),
                            missed >= PATIENCE,
                        )
                    }
                })
            })
        }

        /*
         * Whichever comes first. This is included after `@@livewireScripts`, so
         * on a page where Livewire has already started there is no event left
         * to wait for.
         */
        if (window.Livewire) {
            watch()
        } else {
            document.addEventListener('livewire:init', watch)
        }
    })()
</script>
