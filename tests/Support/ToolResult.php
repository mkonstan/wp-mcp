<?php
/**
 * One unwrapped MCP tool result: the isError flag and the single text block.
 *
 * On success the text is the tool's own JSON, as a string - that is how this server
 * serialises a tool return value - so data() decodes it. On failure the text is the
 * human message and there is no JSON, which is why data() refuses rather than
 * returning an empty array: a test that asserts on fields of a failed call would
 * otherwise pass by asserting nothing.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class ToolResult
{
    public function __construct(
        public readonly bool $isError,
        public readonly string $text
    ) {
    }

    /** The tool's decoded return value. Only valid when isError is false. */
    public function data(): array
    {
        if ($this->isError) {
            throw new RuntimeException(
                'The tool call failed, so it has no data. Message: ' . $this->text
            );
        }

        $decoded = json_decode($this->text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The tool result text is not JSON: ' . $this->text);
        }

        return $decoded;
    }

    /** The `items` list of a list-* tool. */
    public function items(): array
    {
        $data = $this->data();

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('The tool result has no items list: ' . $this->text);
        }

        return $data['items'];
    }

    /** Every value of one field across `items`. */
    public function column(string $field): array
    {
        return array_map(
            static fn (array $item) => $item[$field] ?? null,
            $this->items()
        );
    }

    /**
     * Every JSON path at which $value occurs as a VALUE of the decoded result, as
     * `items[2].id` - empty when it occurs nowhere.
     *
     * WHY THIS EXISTS, AND IT IS THE SECOND TIME THIS PROJECT HAS PAID FOR NOT HAVING IT.
     * "This id must not appear in the answer" used to be written
     * `assertStringNotContainsString((string) $id, $result->text)`, against the whole
     * SERIALIZED envelope. A serialized envelope contains numbers the test did not put
     * there - `count`, `page`, `limit`, `has_more`, a byte length, a timestamp - so the
     * assertion is not about the id at all: it is about whether the id's digits happen to
     * occur anywhere in the payload's own bookkeeping. CI run 36071072879 is the proof.
     * `PostFilterReadsTest` asserted a private post's id was absent, the security property
     * HELD (`items` was `[]` and `count` was `0`, both asserted separately and both green),
     * and the run went RED because the post's id was 10 and the envelope said
     * `"limit":100`. It surfaced when a new test class re-balanced the CI shards and moved
     * that class into a container where the fixture took a low auto-increment id. The first
     * time was `MenuToolsTest` assuming a post id and a `nav_menu` term id could not
     * collide; on a near-empty shard container both were 6.
     *
     * SO THE RULE, AND IT IS A CLASS RULE: assert identity against the PARSED structure,
     * never against a substring of a serialized payload. This walks the decoded result and
     * compares each SCALAR LEAF for identity, so `10` does not match `100` and does not
     * match the `100` inside a `limit`. The path is returned rather than a boolean because
     * "it is in there" is not actionable and "it is in there at `items[0].id`" is.
     *
     * TYPE-LOOSE ON PURPOSE, BUT ONLY FOR SCALARS THAT ARE IDENTIFIERS. An id arrives as an
     * int from the database and as an int in the answer, but a test may hold it as a string
     * and a tool may render it as one, so `10` and `"10"` are the same identifier here.
     * `true` and `null` are NOT, and are skipped - without that, `mentions(1)` would match
     * every `"has_more": true`, which is the same accident in a new costume.
     *
     * ------------------------------------------------------------------------------------
     * EXACTLY WHAT IT CHECKS, AND EXACTLY WHAT IT DOES NOT. Read this before trusting it
     * against a payload that carries strings; the first version of this docblock spoke of "a
     * future message" as though an embedded id were covered, and it is not.
     *
     *   IT FINDS    a SCALAR LEAF of the decoded result whose value, as a string, EQUALS the
     *               identifier - at any depth, in an object or a list, int, float or numeric
     *               string. Float `10.0` matches `10`, which is the loud direction and fine.
     *   IT DOES NOT a string leaf that merely CONTAINS the identifier. `"?p=10"` is not a
     *   FIND        mention of `10`, and `"Post 10 was deleted"` is not either.
     *
     * THAT EXEMPTION IS THE WHOLE DESIGN, not an oversight: containment is the defect this
     * function exists to remove, and a containment check would re-admit the `"limit":100`
     * failure it was written for. It is CORRECT FOR THE ONLY CONSUMER TODAY -
     * PostFilterReadsTest::assertFindsNothing(), which runs this only after asserting
     * `items === []`, and an empty list-posts envelope has no string leaves at all. A future
     * consumer whose answer DOES carry strings - a link, a message, a rendered title - needs
     * its own assertion about that field, and must not read this one as covering it.
     *
     * THREE MEASURED EDGES, so nobody has to rediscover them:
     *   - `mentions('')` matches any empty-string leaf. Every caller guards on a positive id,
     *     which is why assertFindsNothing() takes an int and tests `> 0`.
     *   - `mentions(0)` and `mentions('0')` hit an envelope's own `count`, and a `parent` of 0.
     *     Same guard, same reason.
     *   - the paths come back in document order, and ALL of them, not just the first.
     *
     * @param int|string $value the identifier that must (or must not) be in the answer
     * @return list<string> the paths, in document order
     */
    public function mentions(int|string $value): array
    {
        $needle = (string) $value;
        $found  = [];

        self::walkLeaves($this->data(), '', $needle, $found);

        return $found;
    }

    /**
     * @param mixed         $node
     * @param list<string>  $found
     */
    private static function walkLeaves($node, string $path, string $needle, array &$found): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $step = is_int($key) ? $path . '[' . $key . ']' : ($path === '' ? (string) $key : $path . '.' . $key);
                self::walkLeaves($child, $step, $needle, $found);
            }

            return;
        }

        // Booleans and null are not identifiers. (string) true is '1'.
        if (is_bool($node) || $node === null) {
            return;
        }

        if ((string) $node === $needle) {
            // $path cannot be empty here: data() refuses a result that is not an array, so a
            // scalar at the root does not reach this walk at all.
            $found[] = $path;
        }
    }
}
