<?php

use Illuminate\Support\Facades\Route;
use StreetMesh\Server\Domicile\Http\AvatarController;

/*
 * What a resident's own hostname answers, besides who they are.
 *
 * `collegeman.stme.sh` exists so that a machine resolving a handle can find
 * `/.well-known/atproto-did`, and until now that was the only thing served
 * there — a person typing the name into a browser is asking about a person, and
 * gets redirected to their profile on this server's own name.
 *
 * These two are the exception, and the argument for them is the same as the
 * argument for the well-known paths: a face is something a stranger's software
 * needs, that only this host can answer for. A picture served from anywhere
 * else would be a copy, and a copy is a claim rather than evidence.
 *
 * Outside the `web` group for two independent reasons, either of which alone
 * would be enough. The caller is another server, so there is no session to
 * start and no CSRF token to check. And `SendResidentsHome` lives in `web` and
 * permanently redirects everything on a resident hostname — a route registered
 * there would never run, and browsers would cache the redirect.
 */
Route::middleware('streetmesh')->group(function (): void {
    Route::get('avatar/icon', [AvatarController::class, 'icon'])
        ->name('domicile.published.icon');

    /*
     * Reserved. The model is unbuilt, and this answers 404 rather than 405 or
     * a redirect, so that the day it is built is the day the path changes
     * meaning — rather than the day somebody discovers it had been quietly
     * serving the icon all along.
     */
    Route::get('avatar', [AvatarController::class, 'model'])
        ->name('domicile.published.avatar');
});
