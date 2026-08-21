<?php

/**
 * CoreUpgrader — the write that moves a site one Joomla version.
 *
 * The engine holds no Joomla; a caller wires the real one in, exactly as with {@see
 * ExtensionManager}. That keeps the routing and the guard testable with a fake, and keeps the
 * one genuinely dangerous operation this component can perform behind an interface small enough
 * to read in a sentence: take a site to `$to`, report the version it actually reached.
 *
 * One hop, not a chain. The caller (the conductor in JoomlArt-26/joomlart-joomla-ops) decides
 * the order, gates PHP, and takes the snapshot before calling this. This runs the single hop and
 * says where the site landed, so the caller can verify against `$to` and stop or continue.
 */
interface CoreUpgrader
{
    /**
     * Move the site one hop, to the launch point named by $to ("4.4", "5.4" or "6.1").
     *
     * @return array{ok:bool, version?:string, landed?:bool, steps?:array, error?:string}
     *   `version` is read from the site after the run, not assumed; `landed` is whether it
     *   starts with $to. A caller trusts the reading, never the request's own success.
     */
    public function upgrade(string $to): array;
}
