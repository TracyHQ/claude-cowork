<?php
/**
 * ContractProblem — one reason `content.contract` refuses, with the fields an agent acts on.
 *
 * The prose `message` stays exactly what the relay has always shown; the code, the field it is
 * about and whether a changed request can succeed are what an agent branches on, so they are
 * properties set where the refusal is decided, never parsed back out of the message.
 *
 * Severity is a property of the CODE, fixed in one table: whether a retry with other words can
 * pass is not something each throw site should get to decide on its own.
 */

require_once __DIR__ . '/EditLock.php';

class ContractProblem extends RuntimeException
{
    public const SLOT_UNKNOWN = 'SLOT_UNKNOWN';
    public const SLOT_TOO_LONG = 'SLOT_TOO_LONG';
    public const SLOT_EVIDENCE_REQUIRED = 'SLOT_EVIDENCE_REQUIRED';
    public const SLOT_NOT_CONTENT = 'SLOT_NOT_CONTENT';
    public const SLOT_LINK_UNSUPPORTED = 'SLOT_LINK_UNSUPPORTED';
    /** A picture for an image slot that is not in the media library, or not in the slot's shape. */
    public const SLOT_IMAGE_INVALID = 'SLOT_IMAGE_INVALID';
    public const SLOT_EDITION_MISSING = 'SLOT_EDITION_MISSING';
    /**
     * The row a slot lives on is open in the WordPress editor (a live `_edit_lock`, EditLock).
     * Recoverable: the same request passes once the person saves and closes it. There is no flag
     * to write anyway — the editor's next save would silently undo it.
     */
    public const SLOT_LOCKED_BY_USER = EditLock::CODE;
    public const REVISION_STALE = 'REVISION_STALE';
    public const REVISION_REQUIRED = 'REVISION_REQUIRED';
    public const CHANGES_INVALID = 'CHANGES_INVALID';
    public const PRESENTATION_DRIFT = 'PRESENTATION_DRIFT';
    public const WRITER_BUSY = 'WRITER_BUSY';
    public const CONTRACT_FAILED = 'CONTRACT_FAILED';

    /** Recoverable: the same request with other values (or fresh revisions, or later) can pass. */
    private const RECOVERABLE = [
        self::SLOT_TOO_LONG, self::SLOT_EVIDENCE_REQUIRED, self::SLOT_NOT_CONTENT, self::SLOT_LINK_UNSUPPORTED, self::SLOT_IMAGE_INVALID,
        self::REVISION_STALE, self::REVISION_REQUIRED, self::WRITER_BUSY, self::SLOT_LOCKED_BY_USER,
    ];

    /** @var string */
    public $errorCode;
    /** @var string|null */
    public $slotKey;
    /** @var string|null */
    public $contentId;
    /** @var array<string,mixed> limit / actual / current / lockedBy, only when known */
    public $details;

    public function __construct(string $code, string $message, ?string $slotKey = null, ?string $contentId = null, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->slotKey = $slotKey;
        $this->contentId = $contentId;
        $this->details = $details;
    }

    public static function severity(string $code): string
    {
        return in_array($code, self::RECOVERABLE, true) ? 'recoverable' : 'unrecoverable';
    }

    /** The wire shape of one entry of `errors[]`. */
    public function toArray(): array
    {
        return self::entry($this->errorCode, $this->getMessage(), $this->slotKey, $this->contentId, $this->details);
    }

    public static function entry(string $code, string $message, ?string $slotKey = null, ?string $contentId = null, array $details = []): array
    {
        $out = [
            'code' => $code,
            'message' => $message,
            'field' => ['slotKey' => $slotKey, 'contentId' => $contentId],
            'severity' => self::severity($code),
        ];
        foreach (['limit', 'actual', 'current', 'lockedBy'] as $key) {
            if (array_key_exists($key, $details)) {
                $out[$key] = $details[$key];
            }
        }
        return $out;
    }
}

/**
 * Every problem one request has, refused together: whoever sent three bad slots is told about
 * all three at once. The message is the old messages joined, so a single problem reads exactly
 * as it always did. `$drift` carries the inspect problems a refused apply was blocked by, which
 * the door also answers as `problems`.
 */
final class ContractProblems extends RuntimeException
{
    /** @var ContractProblem[] */
    private $problems;
    /** @var string[] */
    private $drift;

    /** @param ContractProblem[] $problems at least one */
    public function __construct(array $problems, array $drift = [])
    {
        parent::__construct(implode('; ', array_map(static fn(ContractProblem $p) => $p->getMessage(), $problems)));
        $this->problems = array_values($problems);
        $this->drift = array_values($drift);
    }

    /** @return array<int,array<string,mixed>> */
    public function errors(): array
    {
        return array_map(static fn(ContractProblem $p) => $p->toArray(), $this->problems);
    }

    /** @return string[] */
    public function drift(): array
    {
        return $this->drift;
    }
}
