<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

/**
 * Is this request knocking on the API door?
 *
 * The door is `index.php?option=com_claudecowork&task=api.exec`, and it is answered twice: by the
 * component's own controller once Joomla has routed the request, and — earlier — by the system
 * plugin at `onAfterInitialise`, before any router or any other plugin has seen it. Both need the
 * same answer to the same question, so the question lives here, in plain PHP, where the test
 * runner can ask it without Joomla.
 *
 * `format` is deliberately not part of the question. The door always answers JSON; a caller that
 * forgot `format=json` gets JSON anyway rather than an HTML error page dressed as a reply.
 */
final class Door
{
    public const OPTION = 'com_claudecowork';
    public const TASK = 'api.exec';

    /**
     * @param array<string,mixed> $query The request's query parameters, as strings.
     */
    public static function wants(array $query): bool
    {
        $option = $query['option'] ?? null;
        $task = $query['task'] ?? null;
        if (!is_string($option) || !is_string($task)) {
            return false;
        }
        return $option === self::OPTION && strtolower($task) === self::TASK;
    }
}
