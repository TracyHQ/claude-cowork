<?php
/**
 * The address of a page that is not published answers 404 (TracyHQ/tch#1013, measured 08/10/2026 on dev, Tracy Business
 * WP, plugin 0.18.3): the customer unticked News, Tracy drafted it, and an anonymous /news/ answered 301 to /newsletter/,
 * because WordPress core guesses a published post whose slug starts with the one asked for
 * (`redirect_guess_404_permalink`). The guess is skipped while the address belongs to a page that is not published.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/UnpublishedAddressHooks.php';

echo "\nUnpublished address\n";

check('a drafted page holds its address: no guess', UnpublishedAddressHooks::shouldGuess('news', ['draft']), false);
check('a private, pending or scheduled page holds it too',
    [UnpublishedAddressHooks::shouldGuess('news', ['private']), UnpublishedAddressHooks::shouldGuess('news', ['pending']), UnpublishedAddressHooks::shouldGuess('news', ['future'])],
    [false, false, false]);
check('a name no row holds: WordPress guesses as before', UnpublishedAddressHooks::shouldGuess('nwes', []), true);
check('a published row with that exact name: WordPress guesses (and finds it) as before',
    UnpublishedAddressHooks::shouldGuess('news', ['draft', 'publish']), true);
check('no name asked for: untouched', UnpublishedAddressHooks::shouldGuess('', ['draft']), true);
check('a trashed or auto-draft row does not hold an address',
    UnpublishedAddressHooks::shouldGuess('news', ['trash', 'auto-draft', 'inherit']), true);
check('the filter keeps a guess already switched off', UnpublishedAddressHooks::filter(false), false);
