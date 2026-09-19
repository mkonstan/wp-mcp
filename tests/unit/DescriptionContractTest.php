<?php
/**
 * Every tool says what it returns (sprint 14d, G4).
 *
 * A CHEAP STRUCTURAL CHECK, AND ONLY THAT. A description is the one part of this server a
 * model reads before it acts, and three cold clients in a row had to guess at a result
 * shape nobody had described - list-media, list-terms, list-comments, upload-media,
 * delete-media, the six code tools. The substance is the audit in the sprint-14d report,
 * tool by tool; what a test can hold in place cheaply is that no description is missing
 * the sentence at all, so a new tool cannot ship without one.
 *
 * AND THE PAGING-END SENTENCE on every paged tool: an agent that does not know has_more
 * goes false at page 100 does not know that "no more" can mean "no more that I will show
 * you" - so every tool that takes `page` must say where it stops.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressStubs;

final class DescriptionContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    /**
     * G4. Every description of every built-in tool states what the tool returns.
     *
     * @group sprint-14d
     */
    public function testEveryToolDescriptionSaysWhatItReturns(): void
    {
        $missing = [];

        foreach (WireSerializationTest::catalog() as $name => $tool) {
            if (preg_match('/\bReturns\b/', (string) $tool['description']) !== 1) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            'These tools do not say what they return, so a client has to call them to find'
            . ' out and then guess which fields mean what: ' . implode(', ', $missing)
        );
    }

    /**
     * G4. And every description still fits the 1,000 characters clients keep - a claim
     * added to say what a tool returns must not push another claim off the end.
     *
     * @group sprint-14d
     */
    public function testEveryDescriptionFitsTheClientLimit(): void
    {
        foreach (WireSerializationTest::catalog() as $name => $tool) {
            self::assertLessThanOrEqual(
                1000,
                mb_strlen((string) $tool['description']),
                "{$name}'s description is over 1,000 characters; clients cut it silently."
            );
        }
    }

    /**
     * G4. And the first 50 characters, which is all a client shows the model before the
     * tool is loaded, are a whole sentence naming what the tool does - verb first, no
     * preamble. Sprint 6 holds the same rule in ToolContractTest; it is repeated here
     * because this sprint lengthened almost every description, and the sprint-14d gate
     * must not depend on another group being run beside it.
     *
     * @group sprint-14d
     */
    public function testTheFirstFiftyCharactersSayWhatTheToolDoes(): void
    {
        foreach (WireSerializationTest::catalog() as $name => $tool) {
            $description = (string) $tool['description'];

            self::assertSame(1, preg_match('/\.(\s|$)/', $description, $m, PREG_OFFSET_CAPTURE), "{$name} has no sentence end.");
            self::assertLessThan(
                50,
                $m[0][1],
                "{$name}'s first sentence runs past the 50 characters a client shows before loading"
                . " the tool: '" . substr($description, 0, 50) . "'"
            );
            self::assertMatchesRegularExpression(
                '/^[A-Z][a-z]+ /',
                $description,
                "{$name} does not open with a verb."
            );
            self::assertDoesNotMatchRegularExpression(
                '/^(This|Allows|Use |A tool|The tool)/',
                $description,
                "{$name} opens with a preamble instead of what it does."
            );
        }
    }

    /**
     * G3's prose half: every tool that takes `page` says that has_more is false at page 100.
     *
     * @group sprint-14d
     */
    public function testEveryPagedToolSaysWherePagingStops(): void
    {
        $paged = [];

        foreach (WireSerializationTest::catalog() as $name => $tool) {
            if (!isset($tool['inputSchema']['properties']['page'])) { continue; }

            $paged[] = $name;

            self::assertStringContainsString(
                'has_more is false at page 100',
                (string) $tool['description'],
                "{$name} takes `page` and does not say where paging stops."
            );
            self::assertStringContainsString(
                'count, page, limit, has_more and items',
                (string) $tool['description'],
                "{$name} takes `page` and does not name the shared envelope."
            );
        }

        self::assertSame(
            ['list-posts', 'list-revisions', 'list-terms', 'list-media', 'list-comments', 'list-users'],
            $paged,
            'The set of paged tools changed; check the new one against the paging-end rule.'
        );
    }

    /**
     * The paging-end rule itself, as a function: has_more is false at the cap and only
     * there, and the envelope's keys are the five the descriptions name.
     *
     * @group sprint-14d
     */
    public function testTheEnvelopeStopsHasMoreAtTheCap(): void
    {
        self::assertSame(100, WPMCP_PAGE_CAP);

        self::assertTrue(wpmcp_page_envelope([1], 99, 1, true)['has_more']);
        self::assertFalse(wpmcp_page_envelope([1], 100, 1, true)['has_more']);
        self::assertFalse(wpmcp_page_envelope([1], 5, 1, false)['has_more']);
        self::assertSame(['count', 'page', 'limit', 'has_more', 'items'], array_keys(wpmcp_page_envelope([], 1, 20, false)));

        self::assertSame([20, 1], wpmcp_page_args([]));
        self::assertSame([100, 100], wpmcp_page_args(['limit' => 500, 'page' => 101]));
        self::assertSame([7, 1], wpmcp_page_args(['per_page' => 7]), 'per_page is not accepted as limit.');
        self::assertSame([3, 1], wpmcp_page_args(['limit' => 3, 'per_page' => 7]), 'limit does not win over per_page.');
    }
}
