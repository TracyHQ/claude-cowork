<?php
/**
 * The versioned multilingual extension of a quickstart content contract.
 *
 * The base contract says a bound site must look EXACTLY like the published quickstart. Adding a
 * language necessarily breaks that: rows appear that the published archive never had. This class
 * is what makes the new rows checkable instead of merely recorded — for every derived row it can
 * recompute, from the SOURCE row plus this profile, exactly what that row must contain. The
 * binding says which ids exist; it never says what they are allowed to hold.
 *
 * 🔒 A RECORDED DELTA IS NOT A LICENCE. If `inspect()` trusted a snapshot stored at apply time,
 * one bad apply would launder any structural change into the baseline forever: the next inspect
 * would compare the site against the damage and call it clean. Everything here is DERIVED, so a
 * module that moved position, lost its access level or changed type fails on the next read no
 * matter what was written when it was created.
 *
 * 🔒 IT IS PINNED TO THE BASE BY FILE BYTES. `baseHash` is the sha256 of the three published
 * contract files, not of their decoded structure: PHP's json_encode keeps the key order it read
 * while JavaScript's JSON.stringify reorders integer-like keys, and the ACL rules objects have
 * exactly that shape — so a structural hash could not be produced by the generator on the other
 * side. Bytes are the one thing both languages agree on.
 */
final class MultilingualProfile
{
    private array $profile;
    private array $map;
    private array $lock;
    private string $hash;
    /** @var array<string,array<string,mixed>> slots of the base contract, grouped by entity key */
    private array $slotsByEntity = [];

    /** Separates a locale from the base key it derives from. Absent from every base key. */
    private const SEPARATOR = '::';

    /**
     * What a content slot's value is replaced by before two rows are compared, so a comparison is
     * about STRUCTURE and never about words. Defined here and used by the contract too: two
     * spellings of this placeholder would make a copy's masked row differ from its source's for a
     * reason no error message could explain.
     */
    public const CONTENT_SLOT = '__CONTENT_SLOT__';

    public function __construct(array $profile, array $map, array $lock, string $directory, string $rawProfile)
    {
        $this->profile = $profile;
        $this->map = $map;
        $this->lock = $lock;
        $this->hash = hash('sha256', $rawProfile);
        if (($profile['schemaVersion'] ?? '') !== 'tracy-quickstart-multilingual/v1')
            throw new RuntimeException('Unsupported multilingual profile');
        $lines = '';
        foreach (['manifest.json', 'content-map.json', 'presentation-lock.json'] as $name) {
            $file = $directory . '/' . $name;
            if (!is_file($file)) throw new RuntimeException('Missing contract file: ' . $name);
            $lines .= $name . ':' . hash_file('sha256', $file) . "\n";
        }
        if (!hash_equals((string) ($profile['baseHash'] ?? ''), hash('sha256', $lines)))
            throw new RuntimeException('The multilingual profile does not belong to this contract');
        foreach (['sourceLanguage', 'derive', 'policies', 'switcher', 'languageFilter', 'sourceDelta'] as $key)
            if (!isset($profile[$key])) throw new RuntimeException('Incomplete multilingual profile: ' . $key);
        // Every entity of the base contract must be classified. An unclassified one is not a
        // missing line in a file — it is a block of source-language text on a translated page,
        // and nothing else in the system would ever mention it.
        foreach ($map['entities'] as $entity)
            if (!isset($profile['policies'][$entity['key']]))
                throw new RuntimeException('Unclassified entity in multilingual profile: ' . $entity['key']);
        foreach ($map['slots'] as $slot) $this->slotsByEntity[$slot['entity']][] = $slot;
    }

    public function hash(): string { return $this->hash; }
    public function version(): string { return (string) $this->profile['extensionVersion']; }
    public function sourceLanguage(): string { return (string) $this->profile['sourceLanguage']; }
    public function unsupported(): array { return $this->profile['unsupported'] ?? []; }

    /** Base keys whose entity gets a copy per language; every other key is shared with a reason. */
    public function translatedKeys(): array
    {
        $keys = [];
        foreach ($this->profile['policies'] as $key => $policy)
            if (($policy['policy'] ?? '') === 'translate') $keys[] = $key;
        return $keys;
    }

    public function isTranslated(string $key): bool
    {
        return ($this->profile['policies'][$key]['policy'] ?? '') === 'translate';
    }

