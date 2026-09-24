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
     * Every `description` string a tool serves, run together: its own, and every one inside
     * its outputSchema.
     *
     * WHY THE SCHEMA COUNTS AS PROSE (1.1.1). The four piloted read tools moved their field
     * list out of the 1,000-character description and into `outputSchema`, a description per
     * field - which is the point of the pilot (D19: the description budget is the scarce
     * thing). A test that looked only at `description` would then read "the sentence is gone"
     * where a client reads "the sentence is over there", and the guard would be pushing the
     * information out of the server rather than holding it in.
     */
    private static function servedProse(array $tool): string
    {
        $prose = (string) $tool['description'];

        $walk = function ($node) use (&$walk, &$prose) {
            if (!is_array($node)) { return; }
            if (isset($node['description']) && is_string($node['description'])) {
                $prose .= ' ' . $node['description'];
            }
            foreach ($node as $child) { $walk($child); }
        };

        $walk($tool['outputSchema'] ?? null);

        return $prose;
    }

    /**
     * G4. Every built-in tool states what it returns - in its description, or in an
     * outputSchema that describes every field it returns.
     *
     * TWO WAYS TO SATISFY IT, AND THE SECOND IS STRICTER. A tool without an outputSchema must
     * still carry the word in its description, exactly as before. A tool WITH one is exempt
     * from that sentence and owes something harder instead: a `description` on every property
     * of the schema, so moving the field list out of the description cannot quietly lose it.
     *
     * @group sprint-14d
     */
    public function testEveryToolDescriptionSaysWhatItReturns(): void
    {
        $missing    = [];
        $undescribed = [];

        foreach (WireSerializationTest::catalog() as $name => $tool) {
            if (isset($tool['outputSchema']['properties'])) {
                foreach ($tool['outputSchema']['properties'] as $field => $node) {
                    if (!isset($node['description']) || trim((string) $node['description']) === '') {
                        $undescribed[] = $name . '.' . $field;
                    }
                }
                continue;
            }

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
        self::assertSame(
            [],
            $undescribed,
            'These outputSchema fields carry no description. A field list moved out of a tool'
            . ' description has to arrive somewhere: ' . implode(', ', $undescribed)
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
     * Round 4. The five sentences two cold clients read at 84a8e4b and acted on, each
     * replaced by the measured one. Held here, in the unit tier, because each is a claim
     * about behaviour that did not change and must not drift again:
     *
     *   - every tool that returns `link` says it is the plain ?p=ID form while the post is
     *     a draft, pending, scheduled or trashed (measured on both sites, pretty structure,
     *     slug present);
     *   - `create-term` says tags are stripped from a name, as `create-post` does of a title
     *     (measured: `Arts & Crafts <b>` stores `Arts & Crafts`; a menu item label is NOT
     *     stripped, so the menu tools say nothing of the kind);
     *   - `list-terms` says which posts `count` counts, and that it carries no date;
     *   - no description claims every list tool gives dates - `list-terms` gives none.
     *
     * @group sprint-14d
     */
    public function testTheMeasuredSentencesAreTheServedOnes(): void
    {
        $catalog = WireSerializationTest::catalog();

        // ACROSS THE WHOLE SERVED PROSE, not the description alone: get-post's `link`
        // sentence now lives in its outputSchema, where the field it describes is. See
        // servedProse().
        foreach (['list-posts', 'get-post', 'create-post', 'update-post'] as $name) {
            $description = self::servedProse($catalog[$name]);

            self::assertStringContainsString('?p=ID', $description, "{$name} does not say what link is on an unpublished post.");
            foreach (['draft', 'pending', 'future', 'trash'] as $status) {
                self::assertStringContainsString($status, $description, "{$name} does not name {$status} among the statuses link is ?p=ID for.");
            }
        }

        $term = (string) $catalog['create-term']['description'];
        self::assertStringContainsString('Tags are stripped from the name', $term, 'create-term still says a name comes back as typed with nothing stripped.');

        $terms = (string) $catalog['list-terms']['description'];
        self::assertStringContainsString('PUBLISHED posts', $terms, 'list-terms does not say which posts count counts.');
        self::assertStringContainsString('no item carries a date', strtolower($terms), 'list-terms does not say it returns no date.');

        foreach ($catalog as $name => $tool) {
            self::assertStringNotContainsString(
                'as every list tool gives dates',
                (string) $tool['description'],
                "{$name} claims every list tool gives dates; list-terms gives none."
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
