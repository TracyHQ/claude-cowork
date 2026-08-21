<?php
/**
 * In-memory stand-ins for the write side, so an Apply and its undo can be exercised with no web
 * server, no database and no WordPress — the same reason FakeRowSource exists for the read side.
 *
 * What these prove is the ENGINE's promise: every edit is recorded before it counts as done, and
 * an edit whose undo could not be recorded is rolled back rather than left standing. What the real
 * writers do with a post or an option is the other half, and it is tested against the WordPress
 * stubs in FakeWordPress.php.
 */
declare(strict_types=1);

final class FakeSiteWriter implements SiteWriter
{
    /** @var array<int,array<string,mixed>> Rows the mirror should see, in id order. */
    public array $posts = [];

    public function list_posts(int $offset, int $limit, bool $withBody): array
    {
        $rows = array_slice($this->posts, $offset, $limit);
        if (!$withBody) {
            foreach ($rows as $i => $row) {
                unset($rows[$i]['content'], $rows[$i]['excerpt']);
            }
        }
        return array_values($rows);
    }

    /** @var array<string,array<string,array<string,mixed>>> kind => target => fields */
    public array $store = [];
    private int $nextId = 100;
    public int $purges = 0;

    public function read(string $kind, int $id, string $key = ''): ?array
    {
        return $this->store[$kind][$this->slot($id, $key)] ?? null;
    }

    public function write(string $kind, int $id, array $fields, string $key = ''): int
    {
        if ('post' === $kind && 0 === $id) {
            $id = $this->nextId++;
        }
        $this->store[$kind][$this->slot($id, $key)] = $fields;
        return $id;
    }

    public function delete(string $kind, int $id, string $key = ''): void
    {
        unset($this->store[$kind][$this->slot($id, $key)]);
    }

    public function purgeCache(): void
    {
        $this->purges++;
    }

    /** A post is addressed by id, a meta by both, an option by name alone. */
    private function slot(int $id, string $key): string
    {
        return '' === $key ? (string) $id : $id . ':' . $key;
    }
}

final class FakeMediaWriter implements MediaWriter
{
    /** @var array<string,string> path => bytes */
    public array $store = [];
    /** @var array<int,string> attachment id => path */
    public array $attachments = [];
    private int $nextId = 900;

    public function read(string $path): ?string
    {
        return $this->store[$path] ?? null;
    }

    public function write(string $path, string $bytes): int
    {
        $isNew = !isset($this->store[$path]);
        $this->store[$path] = $bytes;
        if (!$isNew) {
            return 0;
        }
        $id = $this->nextId++;
        $this->attachments[$id] = $path;
        return $id;
    }

    public function delete(string $path): void
    {
        unset($this->store[$path]);
    }

    public function deleteAttachment(int $attachmentId): void
    {
        // As WordPress does: the attachment takes its file with it.
        if (isset($this->attachments[$attachmentId])) {
            unset($this->store[$this->attachments[$attachmentId]]);
            unset($this->attachments[$attachmentId]);
        }
    }
}

final class FakeApplyLog implements ApplyLog
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $log = [];

    public function record(string $applyId, array $entry): void
    {
        $this->log[$applyId][] = $entry;
    }

    public function entries(string $applyId): array
    {
        return $this->log[$applyId] ?? [];
    }

    public function clear(string $applyId): void
    {
        unset($this->log[$applyId]);
    }
}

/** A log that cannot record — to prove a write with no recordable undo is rolled back. */
final class FailingApplyLog implements ApplyLog
{
    public function record(string $applyId, array $entry): void
    {
        throw new RuntimeException('log write failed');
    }

    public function entries(string $applyId): array
    {
        return [];
    }

    public function clear(string $applyId): void
    {
    }
}