    public function coverage(): array
    {
        $out = ['translate' => [], 'shared' => []];
        foreach ($this->profile['policies'] as $key => $policy)
            $out[$policy['policy']][] = ['entity' => $key, 'kind' => $policy['kind']]
                + (isset($policy['reason']) ? ['reason' => $policy['reason']] : []);
        return $out;
    }

    public static function derivedKey(string $locale, string $baseKey): string
    {
        return $locale . self::SEPARATOR . $baseKey;
    }

    public static function isDerivedKey(string $key): bool
    {
        return strpos($key, self::SEPARATOR) !== false;
    }

    /** @return array{0:string,1:string} [locale, baseKey] */
    public static function splitDerived(string $key): array
    {
        $at = strpos($key, self::SEPARATOR);
        return [substr($key, 0, $at), substr($key, $at + strlen(self::SEPARATOR))];
    }

    /** The switcher is one module for the whole site, not one per language. */
    public static function switcherKey(): string { return 'multilingual::switcher'; }

    /** The URL segment Joomla routes a language on, derived from the tag so it is never a second fact. */
    public static function sefOf(string $locale): string
    {
        return strtolower(explode('-', $locale)[0]);
    }

    /**
     * The language already holding this locale's URL segment, or null when the segment is free.
     *
     * 🔒 `#__languages.sef` IS UNIQUE, AND THE SEGMENT IS DERIVED. `zh-CN` and `zh-TW` both derive
     * `zh`, so a site that publishes one cannot take the other — the row simply cannot be inserted.
     * The caller asks this at PLAN time because by apply time the customer has already paid for a
     * whole edition of translated strings.
     *
     * @param string $locale the tag being asked for.
     * @param array $have every language already on the site, the source included.
     */
    public static function sefClash(string $locale, array $have): ?string
    {
        $sef = self::sefOf($locale);
        foreach ($have as $one) {
            $one = (string) $one;
            if ($one !== '' && $one !== $locale && self::sefOf($one) === $sef) return $one;
        }
        return null;
    }

    /**
     * The editable slots of a derived entity: the source's own slots, re-keyed to the copy, plus a
     * title slot for a module that SHOWS its title.
     *
     * Re-keying rather than inventing is what makes a translation editable afterwards by exactly
     * the machinery that edits the original — the same length limits, the same markup refusal, the
     * same "cannot empty an occupied slot" rule (an empty ACM field is conditional markup, and that
     * is as true of the copy as of the source).
     *
     * A slot that is a URL or an image is present so it can be CORRECTED later, but it is not
     * translated when the copy is made: a link and a picture are the same in every language.
     *
     * @return array<int,array<string,mixed>>
     */
    public function derivedSlots(string $locale, string $baseKey, array $lockFields): array
    {
        $derivedKey = self::derivedKey($locale, $baseKey);
        $out = [];
        foreach ($this->slotsByEntity[$baseKey] ?? [] as $slot) {
            $slot['sourceKey'] = $slot['key'];
            $slot['key'] = $locale . self::SEPARATOR . $slot['key'];
            $slot['entity'] = $derivedKey;
            $slot['translates'] = $slot['type'] === 'text';
            // Evidence proves a factual claim against the customer brief. A translation makes no
            // new claim — it restates one already approved in the source — so demanding brief-
            // verbatim text here would refuse every correct translation there is.
            $slot['requiresEvidence'] = false;
            $out[] = $slot;
        }
        if (($this->profile['policies'][$baseKey]['kind'] ?? '') === 'module' && ($lockFields['showtitle'] ?? '0') === '1') {
            $out[] = [
                'key' => $locale . self::SEPARATOR . $baseKey . '.title',
                'sourceKey' => $baseKey . '.title',
                'entity' => $derivedKey,
                'column' => 'title',
                'type' => 'text',
                'sample' => (string) $lockFields['title'],
                'maxCharacters' => 100,
                'requiresEvidence' => false,
                'translates' => true,
                'label' => (string) $lockFields['title'],
            ];
        }
        return $out;
    }

    /** Whether this module shows the title it carries, which decides if the title is copy or a label. */
    public function showsTitle(string $baseKey, array $lockFields): bool
    {
        return ($this->profile['policies'][$baseKey]['kind'] ?? '') === 'module' && ($lockFields['showtitle'] ?? '0') === '1';
    }

