<?php

/**
 * FilesRestorer — the write that reverses {@see FileWalker}/files.pack.
 *
 * files.pack streams a site's files out as a tar, in parts, so a large webroot finishes on a
 * host that stops PHP after thirty seconds. This is the other half: it fetches that tar back and
 * lays it over the web root. Together they are the snapshot and restore of the file half of a
 * site, and they are what makes a failed core upgrade recoverable in the common cases.
 *
 * It matters that this is the SAFE half of restore. A core upgrade only changes the database in
 * its finalise step, so a failure before or during extraction leaves the schema untouched: laying
 * the old files back returns the site to a working state without touching the database at all. The
 * database restore is the residual, for a finalise that stopped half way, and is a larger and more
 * dangerous surface kept separate on purpose.
 *
 * Behind an interface for the same reason as the others: the engine routes and guards it with a
 * fake, and the real one that writes files is small enough to read.
 */
interface FilesRestorer
{
    /**
     * Fetch the tar snapshot at $getUrl (a files.pack of this same site) and extract it over the
     * web root. The snapshot is the site's own; the caller holds the URL between pack and restore.
     *
     * @return array{ok:bool, files?:int, error?:string}
     */
    public function restore(string $getUrl): array;
}
