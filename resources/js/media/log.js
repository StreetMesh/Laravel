/**
 * Where the media code says what it is doing.
 *
 * Not the page. A connection state, a close code and a browser exception string
 * are all things an engineer reads while fixing this, and none of them are
 * things the person in the party can act on — putting them on screen turns
 * somebody talking to their friends into an instrument reader for a machine
 * that already knows. The page gets written prose or nothing.
 *
 * On in a dev build, or set `localStorage.smDebug = 1` against a built one.
 */
const PREFIX = '[stage]';

function wanted() {
    try {
        return import.meta.env?.DEV || Boolean(localStorage.getItem('smDebug'));
    } catch {
        /* Safari in private browsing throws rather than answering. */
        return Boolean(import.meta.env?.DEV);
    }
}

const on = wanted();

export function say(...parts) {
    if (on) {
        console.info(PREFIX, ...parts);
    }
}

/**
 * Something went wrong, with the exception itself rather than its message.
 *
 * Always reported, debug channel or not: an error nobody sees is the reason
 * several of these took a week to find.
 */
export function trouble(what, error) {
    console.error(`${PREFIX} ${what}`, error);
}