    /**
     * What the SOURCE row must become once a second language exists.
     *
     * One field, and it is not cosmetic: a row left at `*` renders in every language, so the
     * English hero would sit on the Chinese page underneath the Chinese one. Joomla filters
     * `m.language IN (tag, '*')`, which is why `*` means "always", not "default".
     */
    public function sourceLanguageDelta(): array
    {
        return ['language' => $this->sourceLanguage()];
    }

    /**
     * The complete protected field set a derived row must carry, computed from the source.
     *
     * `$source` is the source entity's MASKED presentation — content slots already replaced by the
     * placeholder — so translated columns compare equal without this method knowing a word of any
     * language. Everything it does set is structure: which language the row is in, where it hangs,
     * and which ids its link points at.
     *
     * @param string $kind          article | module | menuItem
     * @param array  $source        masked presentation of the source row
     * @param array  $idMap         ['article'|'menuItem' => [sourceId => derivedId]] for this locale
     * @param array  $lockFields    the source's unmasked protected fields (for a module's label)
     */
    public function derivedPresentation(string $kind, string $baseKey, array $source, string $locale, array $idMap, array $lockFields, array $actual = []): array
    {
        $rule = $this->profile['derive'][$kind] ?? null;
        if (!$rule) throw new RuntimeException('No derivation rule for ' . $kind);
        $out = $source;
        $out['language'] = $locale;
        // "No image" has two spellings on a Joomla menu and they mean the same thing. The published
        // quickstart arrives from a database dump carrying `''`; anything Joomla's own Table writes
        // carries `' '`, because `Table\Menu::check()` replaces an empty img with a single space.
        // A copy is made through that Table, so demanding the dump's spelling would fail every
        // menu item on the site for a difference no visitor and no administrator can see.
        if ($kind === 'menuItem' && trim((string) ($source['img'] ?? '')) === '' && trim((string) ($actual['img'] ?? '')) === '')
            $out['img'] = $actual['img'];
        if ($kind === 'module') {
            $out['note'] = $this->marker($baseKey, $locale);
            // A module's title is CONTENT on the copy and a LABEL on the source, and the base
            // contract has no title slot for a module — so the two rows are masked differently on
            // purpose and the expectation has to say which side it is on. Shown: the copy carries a
            // translation, so it masks to the placeholder. Hidden: it is an administrator's label;
            // translating it would take the Module Manager away from the customer and change
            // nothing a visitor sees, so the copy is marked instead and compared literally.
            $out['title'] = $this->showsTitle($baseKey, $lockFields)
                ? self::CONTENT_SLOT
                : $lockFields['title'] . ' [' . $locale . ']';
        }
        if (isset($source['alias'])) $out['alias'] = $this->derivedAlias($kind, (string) $source['alias'], $locale);
        if ($kind === 'menuItem') {
            $out['note'] = $this->marker($baseKey, $locale);
            $parent = (int) $source['parent_id'];
            // Level 1 hangs off the menu root, which is shared; deeper items hang off the copy of
            // their own parent, so the whole tree is mirrored rather than flattened.
            $out['parent_id'] = (string) ($idMap['menuItem'][$parent] ?? $parent);
            $out['link'] = $this->remapLink((string) $source['link'], $idMap);
        }
        return $out;
    }


