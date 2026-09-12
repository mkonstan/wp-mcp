<?php
/**
 * The revision set, and the two questions asked of it.
 *
 * WHAT WOULD HAVE GONE WRONG WITHOUT THESE. `latest()` is `cases()[0]`, which is correct
 * only while the cases are declared newest first - a maintainer adding `2026-07-28` at the
 * BOTTOM, where new things normally go, would silently make this server negotiate down to
 * an old revision and nothing else in the codebase would notice. So the order is asserted
 * as a property of the list rather than as one hardcoded string, and `latest()` is checked
 * against it: the test is about the invariant, not about today's answer.
 *
 * `tryFromString()` takes `?string` because both its callers hand it "the thing that might
 * not be there" - an absent `params.protocolVersion`, an absent header. If it ever starts
 * throwing on null, every `initialize` without a protocolVersion becomes a 500.
 *
 * This also proves the `WpMcp\` autoloader in wp-mcp.php actually finds src/: the enum is
 * never required by name anywhere, so if the loader is wrong this file fatals.
 *
 * @group sprint-4
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\ProtocolVersion;
use WpMcp\Tests\Support\WordPressStubs;

final class ProtocolVersionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Nothing requires src/ProtocolVersion.php by path. Loading the plugin registers
        // the spl_autoload_register that has to find it.
        WordPressStubs::loadPlugin();
    }

    /**
     * The set is what the build plan settled on, in the order latest() depends on.
     *
     * @group sprint-4
     */
    public function testTheSupportedRevisionsAreDeclaredNewestFirst(): void
    {
        $values = array_map(
            static fn (ProtocolVersion $v): string => $v->value,
            ProtocolVersion::cases()
        );

        self::assertSame(
            ['2025-11-25', '2025-06-18', '2025-03-26'],
            $values,
            'The revision set or its order changed. latest() is cases()[0], so a new'
            . ' revision added anywhere but the TOP makes this server negotiate down.'
        );

        $sorted = $values;
        rsort($sorted, SORT_STRING);

        self::assertSame(
            $sorted,
            $values,
            'The cases are no longer in descending date order, so cases()[0] is not the'
            . ' newest revision and latest() is wrong.'
        );
    }

    /**
     * @group sprint-4
     */
    public function testLatestIsTheFirstCase(): void
    {
        self::assertSame(
            ProtocolVersion::cases()[0],
            ProtocolVersion::latest(),
            'latest() stopped being the first declared case.'
        );
        self::assertSame(
            '2025-11-25',
            ProtocolVersion::latest()->value,
            'The revision this server negotiates toward is 2025-11-25 (build plan,'
            . ' decisions log 2026-09-12). Changing it is a decision, not a refactor.'
        );
    }

    /**
     * @group sprint-4
     */
    public function testTryFromStringResolvesEverySupportedRevision(): void
    {
        foreach (ProtocolVersion::cases() as $case) {
            self::assertSame(
                $case,
                ProtocolVersion::tryFromString($case->value),
                'tryFromString() did not resolve its own case ' . $case->value
            );
            self::assertTrue(
                ProtocolVersion::isSupported($case->value),
                'isSupported() said no to a declared case: ' . $case->value
            );
        }
    }

    /**
     * null, '' and a version from the future are all "no case", and none of them throws.
     *
     * null is the absent-input path and has to be distinguishable from a bad one by the
     * caller, which is why the return is nullable rather than a case-or-default.
     *
     * @group sprint-4
     */
    public function testNullAndUnknownStringsResolveToNothingWithoutThrowing(): void
    {
        self::assertNull(ProtocolVersion::tryFromString(null), 'null must not throw.');
        self::assertNull(ProtocolVersion::tryFromString(''), "'' is not a revision.");
        self::assertNull(ProtocolVersion::tryFromString('2099-01-01'));
        self::assertNull(ProtocolVersion::tryFromString('2026-07-28'), 'The modern era is deferred.');
        self::assertNull(ProtocolVersion::tryFromString(' 2025-11-25'), 'No trimming, no guessing.');

        self::assertFalse(ProtocolVersion::isSupported('2099-01-01'));
        self::assertFalse(ProtocolVersion::isSupported(''));
        self::assertFalse(ProtocolVersion::isSupported('V2025_11_25'), 'The case NAME is not the wire value.');
    }
}
