<?php
/**
 * THE DETECTOR IS ITSELF TESTED, and it has to be: `ToolResult::mentions()` is now what
 * several integration assertions mean by "this id is not in the answer", and a helper that
 * silently answered "not there" for everything would make every one of them a green no-op -
 * the "test that cannot fail" this project's build plan forbids.
 *
 * It replaces `assertStringNotContainsString((string) $id, $result->text)`, which is the
 * defect this class pins in both directions. CI run 36071072879 failed on
 *
 *     Failed asserting that '{"count":0,"page":1,"limit":100,"has_more":false,"items":[]}'
 *     does not contain "10".
 *
 * - an EMPTY answer, so the security property held, and the id `10` was found inside the
 * envelope's own `"limit":100`. The first case below is that exact payload.
 *
 * @group sprint-seam
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\ToolResult;

final class ToolResultMentionsTest extends TestCase
{
    /** The payload from the CI failure, byte for byte. */
    private const EMPTY_PAGE = '{"count":0,"page":1,"limit":100,"has_more":false,"items":[]}';

    /**
     * The regression: an id whose digits sit inside another number is NOT a mention.
     *
     * @group sprint-seam
     */
    public function testAnIdIsNotFoundInsideAnotherNumber(): void
    {
        $result = new ToolResult(false, self::EMPTY_PAGE);

        self::assertStringContainsString(
            '10',
            $result->text,
            'The fixture no longer reproduces the collision, so this test proves nothing:'
            . ' the whole point is that "10" IS a substring of this payload.'
        );

        self::assertSame(
            [],
            $result->mentions(10),
            'Id 10 was reported as present in an EMPTY answer, which is the exact false'
            . ' failure that took CI red: its digits are inside "limit":100.'
        );
        // AND `0` IS A REAL MENTION OF `count`, which is why every caller guards on an id
        // being positive. Asserted rather than avoided: a helper that silently ignored zero
        // would be hiding a case instead of drawing a line.
        self::assertSame(['count'], $result->mentions(0), 'count is 0 and was not reported.');
    }

    /**
     * And it DOES find one that is really there, by path - or the test above passes because
     * the helper never finds anything.
     *
     * @group sprint-seam
     */
    public function testAnIdThatIsReallyThereIsFoundWithItsPath(): void
    {
        $result = new ToolResult(false, json_encode([
            'count'    => 2,
            'page'     => 1,
            'limit'    => 100,
            'has_more' => false,
            'items'    => [
                ['id' => 10, 'title' => 'first', 'author' => 7],
                ['id' => 100, 'title' => '10', 'author' => 7],
            ],
        ]));

        // BOTH paths, and the second one is the belt-and-braces property doing its job: the
        // id must not be ANYWHERE in the answer, and a title that happens to be the string
        // "10" is somewhere. Identity, not containment - so `items[1].id` of 100 is not a hit.
        self::assertSame(
            ['items[0].id', 'items[1].title'],
            $result->mentions(10),
            'The real id was not found at every path it occupies.'
        );
        // 100 really IS in this envelope twice - as `limit` and as an item id - and the paths
        // say which is which. That ambiguity is exactly what a substring check could not
        // express, and why it failed CI on an EMPTY answer.
        self::assertSame(['limit', 'items[1].id'], $result->mentions(100));
        self::assertSame(
            ['items[0].author', 'items[1].author'],
            $result->mentions(7),
            'Every path is reported, in document order, not just the first.'
        );
    }

    /**
     * A STRING LEAF THAT EQUALS THE ID COUNTS, because a tool may render an id as a string
     * and the test holds an int. Equality, never containment: `"100"` is not `10`.
     *
     * @group sprint-seam
     */
    public function testAnIdRenderedAsAStringIsTheSameIdentifier(): void
    {
        $result = new ToolResult(false, json_encode(['items' => [['id' => '10'], ['id' => '100']]]));

        self::assertSame(['items[0].id'], $result->mentions(10));
        self::assertSame(['items[0].id'], $result->mentions('10'));
        self::assertSame(['items[1].id'], $result->mentions(100));
    }

    /**
     * `true` AND `null` ARE NOT IDENTIFIERS, and this is the same accident in a new costume:
     * `(string) true` is `'1'`, so a naive walk would report user id 1 as present in every
     * answer that carries `"has_more": true`.
     *
     * @group sprint-seam
     */
    public function testBooleansAndNullAreNotIdentifiers(): void
    {
        $result = new ToolResult(false, json_encode([
            'has_more' => true,
            'parent'   => null,
            'items'    => [],
        ]));

        self::assertSame([], $result->mentions(1), 'A boolean true was read as the id 1.');
        self::assertSame([], $result->mentions(''), 'A null was read as the empty identifier.');
    }

    /**
     * It reaches every depth, and a nested list keeps its index in the path.
     *
     * @group sprint-seam
     */
    public function testItReachesEveryDepth(): void
    {
        $result = new ToolResult(false, json_encode([
            'menus' => [
                ['id' => 3, 'items' => [['id' => 41, 'children' => [['id' => 99]]]]],
            ],
        ]));

        self::assertSame(['menus[0].items[0].children[0].id'], $result->mentions(99));
        self::assertSame(['menus[0].items[0].id'], $result->mentions(41));
        self::assertSame([], $result->mentions(9), 'A prefix of a deep value was reported.');
    }

    /**
     * A TOP-LEVEL LIST keeps its index, so the walk does not depend on the answer being an
     * object. A scalar at the root is not reachable at all - data() refuses a result that is
     * not an array - which is why no path is ever empty.
     *
     * @group sprint-seam
     */
    public function testATopLevelListKeepsItsIndex(): void
    {
        $result = new ToolResult(false, '[7, 70, "7"]');

        self::assertSame(['[0]', '[2]'], $result->mentions(7));
        self::assertSame(['[1]'], $result->mentions(70));
    }
}
