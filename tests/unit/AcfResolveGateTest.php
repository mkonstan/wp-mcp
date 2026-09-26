<?php
/**
 * THE ACF MODULE'S CAPABILITY BOUNDARY, HELD BY A TEST - because the sprint's security property was living in
 * a review file and nothing would have gone red when a future author loosened it.
 *
 * WHAT THE BOUNDARY IS FOR. `get-acf-values` reports a Flexible Content layout's `label` by calling
 * ACF's own `get_layout_title()`, which runs the documented
 * `acf/fields/flexible_content/layout_title` filter family over the RAW row - unformatted and NOT
 * permission-reduced, because that is the row ACF's own renderer passes and a reduced one would
 * answer differently from wp-admin. MEASURED (review 81, pressure point 1): a `user` sub-field
 * pointing at an administrator came back as the bare id `2` in `value` for a low-privilege caller,
 * while a site filter calling `get_sub_field('author')['user_email']` put that administrator's
 * e-mail into `label` for the SAME caller. So the label can carry what the reduction withholds.
 *
 * AND THE THING THAT MAKES THAT SAFE IS NOT THE REDUCTION - IT IS THIS GATE. `wpmcp_acf_resolve()`
 * refuses anyone without EDIT rights on the object before a single row is built, so every reader who
 * reaches the filter can open the wp-admin screen where ACF renders the identical string from the
 * identical filter. That is D29's parity requirement, and it is the whole argument.
 *
 * SO THE FAILURE MODE THIS FILE EXISTS FOR IS A GOOD-FAITH LOOSENING. Turning `edit_post` into
 * `read_post` is a change somebody will one day have an excellent reason to make - a read tool
 * reading published content - and it would hand a site filter's output to readers wp-admin never
 * shows it to. That change now turns this file red and the message says why.
 *
 * TWO HALVES, AND NEITHER IS SUFFICIENT ALONE. The EXECUTED half drives the real function and proves
 * a caller without the capability is refused on all four object types - but it cannot tell
 * `edit_post` from some other capability nobody granted either. The DECLARED half asserts the exact
 * SET of capabilities the function names, so a swap for a weaker one is red even though the refusal
 * it produces looks the same.
 *
 * WHY THE ALLOW PATH IS NOT HERE. Past its checks the function calls `wpmcp_acf_object_id()`, which
 * calls ACF's `acf_get_valid_post_id()` - and there is no ACF in this process, which is exactly why
 * this file may live in the gate group at all. Defining that symbol here would make the module's own
 * face-missing tests order-dependent, which is the trap review 81 B2 was about. The allow path is
 * covered over HTTP against real ACF in tests/integration/AcfValueReadTest.php, under `acf-data`.
 *
 * @group sprint-core-fix
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\PhpSymbols;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class AcfResolveGateTest extends TestCase
{
    /**
     * THE GATE, AS THE CONTRACT. One row per object type: the capability
     * `wpmcp_acf_resolve()` must demand, and the wp-admin screen it is the capability for.
     *
     * Every one of these is "the capability that opens the screen ACF renders these fields on",
     * which is the module's stated rule and the reason the set is not wider or looser.
     */
    private const GATES = [
        'options' => 'manage_options',
        'post'    => 'edit_post',
        'term'    => 'edit_term',
        'user'    => 'edit_user',
    ];

    /** The post path asks this FIRST, and it is a not-found gate rather than the edit gate. */
    private const POST_VISIBILITY_GATE = 'read_post';

    private const POST_ID = 4201;
    private const TERM_ID = 4202;
    private const USER_ID = 4203;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    protected function setUp(): void
    {
        parent::setUp();

        WordPressRuntime::install();
        WordPressRuntime::logInAs(77, 'a-low-privilege-caller');
        WordPressRuntime::addPost(self::POST_ID);
        WordPressRuntime::addTerm(self::TERM_ID);
        WordPressRuntime::addUser(self::USER_ID, 'the-target-user');
    }

    /**
     * EXECUTED: a caller with no capabilities is refused on ALL FOUR object types.
     *
     * The objects all EXIST - a post of a registered, viewable type, a term, a user - so nothing
     * here is refused for being absent. What refuses is the capability check, which is the point.
     *
     * @group sprint-core-fix
     */
    public function testACallerWithoutTheCapabilityIsRefusedOnEveryObjectType(): void
    {
        foreach ([
            'options' => [0],
            'post'    => [self::POST_ID],
            'term'    => [self::TERM_ID],
            'user'    => [self::USER_ID],
        ] as $type => $args) {
            $answer = \wpmcp_acf_resolve($type, $args[0]);

            self::assertInstanceOf(
                \WP_Error::class,
                $answer,
                "wpmcp_acf_resolve('{$type}', …) let a caller with NO capabilities through. That"
                . ' caller can then read a layout label built by a site filter out of the raw,'
                . ' un-reduced row - a string wp-admin would never show them, because they cannot'
                . ' open the screen it comes from.'
            );
            self::assertContains(
                $answer->get_error_code(),
                ['wpmcp_forbidden', 'wpmcp_not_found'],
                "The refusal for '{$type}' is not one of the module's two refusals, so something"
                . ' other than the gate produced it: ' . $answer->get_error_code() . ' - '
                . $answer->get_error_message()
            );
        }
    }

    /**
     * EXECUTED, AND IT IS THE EXACT LOOSENING FABLE NAMED: `read_post` is not enough for a post.
     *
     * The post path has TWO checks and they do different jobs. `read_post` decides whether the
     * caller may know the post EXISTS - its refusal is the not-found answer, byte for byte with
     * get-post, so a caller learns nothing. `edit_post` decides whether they may read its ACF
     * fields, and it is the one the layout-label argument rests on. A caller who holds `read_post`
     * and not `edit_post` is every subscriber on every site with published content.
     *
     * @group sprint-core-fix
     */
    public function testReadPostIsNotEnoughToReadAPostsAcfFields(): void
    {
        WordPressRuntime::allowCap(self::POST_VISIBILITY_GATE . ':' . self::POST_ID);

        $answer = \wpmcp_acf_resolve('post', self::POST_ID);

        self::assertInstanceOf(
            \WP_Error::class,
            $answer,
            'A caller who may READ a post may now read its ACF fields. That is the loosening this'
            . " file exists for: the layout `label` comes from a site filter over the raw row, and"
            . ' a reader who cannot open the post editor is a reader wp-admin never shows that'
            . ' string to. If this was deliberate, the disclosure argument in'
            . ' wpmcp_acf_layout_label() has to be rewritten first.'
        );
        self::assertSame(
            'wpmcp_forbidden',
            $answer->get_error_code(),
            'The refusal is the NOT-FOUND one, so this caller was stopped by the read_post gate'
            . ' rather than by the edit_post gate and the assertion above proves nothing about'
            . ' edit_post. Got: ' . $answer->get_error_message()
        );
        self::assertStringContainsString(
            'edit',
            $answer->get_error_message(),
            'The refusal does not tell the caller that editing permission is what is missing, which'
            . ' is the only actionable thing it can say by this point.'
        );
    }

    /**
     * EXECUTED: the gate is not satisfied by ANY capability - only by the one it names.
     *
     * Without this, granting nothing and granting something irrelevant are the same arrangement,
     * and a gate rewritten to `current_user_can('read')` would pass the two tests above.
     *
     * @group sprint-core-fix
     */
    public function testAnUnrelatedCapabilityDoesNotOpenAnyOfTheFourGates(): void
    {
        foreach (['read', 'edit_posts', 'upload_files', 'list_users'] as $irrelevant) {
            WordPressRuntime::install();
            WordPressRuntime::logInAs(77, 'a-low-privilege-caller');
            WordPressRuntime::addPost(self::POST_ID);
            WordPressRuntime::addTerm(self::TERM_ID);
            WordPressRuntime::addUser(self::USER_ID, 'the-target-user');
            WordPressRuntime::allowCap($irrelevant);

            foreach (['options' => 0, 'post' => self::POST_ID, 'term' => self::TERM_ID, 'user' => self::USER_ID] as $type => $id) {
                self::assertInstanceOf(
                    \WP_Error::class,
                    \wpmcp_acf_resolve($type, $id),
                    "Holding '{$irrelevant}' was enough to read '{$type}' ACF fields, so that gate"
                    . ' is asking for something broader than the capability that opens the screen'
                    . ' these fields are rendered on.'
                );
            }
        }
    }

    /**
     * DECLARED: the SET of capabilities `wpmcp_acf_resolve()` names is exactly the five it names
     * today, and no other.
     *
     * WHY A SET AND NOT FIVE PRESENCE CHECKS. A presence check catches a deletion; it does not catch
     * a SWAP, and a swap is the change that matters - `edit_post` becoming `read_post` leaves four
     * `current_user_can()` calls in place and every executed refusal above still passing, because
     * the caller who is refused holds neither. Asserting the whole set makes the swap red: the set
     * loses `edit_post`. It also makes an ADDED looser alternative red, because the set grows.
     *
     * READ OUT OF THE FUNCTION'S OWN BODY, not the file, so a capability named anywhere else in the
     * module is neither counted nor missed. Tokenised, so a capability named in a comment is not a
     * check and a check cannot hide in one.
     *
     * @group sprint-core-fix
     */
    public function testTheGateDemandsExactlyTheFiveCapabilitiesItDemandsToday(): void
    {
        $body = self::resolveBody();

        self::assertSame(
            ['edit_post', 'edit_term', 'edit_user', 'manage_options', 'read_post'],
            self::capabilitiesAskedIn($body),
            "The set of capabilities wpmcp_acf_resolve() demands has changed.\n\n"
            . "This is a SECURITY BOUNDARY, not a detail. The module reports a layout `label` by"
            . " calling ACF's get_layout_title(), which runs a site's own layout_title filter over"
            . " the RAW row - un-reduced, because a reduced row would disagree with wp-admin. What"
            . " keeps that safe is precisely that every caller who gets this far can open the"
            . " wp-admin screen the same filter renders into. MEASURED: a filter reading"
            . " get_sub_field('author')['user_email'] put an administrator's e-mail into `label` for"
            . " a subscriber, and only this gate stopped that subscriber reaching the call.\n\n"
            . 'If a gate was LOOSENED, the disclosure argument in wpmcp_acf_layout_label() is no'
            . ' longer true and has to be rewritten before this list is. If a gate was renamed or a'
            . ' new object type added, add its row to GATES and to this list, deliberately.'
        );

        // AND EACH GATE IS ON ITS OWN OBJECT TYPE'S BRANCH, so the set above cannot be satisfied by
        // five checks that all sit on one branch.
        foreach (self::GATES as $type => $capability) {
            self::assertStringContainsString(
                "'" . $capability . "'",
                $body,
                "The gate for object type '{$type}' no longer names {$capability}."
            );
        }
    }

    /**
     * And the module's own docblock still says what the bound IS, beside the code that depends on it.
     *
     * The prose is the only place a future author learns that loosening a gate is not a local
     * change. Round 2 shipped "it is not a route a CALLER can reach", which is true of the FILTER
     * and false of the VALUE (review 81, S7) - so the sentence that replaced it is held here.
     *
     * @group sprint-core-fix
     */
    public function testTheDisclosureArgumentNamesTheGateAsTheBound(): void
    {
        $source = RepoFile::read('modules/acf.php');

        self::assertStringNotContainsString(
            'It is not a route a CALLER can reach',
            $source,
            'The docblock claims a caller cannot reach the un-reduced value. That is true of the'
            . ' FILTER and false of the VALUE: a site filter can put a sub-value the reduction'
            . ' withheld into the label, and what bounds it is the resolve gate, not the reduction.'
        );
        self::assertStringContainsString(
            'WHAT BOUNDS THE VALUE IS THE RESOLVE GATE',
            $source,
            'The corrected sentence is gone, so the next author who loosens a capability check has'
            . ' nothing beside the code telling them what it holds up.'
        );

        foreach (self::GATES as $capability) {
            self::assertStringContainsString(
                $capability,
                $source,
                "The disclosure docblock no longer names {$capability} among the checks that bound"
                . ' it, so the argument and the code can drift.'
            );
        }
    }

    /**
     * Every capability name passed to `current_user_can()` in $body, sorted and unique.
     *
     * @return list<string>
     */
    private static function capabilitiesAskedIn(string $body): array
    {
        $found = [];

        // Tokenised for the CALL, then the literal read off the source - `current_user_can` takes
        // its capability as a single-quoted literal at every one of these call sites, and a gate
        // that stopped doing so would fall out of this list and fail the assertion, which is the
        // right answer: a capability computed at run time is not a gate a test can hold.
        // `<?php` prepended because the body is a FRAGMENT: without an opening tag token_get_all()
        // reads the whole thing as inline HTML and reports no symbols at all, which would have made
        // the assertion below fail for a reason that has nothing to do with the gate.
        foreach (PhpSymbols::scan("<?php\n" . $body) as $symbol) {
            if ($symbol['kind'] === 'call' && $symbol['lower'] === 'current_user_can') {
                $found[] = $symbol['line'];
            }
        }

        self::assertNotEmpty(
            $found,
            'No current_user_can() call was found in wpmcp_acf_resolve() at all, so this gate is'
            . ' not a gate any more.'
        );

        preg_match_all("/current_user_can\(\s*'([a-z_]+)'/", $body, $matches);

        self::assertCount(
            count($found),
            $matches[1],
            'A current_user_can() call in wpmcp_acf_resolve() does not take a literal capability'
            . ' name, so this test cannot see which capability it demands - and a gate whose'
            . ' capability is computed cannot be held. Found ' . count($found) . ' calls and '
            . count($matches[1]) . ' literals.'
        );

        $capabilities = array_values(array_unique($matches[1]));
        sort($capabilities);

        return $capabilities;
    }

    /** The body of `wpmcp_acf_resolve()`, from its opening brace to the line that closes it. */
    private static function resolveBody(): string
    {
        $source = RepoFile::read('modules/acf.php');
        $start  = strpos($source, 'function wpmcp_acf_resolve($type, $id) {');

        self::assertNotFalse($start, 'wpmcp_acf_resolve() is not there, or its signature changed.');

        $end = strpos($source, "\n}\n", $start);

        self::assertNotFalse($end, 'Could not find the end of wpmcp_acf_resolve().');

        return substr($source, $start, $end - $start);
    }
}
