<?php
/**
 * One reason a content-contract operation refused, in a shape an agent can act on without parsing prose.
 *
 * The prose stays: `message` is exactly the sentence the receiver always said, because relays and
 * tests written against it still read it. What this adds is the part prose cannot carry reliably —
 * a stable code, WHICH slot and WHICH content, whether a retry with different input can succeed,
 * and the numbers (a limit, the current revision) the next attempt needs.
 *
 * Extends RuntimeException so every existing `catch (RuntimeException)` still catches it.
 * `$reason` and not `$code`: Exception::$code is an int and is taken.
 */
final class ContractProblem extends RuntimeException
{
    /** Codes a caller can fix by sending something different; every other code is unrecoverable. */
    private const RECOVERABLE = ['SLOT_TOO_LONG', 'SLOT_EVIDENCE_REQUIRED', 'SLOT_NOT_CONTENT', 'SLOT_LINK_UNSUPPORTED',
        'SLOT_IMAGE_INVALID', 'REVISION_STALE', 'REVISION_REQUIRED', 'WRITER_BUSY', 'CONFLICT',
        // Recoverable by someone else: the admin who has the record open saves and closes it.
        'SLOT_LOCKED_BY_USER'];

    public string $reason;
    public ?string $slotKey;
    public ?string $contentId;
    /** @var array<string,mixed> limit, actual, current, lockedBy */
    public array $extra;
    /** @var list<ContractProblem> Every problem this refusal carries, itself included. */
    public array $problems;

    public function __construct(string $reason, string $message, ?string $slotKey = null, ?string $contentId = null, array $extra = [])
    {
        parent::__construct($message);
        $this->reason = $reason; $this->slotKey = $slotKey; $this->contentId = $contentId; $this->extra = $extra;
        $this->problems = [$this];
    }

    /** One refusal carrying every problem found, so a caller fixes them all in one round. */
    public static function all(array $problems): self
    {
        if (count($problems) === 1) return $problems[0];
        $first = $problems[0];
        $all = new self($first->reason, implode('; ', array_map(fn(self $p) => $p->getMessage(), $problems)), $first->slotKey, $first->contentId, $first->extra);
        $all->problems = $problems;
        return $all;
    }

    public function toArray(): array
    {
        return ['code' => $this->reason, 'message' => $this->getMessage(),
            'field' => ['slotKey' => $this->slotKey, 'contentId' => $this->contentId],
            'severity' => self::severityOf($this->reason)] + $this->extra;
    }

    public static function severityOf(string $code): string
    {
        return in_array($code, self::RECOVERABLE, true) ? 'recoverable' : 'unrecoverable';
    }

    /** The `errors[]` of a refusal: the problems a ContractProblem carries, or one of `$code` for anything else. */
    public static function errorsOf(Throwable $error, string $code = 'CONTRACT_FAILED'): array
    {
        if ($error instanceof self) return array_map(fn(self $p) => $p->toArray(), $error->problems);
        return [self::plain($code, $error->getMessage())];
    }

    /** An `errors[]` entry for a refusal no exception carried (a returned error, not a thrown one). */
    public static function plain(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message, 'field' => ['slotKey' => null, 'contentId' => null], 'severity' => self::severityOf($code)];
    }
}