    /**
     * What a translation may not lose.
     *
     * A model rewriting a sentence will happily drop a price, round a number, localise a domain or
     * swallow a placeholder, and the result reads perfectly in the target language — which is why
     * no reviewer catches it. These are the tokens whose MEANING is identical in every language, so
     * a translation that does not carry them through is wrong by construction, not by taste.
     *
     * Single digits are deliberately exempt: "Chapter 1" becoming "第一章" is a correct translation,
     * and a rule that refused it would be refusing the job. A figure with a currency mark, a
     * percentage, a decimal point or two or more digits is a fact, and stays.
     *
     * @return string[] one sentence per loss, empty when the translation preserves everything
     */
    public static function preservationErrors(string $source, string $target): array
    {
        $out = [];
        /**
         * A figure carrying a SCALE WORD is checked differently, because verbatim is the wrong
         * rule for it. "$44bn" is "44 milliards de dollars" in French and "440亿美元" in Chinese:
         * French keeps the digits and translates the scale, Chinese folds the scale into the
         * number. Both are correct; neither contains "$44bn". Measured 2026-09-12 across two real
         * runs of 969 strings — three refusals, all three of them correct translations.
         *
         * What must still be true is that a NUMBER survived. The trap is that a sentence can carry
         * digits of its own: "$44bn O2, Virgin Media" dropped whole still leaves the 2 of O2. So
         * any source word that contains a digit and is carried over verbatim is struck out of the
         * target first; whatever digits remain are the ones the translation produced for this
         * figure.
         */
        $scale = '(?:bn|tn|k|m|b|billion|million|thousand|trillion)';
        $scaled = '~(?<![\w.])[$€£¥]?\s?\d[\d,.]*\s?' . $scale . '\b~ui';
        if (preg_match($scaled, $source, $which)) {
            $left = $target;
            foreach (preg_split('~\s+~u', $source) as $word) {
                $word = trim($word, ".,;:!?()[]\"'");
                if ($word !== '' && preg_match('~\d~u', $word) && !preg_match($scaled, $word)
                    && mb_strpos($left, $word) !== false)
                    $left = str_replace($word, ' ', $left);
            }
            if (!preg_match('~\d~u', $left))
                $out[] = 'the figure ' . trim($which[0]) . ' left no number in the translation';
            $source = (string) preg_replace($scaled, ' ', $source);
        }
        /**
         * 🔒 A FIGURE IS COMPARED BY ITS VALUE, NOT BY ITS SPELLING.
         *
         * Digit grouping is part of a language, not part of a fact. Vietnamese writes `4.800`
         * where British English writes `4,800`, and `6,2` where English writes `6.2`; German,
         * Spanish, Italian, Portuguese, Russian, Turkish and French all group with a dot or a
         * space. Comparing the characters refuses every one of those, and it refused them on text
         * TRACY ITSELF SHIPPED — measured 22/09/2026 on the reviewed `vi-VN` edition of the
         * Business quickstart: `4,800` → `4.800`, `6.2` → `6,2`, `4,000` → `4.000`, all correct,
         * all refused, and the build stopped at the `language` stage with nothing wrong.
         *
         * So both sides are reduced to a key in which every separator — comma, dot, ordinary
         * space, non-breaking and narrow space — reads the same, and the currency mark and percent
         * sign are dropped (`figureKey()`). The digits and their POSITIONS still have to match, so
         * `4,800` → `4,900` is refused exactly as before, and a figure dropped outright still is.
         */
        $figures = self::figuresIn($target);
        preg_match_all(self::FIGURE, $source, $sourceFigures);
        foreach (array_count_values(array_map([self::class, 'figureKey'], $sourceFigures[0])) as $key => $times)
            if (($figures[$key] ?? 0) < $times)
                $out[] = 'the figure ' . self::spellOut($sourceFigures[0], $key) . ' is missing from the translation';
        $checks = [
            'URL' => '~https?://[^\s<>"\)]+~',
            'email address' => '~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~',
            'placeholder' => '~%[0-9]+\$?[sd]|%[sd]~',
        ];
        foreach ($checks as $what => $pattern) {
            preg_match_all($pattern, $source, $found);
            foreach (array_count_values($found[0]) as $token => $times) {
                $token = (string) $token;
                $seen = substr_count($target, $token);
                if ($seen < $times)
                    $out[] = 'the ' . $what . ' ' . $token . ' is missing from the translation';
            }
        }
        return $out;
    }

    /**
     * What counts as a figure: a currency amount, a percentage, or a number of two digits or more,
     * written with any of the separators the world's locales use between and inside its digits.
     *
     * A single digit is deliberately outside it — "Chapter 1" becoming "第一章" is a correct
     * translation, and a rule that refused it would be refusing the job.
     */
    /**
     * Whether a translation brings markup its source did not have.
     *
     * 🔒 AN ANGLE BRACKET IS MARKUP ONLY WHERE THE SOURCE HAS NONE. Refusing `<` and `>` outright
     * refuses a translation for FAITHFULLY KEEPING what its source says. Measured 22/09/2026 on
     * the Business quickstart: slot `module-678.9` is an email preview whose published English
     * reads `From: Northgate <no-reply@northgate-ind.ru>` — the address in the angle brackets every
     * mail client writes — and the reviewed Vietnamese edition Tracy ships keeps it. The check
     * called that markup and stopped the build with nothing wrong on either side.
     *
     * What the rule is for is a translation INTRODUCING markup into a slot that had none, so that
     * is what it now asks. A plain-text slot is unchanged — no brackets in, none allowed out — and
     * a slot that legitimately carries one cannot be grown into a tag.
     */
    public static function markupIntroduced(string $source, string $target): bool
    {
        return preg_match_all('/[<>]/u', $target) > preg_match_all('/[<>]/u', $source);
    }

