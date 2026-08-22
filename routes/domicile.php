<?php

use Illuminate\Support\Facades\Route;

/*
 * Two screens, at names nothing else wants, drawn by Livewire components this
 * package ships itself. They render into the host's layout — the package
 * decides what the screens are, the application decides what frames them.
 */
Route::livewire('directory', 'domicile::directory')->name('domicile.directory');

/*
 * A resident, at an address anybody can link to.
 *
 * The whole handle rather than the label in front of it, because the handle is
 * the thing people copy, quote and are known by — `stme.sh/profile/alice` would
 * be this server naming somebody by a part of their name that only means
 * anything while you are already here.
 */
Route::livewire('profile/{handle}', 'domicile::profile')->name('domicile.profile');

/*
 * Choosing what you look like.
 *
 * Behind a login, because this is somebody deciding about their own records —
 * and at this server's own name rather than at theirs, because their hostname
 * is where the answer is published and not where it is decided.
 *
 * Under `settings/` because that is where a person looks for something about
 * themselves, and the capability says so in `DomicileCapability::settings` so
 * that the application can put it in the same list as its own.
 *
 * Not at `avatar`, which is taken: that is the published path on a resident's
 * hostname, registered in `published.php`, which is loaded after this file.
 * Laravel replaces a route sharing a path rather than complaining about it, so
 * the two named the same thing left this screen answering 404 and said nothing
 * anywhere about why.
 */
Route::middleware('auth')->group(function (): void {
    Route::livewire('settings/avatar', 'domicile::avatar')->name('domicile.avatar');
});
