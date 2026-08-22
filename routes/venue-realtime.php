<?php

use Illuminate\Support\Facades\Route;
use StreetMesh\Server\Venue\Http\RealtimeController;

/*
 * What the hub tells this venue.
 *
 * Outside the `web` group entirely, and that is the point rather than an
 * economy. Everything in that group is for a person with a browser — a session,
 * a CSRF token, errors shared out of the session — and a hub has none of them
 * and never will. It was in there briefly and failed on the first request,
 * because sharing errors out of a session needs a session.
 *
 * What stands in front of this instead is a shared secret, checked in the
 * controller. A hub holds no key of its own, so there is nothing else it could
 * be recognised by.
 */
Route::post('realtime', RealtimeController::class)->name('venue.realtime');
