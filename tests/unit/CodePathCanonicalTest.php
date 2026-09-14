<?php
/**
 * One file inside the theme has ONE spelling by the time anything acts on it.
 *
 * THE BYPASS THIS CLOSES, measured by the sprint-8 review on jaygroup's live client
 * theme. `wpmcp_code_denied()` matches a directory rule by prefix, so `inc/` blocks
 * anything beginning `inc/`. `wpmcp_code_resolve()` handed it the caller's own string
 * after checking only for `..` and an absolute path - and `./inc/ajax.php` does not begin
 * with `inc/`. The reviewer ran it against the real theme: `inc/ajax.php` refused,
 * `./inc/ajax.php` ALLOWED. Every code tool goes through that function, so all six
 * inherited it, and README's "is never read or written" was a false claim.
 *
 * THE SECOND DEFECT IS THE SAME DEFECT. From sprint 8 that spelling is also the `path`
 * column of the version table, the key `code-history` looks up, and the group the
 * twenty-version cap counts within. `./style.css` and `style.css` were two histories of
 * one file with two caps, and `code-history style.css` after a `code-write ./style.css`
 * returned nothing at all.
 *
 * A UNIT TEST AND NOT AN INTEGRATION ONE, because the question is entirely about
 * `realpath()`, `is_link()` and string handling over a directory tree - none of which
 * needs WordPress, a database or HTTP. The tree is built in a temp directory per test and
 * removed afterwards, so this runs in CI on every push, which a live-site probe cannot.
 *
 * @group sprint-8
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class CodePathCanonicalTest extends TestCase
{
    private string $theme = '';

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        WordPressRuntime::install();

        $this->theme = self::makeTheme();

        WordPressRuntime::setTheme($this->theme, 'wpmcp-test-theme');
        // The shipped default, spelled out: this test is about the directory rules, and
        // taking them from wpmcp_code_denylist()'s fallback would make it pass if the
        // fallback ever lost `inc/`.
        WordPressRuntime::setOption(
            'wpmcp_code_denylist',
            ['functions.php', 'index.php', 'inc/', 'includes/', 'lib/']
        );
    }

    protected function tearDown(): void
    {
        if ($this->theme !== '' && is_dir($this->theme)) {
            self::removeTree($this->theme);
        }

        parent::tearDown();
    }

    /**
     * THE BYPASS. Every spelling of a denied file is denied, whether it exists yet or
     * not - `mustExist` false is the `code-write` / `code-restore` path and took the same
     * branch, so an agent could CREATE files in `inc/` as well as read them.
     *
     * @dataProvider deniedSpellings
     * @group sprint-8
     */
    public function testEverySpellingOfADeniedPathIsDenied(string $spelling): void
    {
        foreach ([true, false] as $mustExist) {
            $result = wpmcp_code_target(['path' => $spelling], $mustExist);

            self::assertInstanceOf(
                \WP_Error::class,
                $result,
                "'{$spelling}' was allowed with mustExist=" . var_export($mustExist, true)
                . '. The denylist blocks the directory `inc/`, and that has to hold for'
                . ' every way of writing the same file.'
            );
            self::assertSame(
                'wpmcp_denied',
                $result->get_error_code(),
                "'{$spelling}' was refused, but not by the denylist - so this test would"
                . ' pass with the denylist removed. Code: ' . $result->get_error_code()
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function deniedSpellings(): array
    {
        return [
            'plain'                => ['inc/x.php'],
            'leading dot slash'    => ['./inc/x.php'],
            'dot slash inside'     => ['inc/./x.php'],
            'doubled separator'    => ['inc//x.php'],
            'backslashes'          => ['.\\inc\\x.php'],
            'trailing dot segment' => ['./inc/./x.php'],
        ];
    }

    /**
     * The control: the SAME tree, the same denylist, a file that is not behind one. If
     * this were red the test above would be proving that everything is refused.
     *
     * @group sprint-8
     */
    public function testAPathThatIsNotDeniedIsStillAllowed(): void
    {
        $result = wpmcp_code_target(['path' => 'style.css'], true);

        self::assertIsArray($result, 'A plain allowed path was refused: '
            . ($result instanceof \WP_Error ? $result->get_error_message() : ''));
        self::assertSame('style.css', $result['rel']);
    }

    /**
     * ONE FILE, ONE `rel`. This is the assertion the version table needs: `rel` is its
     * primary lookup key and the group the retention cap counts within, so two spellings
     * resolving to two strings are two histories of one file.
     *
     * @dataProvider sameFileSpellings
     * @group sprint-8
     */
    public function testEverySpellingOfOneFileYieldsTheSameRelativePath(string $spelling): void
    {
        $result = wpmcp_code_target(['path' => $spelling], true);

        self::assertIsArray($result, "'{$spelling}' was refused: "
            . ($result instanceof \WP_Error ? $result->get_error_message() : ''));

        self::assertSame(
            'assets/app.css',
            $result['rel'],
            "'{$spelling}' resolves to the file assets/app.css but reports its path as"
            . " '{$result['rel']}'. That string is the version table's key, so this"
            . ' spelling would get a history and a retention cap of its own.'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function sameFileSpellings(): array
    {
        return [
            'plain'             => ['assets/app.css'],
            'leading dot slash' => ['./assets/app.css'],
            'dot slash inside'  => ['assets/./app.css'],
            'doubled separator' => ['assets//app.css'],
            'backslashes'       => ['assets\\app.css'],
            'leading slash is refused elsewhere, so not here' => ['./assets//./app.css'],
        ];
    }

    /**
     * The same canonical answer for a file that does NOT exist yet, which is the path
     * `code-write` takes when it creates one. Without this, a file's first version row
     * could be keyed differently from every later one.
     *
     * @group sprint-8
     */
    public function testANewFileIsCanonicalisedTheSameWay(): void
    {
        foreach (['assets/new.css', './assets/new.css', 'assets//new.css', 'assets\\new.css'] as $spelling) {
            $result = wpmcp_code_target(['path' => $spelling], false);

            self::assertIsArray($result, "'{$spelling}' was refused: "
                . ($result instanceof \WP_Error ? $result->get_error_message() : ''));
            self::assertSame('assets/new.css', $result['rel'], "for '{$spelling}'");
        }
    }

    /**
     * `..` is still refused outright, in any position, and it is refused as an ILLEGAL
     * PATH rather than being quietly normalised away. Normalising `a/../b` to `b` would
     * be a second path parser disagreeing with the first; refusing is one rule.
     *
     * @group sprint-8
     */
    public function testParentSegmentsAreStillRefused(): void
    {
        foreach (['assets/../inc/x.php', '../outside.css', './../outside.css', 'a/../../b.css'] as $spelling) {
            $result = wpmcp_code_target(['path' => $spelling], false);

            self::assertInstanceOf(\WP_Error::class, $result, "'{$spelling}' was allowed.");
            self::assertSame('wpmcp_path', $result->get_error_code(), "for '{$spelling}'");
        }
    }

    /**
     * The theme directory itself is not a file. Before the canonicalisation these
     * resolved to an empty `rel`, which as a version-table key is a row belonging to
     * nothing.
     *
     * @group sprint-8
     */
    public function testTheThemeRootItselfIsRefused(): void
    {
        foreach (['.', './'] as $spelling) {
            $result = wpmcp_code_target(['path' => $spelling], true);

            self::assertInstanceOf(\WP_Error::class, $result, "'{$spelling}' was allowed.");
            self::assertSame('wpmcp_path', $result->get_error_code(), "for '{$spelling}'");
        }
    }

    /**
     * A scratch theme: style.css, assets/app.css, and the denied inc/x.php.
     *
     * UNDER `.phpunit.cache/` AND NOT `sys_get_temp_dir()`, which is measured rather than
     * fussy: on the Windows workstation this suite is developed on, PHP's temp directory
     * is `C:\Windows\TEMP`, where `mkdir()` succeeds, `is_dir()` is true and `realpath()`
     * returns FALSE - so `wpmcp_code_root()` was empty and every assertion here failed
     * with "Active theme directory not found". The jail is built out of `realpath()`, so
     * a directory it cannot resolve is not a place to test it. `.phpunit.cache/` is
     * git-ignored, writable wherever the suite can run at all, and removed per test.
     */
    private static function makeTheme(): string
    {
        $base = WPMCP_PLUGIN_DIR . '/.phpunit.cache/wpmcp-jail-' . bin2hex(random_bytes(6));

        mkdir($base . '/inc', 0777, true);
        mkdir($base . '/assets', 0777, true);

        file_put_contents($base . '/style.css', "/* wpmcp-test */\n");
        file_put_contents($base . '/functions.php', "<?php\n");
        file_put_contents($base . '/inc/x.php', "<?php\n");
        file_put_contents($base . '/assets/app.css', "/* wpmcp-test */\n");

        return $base;
    }

    private static function removeTree(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir . '/' . $name;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
