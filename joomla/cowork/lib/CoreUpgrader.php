<?php

/**
 * CoreUpgrader — the write that moves a site one Joomla version.
 *
 * The engine holds no Joomla; a caller wires the real one in, exactly as with {@see
 * ExtensionManager}. That keeps the routing and the guard testable with a fake, and keeps the
 * one genuinely dangerous operation this component can perform behind an interface small enough
 * to read in a sentence: take a site one hop toward `$to`, report where it landed.
 *
 * TWO steps, not one, and the caller makes them as two separate calls. A core upgrade replaces
 * the code on disk mid-flight, so the process that copied the new files is still running the old
 * ones: it cannot finalise against class signatures it has not loaded. The web updater solves
 * this by finalising in a fresh request, and so does this. `prepare` downloads and extracts;
 * `finalise` runs on the new code the next call loads. The caller (the conductor) calls prepare,
 * then finalise, then verifies the reported version.
 */
interface CoreUpgrader
{
    /**
     * One step of one hop. $step is "prepare" (download + extract the package) or "finalise"
     * (run the finalise on the freshly extracted code). $to is the launch point ("4.4"|"5.4"|"6.1").
     *
     * @return array{ok:bool, step?:string, version?:string, error?:string}
     */
    public function upgrade(string $to, string $step): array;
}
