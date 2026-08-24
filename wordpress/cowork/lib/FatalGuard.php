<?php

declare(strict_types=1);

/**
 * Turning a PHP death into an answer, because a caller across the network cannot read a stack
 * trace.
 *
 * The engine catches `Throwable`, which covers everything PHP is willing to hand back. It cannot
 * catch the failures that stop the process where it stands: memory exhausted, `max_execution_time`
 * reached, a stack overflow. PHP prints its own notice and exits, so what leaves the site is not
 * JSON — the relay answers `COMPONENT_BAD_RESPONSE`, and the site's own account of what went wrong
 * never leaves the building.
 *
 * 🔒 24/08/2026, tracy.ai. Two writes died this way ninety minutes apart and both arrived at the
 * desk as the same bare 502. The first cost three wrong diagnoses and a hand-run request against
 * the customer's live site; the second was only understood because the first had been. The fatal
 * named its file and its line the entire time.
 *
 * The decision is kept apart from the printing on purpose. `payloadFor()` is the whole judgement
 * and touches nothing — which is what makes it testable without a web server, a shutdown, or a
 * real fatal. `arm()` is the side effects, and holds no judgement of its own.
 */
final class FatalGuard
{
    /**
     * The error types that end a request. These are exactly the ones `try`/`catch` cannot reach;
     * a warning or a notice leaves the request alive and its real answer on its way.
     *
     * @var int[]
     */
    private const DEADLY = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * The answer to send in place of a death, or null when nothing died.
     *
     * Null is the ordinary case and means "leave the response alone": the request either finished
     * normally or tripped over something it survived.
     *
     * @param array<string,mixed>|null $fatal What `error_get_last()` returned.
     * @return array<string,mixed>|null
     */
    public static function payloadFor(?array $fatal): ?array
    {
        if ($fatal === null || !in_array($fatal['type'] ?? 0, self::DEADLY, true)) {
            return null;
        }

        return [
            'ok'    => false,
            // Its own error code rather than `write_failed`: a site that refused a write and a
            // site that fell over are different conversations with the customer, and a caller
            // that cannot tell them apart will retry the second one forever.
            'error' => 'fatal',
            // File and line are the whole value of this line to whoever reads it next.
            'message' => sprintf(
                '%s in %s:%d',
                (string) ($fatal['message'] ?? 'PHP stopped without saying why'),
                (string) ($fatal['file'] ?? '?'),
                (int) ($fatal['line'] ?? 0)
            ),
        ];
    }

    /**
     * Put the guard in place for this request.
     *
     * `ob_start` matters as much as the shutdown handler: PHP has usually printed its notice by
     * the time the handler runs, and a JSON body with an HTML fatal glued to its front is still
     * not JSON. The buffer is discarded rather than repaired — half a notice in front of a valid
     * body is worse than either alone.
     */
    public static function arm(): void
    {
        ob_start();

        register_shutdown_function(static function (): void {
            $answer = self::payloadFor(error_get_last());

            if ($answer === null) {
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                return;
            }

            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                // 200 deliberately: the refusal travels in the body like every other one this
                // endpoint gives. A 500 reads as the relay failing rather than the site
                // refusing, and leaves the caller two places to look for one reason.
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($answer);
        });
    }
}
