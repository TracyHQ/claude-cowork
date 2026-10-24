<?php
/**
 * Read, set or remove ONE top-level key of a JSON object held as text, leaving every other byte as it was.
 *
 * 🔒 NOT A DECODE AND RE-ENCODE. A Joomla row's `params` was written by whoever wrote it last — the
 * installer's SQL, the admin form through Registry, a template's own tool — each with its own escaping
 * of slashes and unicode, its own spacing and its own types. Re-encoding the whole object to change one
 * key would rewrite all of them, and a revert could then never hand the row back as it was. Only the
 * key's own value moves; every edit is proven by decoding the result and comparing it with the input
 * changed in that one key, and refused when the two disagree.
 */
final class JsonObjectKey
{
    /**
     * Where `$key` stands in `$json`.
     *
     * @return array{exists:bool, literal:?string, value:mixed}
     * @throws RuntimeException when `$json` is not one JSON object, or names `$key` twice
     */
    public static function get(string $json, string $key): array
    {
        $members = self::members($json)['members'];
        $found = array_values(array_filter($members, fn ($m) => $m['key'] === $key));
        if (count($found) > 1) throw new RuntimeException('The JSON object names ' . $key . ' twice');
        if (!$found) return ['exists' => false, 'literal' => null, 'value' => null];
        $literal = substr($json, $found[0]['value'], $found[0]['end'] - $found[0]['value']);
        return ['exists' => true, 'literal' => $literal, 'value' => json_decode($literal, true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * `$json` with `$key` set to the JSON literal `$literal`, or removed when `$literal` is null. A new key
     * is added last, as Joomla's Registry adds one.
     */
    public static function set(string $json, string $key, ?string $literal): string
    {
        if ($literal !== null) json_decode($literal, false, 512, JSON_THROW_ON_ERROR);
        ['members' => $members, 'open' => $open] = self::members($json);
        $index = null;
        foreach ($members as $n => $m) if ($m['key'] === $key) {
            if ($index !== null) throw new RuntimeException('The JSON object names ' . $key . ' twice');
            $index = $n;
        }
        if ($index !== null && $literal !== null) {
            $m = $members[$index];
            $out = substr($json, 0, $m['value']) . $literal . substr($json, $m['end']);
        } elseif ($index !== null) {
            $m = $members[$index];
            // The member and ONE comma beside it: the one after it, or before it when it is the last.
            if (isset($members[$index + 1])) $out = substr($json, 0, $m['start']) . substr($json, $members[$index + 1]['start']);
            elseif ($index > 0) $out = substr($json, 0, $members[$index - 1]['end']) . substr($json, $m['end']);
            else $out = substr($json, 0, $open + 1) . substr($json, $m['end']);
        } elseif ($literal !== null) {
            $pair = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . $literal;
            $at = $members ? end($members)['end'] : $open + 1;
            $out = substr($json, 0, $at) . ($members ? ',' : '') . $pair . substr($json, $at);
        } else {
            return $json;
        }
        $want = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ($literal === null) unset($want[$key]);
        else $want[$key] = json_decode($literal, true, 512, JSON_THROW_ON_ERROR);
        $got = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        if ($got !== $want) throw new RuntimeException('Changing ' . $key . ' would change more of the JSON object than that key');
        return $out;
    }

    /**
     * The top-level members of a JSON object: key, and the byte offsets of the member's start, its
     * value and the value's end.
     *
     * @return array{open:int, members:list<array{key:string,start:int,value:int,end:int}>}
     */
    private static function members(string $json): array
    {
        if (!is_object(json_decode($json, false, 512))) throw new RuntimeException('Not a JSON object');
        $n = strlen($json);
        $i = self::space($json, 0);
        $open = $i;
        $i++;
        $members = [];
        while (true) {
            $i = self::space($json, $i);
            if ($json[$i] === '}') break;
            if ($json[$i] === ',') { $i = self::space($json, $i + 1); }
            $start = $i;
            $keyEnd = self::stringEnd($json, $i);
            $key = json_decode(substr($json, $i, $keyEnd - $i), true, 512, JSON_THROW_ON_ERROR);
            $i = self::space($json, $keyEnd);
            $i = self::space($json, $i + 1); // ':'
            $value = $i;
            $end = self::valueEnd($json, $i, $n);
            $members[] = ['key' => (string) $key, 'start' => $start, 'value' => $value, 'end' => $end];
            $i = $end;
        }
        return ['open' => $open, 'members' => $members];
    }

    private static function space(string $json, int $i): int
    {
        return $i + strspn($json, " \t\r\n", $i);
    }

    /** The offset just past the string that starts at `$i`. */
    private static function stringEnd(string $json, int $i): int
    {
        for ($i++; ; $i++) {
            if ($json[$i] === '\\') { $i++; continue; }
            if ($json[$i] === '"') return $i + 1;
        }
    }

    /** The offset just past the value that starts at `$i`. */
    private static function valueEnd(string $json, int $i, int $n): int
    {
        $c = $json[$i];
        if ($c === '"') return self::stringEnd($json, $i);
        if ($c === '{' || $c === '[') {
            for ($depth = 0; $i < $n; $i++) {
                $c = $json[$i];
                if ($c === '"') { $i = self::stringEnd($json, $i) - 1; continue; }
                if ($c === '{' || $c === '[') $depth++;
                elseif ($c === '}' || $c === ']') { if (--$depth === 0) return $i + 1; }
            }
            throw new RuntimeException('Not a JSON object');
        }
        return $i + strcspn($json, ",} \t\r\n", $i);
    }
}
