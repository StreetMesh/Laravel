<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use StreetMesh\Protocol\BlobScope;
use StreetMesh\Protocol\Glb;
use StreetMesh\Protocol\Scope;
use StreetMesh\Server\Protocol\Identity\Identities;
use StreetMesh\Server\Protocol\Permissions\Permissions;

/**
 * The one screen in this whole exchange that a person actually looks at.
 *
 * Everything else here is two servers talking. This is where somebody is told
 * what a venue is asking for and decides, and it is the only place the answer
 * can come from — a domicile that could grant permission without asking would
 * not be a domicile in the sense this project means.
 *
 * The view is deliberately plain and deliberately overridable. What a resident
 * sees belongs to whatever interface package is installed; what cannot be
 * overridden is that they are asked at all.
 */
final class ConsentController
{
    public function __construct(
        private readonly Permissions $permissions,
        private readonly Identities $identities,
    ) {}

    public function show(Request $request): View|Response
    {
        $permission = $this->permissions->awaiting((string) $request->query('request_uri'));

        if ($permission === null) {
            return $this->gone($request);
        }

        /** @var view-string $consent */
        $consent = 'streetmesh::consent';

        return view($consent, [
            'permission' => $permission,

            /*
             * The client identifier is a URL, and its host is the only part of
             * it a person can reasonably judge. Shown as the venue's name
             * rather than the whole address, which nobody reads.
             */
            'venue' => parse_url($permission->client_id, PHP_URL_HOST),

            'asking' => $this->inWords($permission->scopes()),
        ]);
    }

    public function approve(Request $request): RedirectResponse|Response
    {
        $permission = $this->permissions->awaiting((string) $request->input('request_uri'));

        if ($permission === null) {
            return $this->gone($request);
        }

        $did = $this->didOf($request);

        if ($request->input('answer') !== 'yes') {
            /*
             * A refusal goes back to the venue as a refusal rather than as a
             * silence, because a venue left waiting cannot tell "no" from "the
             * browser closed" and would keep the seat open for either.
             */
            return redirect()->away($this->back($permission->redirect_uri, [
                'error' => 'access_denied',
                'state' => $permission->state,
            ]));
        }

        $code = $this->permissions->approve($permission, $did);

        return redirect()->away($this->back($permission->redirect_uri, [
            'code' => $code,
            'state' => $permission->state,

            /*
             * Naming ourselves in the redirect lets the venue confirm the
             * answer came from the server it asked, rather than from whoever
             * got to the callback first.
             */
            'iss' => rtrim(url('/'), '/'),
        ]));
    }

    /**
     * There is nothing here to answer, said as a page rather than as a crash.
     *
     * The two ways to arrive: a screen left open past the few minutes a request
     * lasts, and a reload after already deciding — because answering empties
     * the request. Both are ordinary things for a person to do and neither is a
     * fault of any kind, which is what made a 500 the wrong response to it.
     *
     * `410` rather than `404`. It was here, it isn't now, and that is exactly
     * what the code is for — it also stops a browser or a proxy holding on to
     * the page and offering it again later.
     *
     * The venue is dug out of `client_id` when there is one, because the
     * permission that knew where to send somebody is precisely the thing that
     * has gone. On the POST there is no `client_id` — the form carries only the
     * handle — so the page falls back to naming nowhere in particular, and the
     * person still has a way out through this server's own front page.
     */
    private function gone(Request $request): Response
    {
        $clientId = (string) $request->input('client_id');
        $venue = $clientId === '' ? null : parse_url($clientId, PHP_URL_HOST);

        /** @var view-string $expired */
        $expired = 'streetmesh::expired';

        return response()->view($expired, [
            'venue' => is_string($venue) ? $venue : null,
        ], 410);
    }

    /**
     * The scopes, as sentences somebody can act on.
     *
     * This carries more weight than it looks. A server no longer has to have
     * been configured for a kind of record before it can hold one — a resident
     * agreeing is what makes it allowed — so this screen is the only place a
     * person sees the name of what is about to be written under. `repo:com.
     * streetmesh.games.chess?action=create` is not something to decide from.
     *
     * The record type is shown as it is, though, rather than prettified away.
     * Whoever is reading has to be able to recognize it later in their own
     * records, and a friendly paraphrase they never see again would not help
     * them do that.
     *
     * Two passes, because a token carries two kinds of permission and each half
     * of the grammar declines to read the other's. A scope that describes
     * neither -- `atproto` -- contributes nothing to either pass, which is
     * correct: it grants no access to anything a person would want warning of.
     *
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    private function inWords(array $scopes): array
    {
        $sentences = [];

        foreach ($scopes as $scope) {
            $repo = Scope::parse($scope);

            if ($repo === null) {
                continue;
            }

            $what = in_array('*', $repo->collections, strict: true)
                ? __('records of every kind')
                : implode(__(' and '), $repo->collections);

            $sentences[] = $repo->actions === [Scope::CREATE]
                ? __('Add :what to your records, and never change or remove them', ['what' => $what])
                : __('Add, change and remove :what in your records', ['what' => $what]);
        }

        foreach ($scopes as $scope) {
            $blob = BlobScope::parse($scope);

            if ($blob === null) {
                continue;
            }

            /*
             * Media types rather than record names, and shown as plainly as the
             * record types above are shown literally -- but the reasoning is
             * the other way round. Nobody is going to recognize
             * `model/gltf-binary` again later in their own records, because a
             * blob is not something they browse. What they need to understand
             * is what sort of thing a venue is about to keep for them, and
             * "pictures and models" is that.
             */
            $sentences[] = __('Keep :what in your files', [
                /*
                 * Unique, because two types can say the same word. A venue
                 * asking for PNGs and JPEGs is asking to keep pictures, and
                 * "pictures and pictures" reads as a bug in the screen rather
                 * than as a permission.
                 */
                'what' => implode(__(' and '), array_unique(array_map($this->plainly(...), $blob->accepts))),
            ]);
        }

        return $sentences === [] ? [__('Confirm who you are, and nothing else')] : $sentences;
    }

    /**
     * A media type, as a person would say it.
     *
     * Falls back to the type itself rather than to a vague word, because a
     * sentence saying a venue may keep "files" is a sentence that has told
     * somebody nothing. An unfamiliar type is better read as itself.
     */
    private function plainly(string $mime): string
    {
        return match (true) {
            $mime === BlobScope::ANY => __('anything at all'),
            str_starts_with($mime, 'image/') => __('pictures'),
            $mime === Glb::MIME => __('models'),
            default => $mime,
        };
    }

    /**
     * Whose answer this is.
     *
     * A permission is granted over a person's records, so it is their identity
     * that matters and not their login. A signed-in account with no identity
     * cannot grant anything, and saying so is better than granting it under the
     * server's own name.
     */
    private function didOf(Request $request): string
    {
        $user = $request->user();

        $identity = $user === null ? null : $this->identities->forUser($user);

        return $identity->did ?? throw new RuntimeException(
            'Whoever is signed in has no identity of their own, so there is nothing to grant permission over.'
        );
    }

    /**
     * @param  array<string, string|null>  $answer
     */
    private function back(string $redirect, array $answer): string
    {
        return $redirect.(str_contains($redirect, '?') ? '&' : '?')
            .http_build_query(array_filter($answer, fn (?string $value): bool => $value !== null));
    }
}