    /** One more digit, or a separator that has a digit behind it. A plain space groups only by threes. */
    private const FIGURE_STEP = '(?:\d|[.,\x{00A0}\x{202F}\x{2009}](?=\d)|\x20(?=\d{3}(?!\d)))';
    private const FIGURE = '~(?<![\w.])(?:'
        // A currency amount, single-digit included: "$0 free forever" is a price.
        . '[$€£¥]\s?\d' . self::FIGURE_STEP . '*'
        // The same amount with the mark on the other side: French writes "$0" as "0 $".
        . '|\d' . self::FIGURE_STEP . '*\s?[$€£¥]'
        // A percentage, same reason.
        . '|\d' . self::FIGURE_STEP . '*\s?%'
        // Otherwise a number needs a second digit or a separator: a lone digit is a WORD in most
        // languages and a fact in none, so "Chapter 1" → "第一章" must not be refused.
        . '|\d' . self::FIGURE_STEP . self::FIGURE_STEP . '*'
        . ')~u';

    /**
     * One figure reduced to the value it names, so two spellings of the same number compare equal.
     *
     * Separators are levelled rather than deleted: deleting them would make `6.2` and `62` the
     * same figure, and a translation that turned six-point-two into sixty-two would pass. Levelled,
     * `6.2` and `6,2` are one key while `62` is another — and `4,800` still differs from `4,900`.
     */
    private static function figureKey(string $token): string
    {
        // A space standing BETWEEN digits is a separator (French and Russian group that way);
        // any other space is not part of the value.
        $key = (string) preg_replace('~(?<=\d)[\x{00A0}\x{202F}\x{2009}\x20](?=\d)~u', '.', $token);
        // ⚠ THE MARK IS KEPT, ITS SIDE IS NOT. A price may be written "$24" or "24 $" and mean the
        // same thing, but "24" alone has lost the currency — measured as a real refusal worth
        // keeping. So the mark moves to the front of the key rather than being dropped.
        preg_match('~[$€£¥%]~u', $key, $mark);
        $key = (string) preg_replace('~[\s\x{00A0}\x{202F}\x{2009}$€£¥%]~u', '', $key);
        return ($mark[0] ?? '') . str_replace(',', '.', $key);
    }

