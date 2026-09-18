<?php
/**
 * The build stamp: what a build says it is, and what it says when it is not a build
 * (sprint 14c, G1 / G2 / G4).
 *
 * WHY THIS EXISTS. Every dev zip cut from this repository reported `Version: 1.1.0`, so
 * the Plugins screen, serverInfo and site-info said the same string for Sprint 10's
 * build and Sprint 14b's. On 2026-09-16 an older zip was installed, everything looked
 * right, and about an hour went into diagnosing a "stale file" that was a stale ZIP.
 * Only the filename told the two apart, and a filename is gone the moment the plugin is
 * installed.
 *
 * THE RULE THIS TIER HOLDS. There is exactly one way to be a build - `git archive`
 * substituted build.txt's placeholders - and exactly one thing to say when that did not
 * happen: `source`. Never the version, never a file's mtime, never an empty field, and
 * never the unsubstituted placeholder itself. A checkout is the NORMAL case here (both
 * Local development sites and CI run the plugin straight out of a working tree), so the
 * absence of a stamp must be ordinary: nothing warns, nothing fails, nothing behaves
 * differently.
 *
 * WHAT THE INTEGRATION TIER ADDS. That the four surfaces all read this one function,
 * that a real `git archive` of this very commit produces this commit's short hash, and
 * that the parse below turns that archived file into it. Those need a site and a git.
 *
 * @group sprint-14c
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class BuildStampTest extends TestCase
{
    /**
     * A substituted stamp, in the shape `git archive` really writes one - measured
     * against this repository on 2026-09-18 (commit 04fd033, a throwaway probe).
     */
    private const SUBSTITUTED = "commit=04fd0331899d8f8d6e7e41bf90710e929d13cfd8\n"
        . "short=04fd033\n"
        . "date=2026-09-18T10:15:23-04:00\n";

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        WordPressRuntime::install();
    }

    /**
     * G1. The file as it sits in a checkout is not a build, and every field of the
     * parse says so rather than carrying the placeholder through.
     *
     * @group sprint-14c
     */
    public function testAnUnsubstitutedStampIsNotABuild(): void
    {
        // Built from parts, so this file does not itself contain a literal placeholder.
        // Nothing here is marked export-subst, so nothing would substitute it - the
        // point is that a reader grepping for a placeholder finds build.txt (the one
        // file git rewrites) and ARCHITECTURE.md (which explains it), and not a test.
        $dollar = '$';
        $text   = "# a comment\n"
            . 'commit=' . $dollar . 'Format:%H' . $dollar . "\n"
            . 'short=' . $dollar . 'Format:%h' . $dollar . "\n"
            . 'date=' . $dollar . 'Format:%cI' . $dollar . "\n";

        self::assertSame(
            ['commit' => '', 'short' => '', 'date' => ''],
            \wpmcp_build_stamp_parse($text),
            'An unsubstituted placeholder was read as a value. A checkout would then'
            . ' report a "build" that is a literal git format string.'
        );
    }

    /**
     * G2. A substituted stamp is read, field by field.
     *
     * @group sprint-14c
     */
    public function testASubstitutedStampIsRead(): void
    {
        self::assertSame(
            [
                'commit' => '04fd0331899d8f8d6e7e41bf90710e929d13cfd8',
                'short'  => '04fd033',
                'date'   => '2026-09-18T10:15:23-04:00',
            ],
            \wpmcp_build_stamp_parse(self::SUBSTITUTED)
        );
    }

    /**
     * Comments, blank lines, unknown keys, CRLF and surrounding space are all handled,
     * because the file travels through a zip and through whatever unpacked it.
     *
     * @group sprint-14c
     */
    public function testTheParseSurvivesWhatAZipDoesToATextFile(): void
    {
        $text = "# WP MCP\r\n\r\n  short = 04fd033  \r\nnot-a-key=whatever\r\nno-equals-sign\r\n";

        self::assertSame(
            ['commit' => '', 'short' => '04fd033', 'date' => ''],
            \wpmcp_build_stamp_parse($text)
        );
    }

    /**
     * G1. Nothing that is not a build id is reported as one - the placeholder, a
     * sentence, a path, something over-long, nothing at all.
     *
     * @group sprint-14c
     */
    public function testAValueThatCannotBeABuildIdIsDiscarded(): void
    {
        $dollar  = '$';
        $refused = [
            'the placeholder'   => $dollar . 'Format:%h' . $dollar,
            'a sentence'        => 'built by hand',
            'a path'            => '../../etc/passwd',
            'markup'            => '<b>1.1.0</b>',
            'over forty chars'  => str_repeat('a', 41),
            'empty'             => '',
            'leading dash'      => '-deadbee',
            // The unknown word matches the pattern perfectly well, so it has to be
            // refused by name. Otherwise a hand-edited `short=source`, or a filter
            // returning it, renders "build `source` - the commit this zip was built
            // from": a sentence about a build that does not exist.
            'the unknown word'  => \WPMCP_BUILD_UNKNOWN,
        ];

        foreach ($refused as $why => $value) {
            self::assertFalse(
                \wpmcp_build_id_valid($value),
                "wpmcp_build_id_valid() accepted {$why}, so it could reach the admin page"
                . ' and the wire as a build id.'
            );
            self::assertSame(
                '',
                \wpmcp_build_stamp_parse('short=' . $value . "\n")['short'],
                "A build.txt whose short value is {$why} was read as a build."
            );
        }

        foreach (['04fd033', '04fd0331899d8f8d6e7e41bf90710e929d13cfd8', 'r2026.09.18+4'] as $ok) {
            self::assertTrue(\wpmcp_build_id_valid($ok), "A real build id was refused: {$ok}");
        }
    }

    /**
     * The placeholder guard is NOT redundant with the shape check, and this is the case
     * that shows it: the parse is a fold over lines and keys are last-wins, while the
     * shape check runs once, on the last value.
     *
     * Round 1 reported this guard as unobservable and said so in the code, in the report
     * and in the KB. All three were wrong, and a review found the case. What the guard
     * buys is the first row below - a stamped build whose file has had a placeholder line
     * appended still reports the build it was cut from. What it costs is the second: a
     * checkout with a valid line ABOVE the placeholder claims that build. Both take a
     * hand edit; `git archive` substitutes in place and never duplicates a key.
     *
     * @group sprint-14c
     */
    public function testTheGuardDecidesWhichOfTwoValuesForOneKeyWins(): void
    {
        $placeholder = '$' . 'Format:%h' . '$';

        self::assertSame(
            '04fd033',
            \wpmcp_build_stamp_parse("short=04fd033\nshort={$placeholder}\n")['short'],
            'A placeholder line appended below a real one erased the build. Without the'
            . ' guard the placeholder wins the fold and the shape check then empties it,'
            . ' so the zip falls back to reporting `source`.'
        );

        self::assertSame(
            '04fd033',
            \wpmcp_build_stamp_parse("short={$placeholder}\nshort=04fd033\n")['short'],
            'Last-wins for two real values is unaffected by the guard.'
        );

        self::assertSame(
            'aaaaaaa',
            \wpmcp_build_stamp_parse("short=04fd033\nshort=aaaaaaa\n")['short'],
            'Two valid values for one key: the last one wins, as every other key does.'
        );
    }

    /**
     * A date that is not an ISO 8601 instant is not a build date. The admin page prints
     * it beside the build, so a half-substituted or hand-edited line must vanish rather
     * than be shown.
     *
     * @group sprint-14c
     */
    public function testOnlyAnIsoInstantIsABuildDate(): void
    {
        foreach (['2026-09-18T10:15:23-04:00', '2026-09-18T14:15:23Z', '2026-09-18T14:15:23+0000'] as $ok) {
            self::assertTrue(\wpmcp_build_date_valid($ok), "A real committer date was refused: {$ok}");
        }

        foreach (['2026-09-18', 'yesterday', '', '2026-09-18 10:15:23'] as $bad) {
            self::assertFalse(\wpmcp_build_date_valid($bad), "A non-instant was accepted: {$bad}");
            self::assertSame('', \wpmcp_build_stamp_parse('date=' . $bad . "\n")['date']);
        }
    }

    /**
     * G1. With no stamp the label is the word, and the word is not a version number.
     *
     * @group sprint-14c
     */
    public function testWithNoStampTheLabelIsTheWordAndNotAVersion(): void
    {
        self::assertSame('source', \WPMCP_BUILD_UNKNOWN);

        // This checkout is the case: build.txt is here and unsubstituted.
        self::assertSame('', \wpmcp_build_id(), 'A git checkout reported a build id.');
        self::assertSame('source', \wpmcp_build_label());

        self::assertDoesNotMatchRegularExpression(
            '/^\d+\.\d+/',
            \wpmcp_build_label(),
            'The unknown-build word reads as a version number, which is the confusion'
            . ' this whole sprint exists to remove.'
        );
        self::assertNotSame(\WPMCP_VER, \wpmcp_build_label());
    }

    /**
     * The filter is SHAPE-CONSTRAINED, not narrow-only, and the round-1 test name said
     * otherwise. It is a seam for a packager that stamps some other way, and PHP on the
     * site can use it to make a checkout claim any build it likes - which is what the
     * gate's own G2 does. What the shape check guarantees is only that whatever appears
     * on the admin page and on the wire LOOKS like a build id: never a sentence, a
     * version, the unknown word, or an empty string.
     *
     * @group sprint-14c
     */
    public function testTheFilterMaySetAnyBuildIdThatLooksLikeOne(): void
    {
        WordPressRuntime::addFilter('wpmcp_build_id', static fn () => 'deadbee');
        self::assertSame('deadbee', \wpmcp_build_id());
        self::assertSame('deadbee', \wpmcp_build_label());

        WordPressRuntime::addFilter('wpmcp_build_id', static fn () => \WPMCP_BUILD_UNKNOWN);
        self::assertSame(
            'source',
            \wpmcp_build_label(),
            'The word survives as if it were a build id, so the settings page would call'
            . ' it "the commit this zip was built from".'
        );
        self::assertSame('', \wpmcp_build_id());

        WordPressRuntime::addFilter('wpmcp_build_id', static fn () => 'not a build id');
        self::assertSame('', \wpmcp_build_id());
        self::assertSame('source', \wpmcp_build_label(), 'A refused filter value left an empty field.');

        WordPressRuntime::addFilter('wpmcp_build_id', static fn () => null);
        self::assertSame('source', \wpmcp_build_label());
    }

    /**
     * The date belongs to the build in the FILE, so a filtered id is shown without one.
     *
     * A PURE FUNCTION, BECAUSE THE INTERESTING CASE CANNOT EXIST ON A CHECKOUT: build.txt
     * here carries no date at all, so asserting `wpmcp_build_date() === ''` on this
     * machine passes whatever the rule is - the first version of this test did exactly
     * that and a mutation of the rule stayed green. This one hands the decision a stamp
     * that has a date.
     *
     * @group sprint-14c
     */
    public function testAFilteredBuildIdIsShownWithoutTheFileSDate(): void
    {
        $stamp = \wpmcp_build_stamp_parse(self::SUBSTITUTED);

        self::assertSame(
            '2026-09-18T10:15:23-04:00',
            \wpmcp_build_date_of($stamp, '04fd033'),
            "The build in the file is the one being reported, so it is shown with the"
            . ' file\'s own date.'
        );
        self::assertSame(
            '',
            \wpmcp_build_date_of($stamp, 'deadbee'),
            'A build id that did not come from this file was shown beside this file\'s'
            . " date - two builds on one line, which is the class of lie this sprint"
            . ' exists to remove.'
        );
        self::assertSame(
            '',
            \wpmcp_build_date_of($stamp, ''),
            'A copy with no build at all was given a date.'
        );
        self::assertSame(
            '',
            \wpmcp_build_date_of(['commit' => '', 'short' => 'abc1234', 'date' => ''], 'abc1234'),
            'A stamp with no date produced one.'
        );
    }

    /**
     * G4. The version stays a clean semantic version on every build: the stamp sits
     * BESIDE it and never inside it.
     *
     * @group sprint-14c
     */
    public function testTheVersionNeverCarriesABuildStamp(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', \WPMCP_VER);

        $header = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/wp-mcp.php');
        self::assertMatchesRegularExpression(
            '/^\s*\*\s*Version:\s*\d+\.\d+\.\d+\s*$/m',
            $header,
            "The plugin header's Version: line is no longer a bare semantic version."
            . ' WordPress shows that line on the Plugins screen and the release tooling'
            . ' reads it; the build belongs beside it, not inside it.'
        );
    }

    /**
     * G3's precondition, asserted where a reader will look: the file exists, carries all
     * three placeholders, and .gitattributes marks it - and only it - export-subst.
     *
     * A ZIP RECIPE THAT FORGETS THE FILE SHIPS A PLUGIN THAT SAYS `source`, which is a
     * silent regression to exactly the state that cost the hour. So the two recipes are
     * asserted here too: they are the only things that put the file in a zip.
     *
     * @group sprint-14c
     */
    public function testTheStampFileAndBothZipRecipesAreWired(): void
    {
        $dollar = '$';
        $stamp  = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/build.txt');

        foreach (['%H' => 'commit', '%h' => 'short', '%cI' => 'date'] as $placeholder => $key) {
            self::assertStringContainsString(
                $key . '=' . $dollar . 'Format:' . $placeholder . $dollar,
                $stamp,
                "build.txt no longer carries the {$key} placeholder, so a built zip cannot"
                . ' carry that value.'
            );
        }

        // The same three files that put the stamp in a zip, asserted without printing
        // any of them: a failed assertStringContainsString prints its haystack, and two
        // of these are workflow files nobody wants in a test log.

        $attributes = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/.gitattributes');
        self::assertMatchesRegularExpression(
            '#^/build\.txt\s+export-subst\b#m',
            $attributes,
            'build.txt is not marked export-subst, so `git archive` substitutes nothing'
            . ' and every zip reports `source`.'
        );

        // THE RECIPE'S FILE LIST, not the word anywhere in the document. RELEASE.md
        // explains the stamp at length, so "does this file mention build.txt" is green
        // even when the one line that puts it in a zip has lost it - which is exactly
        // the regression worth catching, because the result is a zip that says `source`
        // and looks like a checkout.
        $release = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/docs/RELEASE.md');

        self::assertSame(
            1,
            preg_match('/```bash\s*\ngit archive(.*?)```/s', $release, $recipe),
            'docs/RELEASE.md no longer carries a `git archive` recipe at all.'
        );
        self::assertTrue(
            str_contains($recipe[1], 'build.txt'),
            "The dev-zip recipe in docs/RELEASE.md does not name build.txt in its file"
            . ' list, so the zip a human builds from it reports `source`.'
        );

        $workflow = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/.github/workflows/release.yml');

        self::assertTrue(
            str_contains($workflow, 'git archive --format=tar HEAD build.txt'),
            'The release workflow no longer takes build.txt from `git archive`, so the'
            . ' published zip carries the unsubstituted placeholders - `cp` cannot'
            . ' substitute them.'
        );
        self::assertTrue(
            str_contains($workflow, 'tar -xf - -C dist/stamp'),
            'The stamping step no longer passes `-f -`. A bare `tar -x` reads stdin only'
            . ' because GNU tar on Debian and Ubuntu is built that way; on another runner'
            . ' image, or with bsdtar, it reads a tape device and the release dies there.'
        );
    }

    /**
     * G5. The two sentences the cold-client test found on 2026-09-18 are gone from
     * `update-post`, and the claim that replaced each is present.
     *
     * A PROSE ASSERTION, AND IT EARNS ITS PLACE. Both sentences were false in a way no
     * behavioural test could see: the tool did the right thing and described it wrongly,
     * and a reader following the second one literally restores the wrong revision. The
     * behaviour they describe is measured in the integration tier; what is asserted here
     * is that the description cannot drift back.
     *
     * @group sprint-14c
     */
    public function testUpdatePostNoLongerMakesTheTwoFalseClaims(): void
    {
        $source = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/tools.php');

        // assertFalse(str_contains(...)) AND NOT assertStringNotContainsString: the
        // haystack is the whole of tools.php, and PHPUnit prints a failed haystack. A
        // 288-kilobyte failure message is a failure nobody reads.
        self::assertFalse(
            str_contains($source, 'Only the fields you send change, and each REPLACES what was there.'),
            'The unqualified "only the fields you send change" is back in tools.php. A title-only'
            . ' update on a draft nobody dated moves `date` too - measured on both Local'
            . ' sites, 2026-09-18.'
        );
        self::assertFalse(
            str_contains($source, 'The current title, content and excerpt are saved as a revision first, for restore-revision.'),
            'The reversed revision sentence is back in tools.php. The revision an edit CREATES holds'
            . ' the new text; the pre-edit text is the one below it, and a reader who'
            . ' follows this sentence literally restores what they just wrote.'
        );
    }
}
