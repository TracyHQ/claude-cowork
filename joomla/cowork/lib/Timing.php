<?php
/**
 * Where one door call's time went, phase by phase — measured only when the caller asks for it.
 *
 * Issue #316: a content-contract call took 35–108 s and nothing in the receiver could say where.
 * Joomla's own profiler cannot help, because the door ends the response with `close()` before
 * anything could print it. A caller that sends `timing: true` in `params` now gets a `timing`
 * block beside the answer, `{phase: {ms, n}}`: the wall time summed over every run of that phase
 * in this call, and how many runs there were. Phases nest — an apply's `plan` and `verify` each
 * hold a whole inspect, whose own phases are listed too — so they do not add up to a total.
 *
 * The block is attached by the door, around the ENCODED answer, never inside anything the engine
 * returns. That is what keeps it out of a stored receipt: a replayed apply answers with this
 * call's timings, never the ones it was recorded with.
 *
 * Off — every call that does not ask — each marker is one static boolean read, and the body is
 * byte for byte what it was before this class existed. `CLAUDECOWORK_TIMING=1` in PHP's
 * environment forces it on for every call; the param is the switch that always arrives, because
 * PHP-FPM clears the environment by default.
 */
final class Timing
{
    private static bool $on = false;
    /** @var array<string,array{0:int,1:int}> phase => [nanoseconds, runs] */
    private static array $phases = [];

    /** Whether this request asked to be timed. */
    public static function wanted(array $request): bool
    {
        return (($request['params']['timing'] ?? null) === true) || getenv('CLAUDECOWORK_TIMING') === '1';
    }

    /** A start mark for {@see end()}; 0, and no clock read, when this call is not timed. */
    public static function begin(): int
    {
        return self::$on ? hrtime(true) : 0;
    }

    /** Add the time since `$start` to `$phase`. */
    public static function end(string $phase, int $start): void
    {
        if (!self::$on) return;
        $spent = hrtime(true) - $start;
        self::$phases[$phase] = [(self::$phases[$phase][0] ?? 0) + $spent, (self::$phases[$phase][1] ?? 0) + 1];
    }

    /**
     * The JSON body of one door call: `$answer()` encoded exactly as the door always has, plus a
     * `timing` block when the request asked for one.
     */
    public static function body(array $request, callable $answer): string
    {
        if (!self::wanted($request)) return (string) json_encode($answer());
        self::$on = true;
        self::$phases = [];
        try {
            $result = $answer();
            $start = hrtime(true);
            $json = json_encode($result);
            self::end('encode', $start);
            $report = [];
            foreach (self::$phases as $phase => [$ns, $n]) $report[$phase] = ['ms' => round($ns / 1e6, 1), 'n' => $n];
        } finally {
            self::$on = false;
            self::$phases = [];
        }
        // Spliced into the encoded text, not added and encoded again: a second encode of a 1 MB
        // inspect would be time the block itself put there. Only a JSON object can take a key.
        if (!is_string($json) || !is_array($result) || $result === [] || array_is_list($result)) return (string) $json;
        return substr($json, 0, -1) . ',"timing":' . json_encode((object) $report) . '}';
    }
}