    /** Every figure the text carries, counted by value. */
    private static function figuresIn(string $text): array
    {
        preg_match_all(self::FIGURE, $text, $found);
        $out = [];
        foreach ($found[0] as $token) {
            $key = self::figureKey($token);
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        return $out;
    }

    /** The figure as the SOURCE wrote it, so the complaint quotes text the author can search for. */
    private static function spellOut(array $tokens, string $key): string
    {
        foreach ($tokens as $token) if (self::figureKey($token) === $key) return trim($token);
        return $key;
    }

    /**
     * The alias a copy takes, and the two Joomla rules behind the two answers.
     *
     * A menu item keeps its source's alias: `#__menu` is unique on
     * `(client_id, parent_id, alias, language)`, so two languages may share a path and
     * `/en/resources/style-guide` and `/zh/resources/style-guide` are the same route in two
     * editions. An article cannot: `Table\Content::store()` checks `(alias, catid)` and ignores
     * language entirely, so a copy sharing its source's alias is refused outright. The suffix is
     * the URL segment of the language, which is already in the address.
     */
    public function derivedAlias(string $kind, string $sourceAlias, string $locale): string
    {
        return ($this->profile['derive'][$kind]['alias'] ?? 'source') === 'source-sef'
            ? $sourceAlias . '-' . self::sefOf($locale)
            : $sourceAlias;
    }

    /** The provenance stamp on a copy, independent of the private binding that also records it. */
    public function marker(string $baseKey, string $locale): string
    {
        return 'tracy-ml:' . $baseKey . ':' . $locale;
    }

    /**
     * Point a copied link at the copies of what it referenced.
     *
     * 🔒 `id=` MEANS DIFFERENT THINGS UNDER DIFFERENT VIEWS, AND GUESSING IS NOT AVAILABLE. In a
     * Joomla menu link the same parameter names an ARTICLE under `view=article`, a CATEGORY under
     * `view=category`, and a contact under `com_contact&view=contact`. Only the first has a copy to
     * point at — categories are shared by this profile, and so is everything outside com_content.
     *
     * Measured 2026-09-12: mapping every `id=` through the article table rewrote the Team page's
     * `view=category&…&id=10` to the id of the copy of ARTICLE 10, and `/zh/company/team` answered
     * 404 while `/en/company/team` served the page. Seven of the forty-six menu links had that
     * shape. The rule is therefore narrow on purpose: a reference this cannot prove the meaning of
     * is a reference it does not touch.
     *
     * A raw path needs no remapping at all: a menu copy keeps its source's alias, so
     * `/resources/style-guide` is the same path under both language prefixes.
     */
    public function remapLink(string $link, array $idMap): string
    {
        // 🔒 ONLY THIS SITE'S OWN LINKS. `id=` and `Itemid=` mean something here and nothing
        // anywhere else, so a link that names another host — or a mail or telephone address — is
        // left exactly as written. Without this guard a customer's link to a supplier's catalogue
        // page at `?id=1` came back pointing at `?id=101`, a page on a server Tracy does not own.
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $link)) return $link;
        // An Itemid is a menu item under every view there is, so it maps unconditionally.
        $link = (string) preg_replace_callback(
            '~\bItemid=(\d+)~',
            static function (array $match) use ($idMap): string {
                $id = (int) $match[1];
                return 'Itemid=' . ($idMap['menuItem'][$id] ?? $id);
            },
            $link
        );
        // A plain `id=` is an article only here: com_content's single-article view.
        $query = (string) parse_url($link, PHP_URL_QUERY);
        parse_str($query, $args);
        if (($args['option'] ?? '') !== 'com_content' || ($args['view'] ?? '') !== 'article') return $link;
        return (string) preg_replace_callback(
            '~\bid=(\d+)~',
            static function (array $match) use ($idMap): string {
                $id = (int) $match[1];
                return 'id=' . ($idMap['article'][$id] ?? $id);
            },
            $link
        );
    }

    /** The fields a created copy carries beyond its protected set, per kind. */
    public function carried(string $kind): array
    {
        return $this->profile['derive'][$kind]['carry'] ?? [];
    }

    public function association(string $kind): ?string
    {
        return $this->profile['derive'][$kind]['association'] ?? null;
    }

    /**
     * The language switcher, as one module whose every field is fixed here.
     *
     * Named type and named position on purpose: a `mod_custom` dropped into whatever slot looked
     * free is how a switcher ends up replacing a customer's content. This one is `mod_languages`
     * in the slot the quickstart already gives its header actions.
     */
    public function switcherPresentation(): array
    {
        $s = $this->profile['switcher'];
        return [
            'title' => 'Languages',
            'note' => $s['note'],
            'content' => '',
            'ordering' => (string) ($s['ordering'] ?? '9'),
            'position' => $s['position'],
            'publish_up' => null,
            'publish_down' => null,
            'published' => $s['published'],
            'module' => $s['module'],
            'access' => $s['access'],
            'showtitle' => $s['showtitle'],
            'params' => $s['params'],
            'client_id' => '0',
            'language' => $s['language'],
        ];
    }

    /** Which module the switcher stands beside, so its position is answerable from the contract. */
    public function switcherAnchor(): string { return (string) $this->profile['switcher']['anchorEntity']; }

    /** The seven language-filter settings written explicitly, because Joomla ships this plugin with none. */
    public function languageFilterParams(): array { return $this->profile['languageFilter']['params']; }

    /** The content-language row a published language needs; the pack install already made the row. */
    public function contentLanguageFields(string $locale, string $nativeName): array
    {
        return [
            'lang_code' => $locale,
            'title' => $nativeName,
            'title_native' => $nativeName,
            'sef' => self::sefOf($locale),
            'image' => strtolower(str_replace('-', '_', $locale)),
            'published' => 1,
            'access' => 1,
        ];
    }
}
