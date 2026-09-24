<?php
/**
 * THE BARE-SITE RULE APPLIED TO THE PLUGIN'S OWN FILE LAYOUT: the core has to work with ZERO
 * modules loaded, and this is the gate that says so (analysis/53-open-decisions.md, D23,
 * condition 2).
 *
 * WHY A SUBPROCESS OVER A STAGED COPY, and not a flag. "The core behaves identically with no
 * modules" is a statement about LOADING, and the only honest way to stop a file being loaded
 * is for it not to be there. A constant or a filter that suppressed the modules would be a
 * production switch invented for a test, and it would prove the switch rather than the layout:
 * a core file that called a module's function directly would still pass, because the module
 * would still be in the process. So this test copies every core file - every `*.php` in the
 * plugin root plus `src/` - into a temporary directory, DOES NOT copy `modules/`, and loads
 * that copy in a fresh PHP process. A core that needs a module fatals there and says which
 * symbol it wanted.
 *
 * DERIVED, NOT LISTED. The file set is read off the filesystem rather than typed here, so a
 * core file added in a later sprint is staged without this test being remembered - and a
 * module is excluded by being in `modules/`, which is where wpmcp_module_manifest() says
 * modules are. The one thing asserted about the staging is the negative: that not one file the
 * manifest names reached the copy.
 *
 * WHAT IT ASSERTS, AND THE SECOND HALF IS THE POINT:
 *
 *   1. The bare core's tool list is EXACTLY the 24 names below. A tool that leaves the core
 *      without anybody deciding to move it is red here.
 *   2. Every module tool's name answers the registry lookup the DISPATCHER makes -
 *      `isset($tools[$name])` in endpoint.php, one line above the -32602 - identically to a
 *      name nobody ever registered: false. That is the same shape SqlSelectTest and
 *      PostMetaToolsTest prove against the live dispatcher for a switched-off tool, asserted
 *      here for a module that is not installed at all.
 *   3. The control, in-process, with the modules present: those same names ARE registered. A
 *      test that proves a tool is absent has to prove it was ever there, or a typo in the name
 *      passes it.
 *
 * AND IT USES PHP_BINARY, so it needs nothing on PATH. The unit tier's promise is that it runs
 * on a laptop with no git, no bash and no site; a PHP process is the one thing a PHP test can
 * always have.
 *
 * @group sprint-seam
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class BareCoreTest extends TestCase
{
    /**
     * The core's tool surface with no module loaded and every opt-in switch off, in the order
     * wpmcp_tools() assembles it.
     *
     * A DECISION, NOT A MEASUREMENT: a name entering or leaving this list is a change to what
     * the plugin is without its features, which is exactly the thing D23 asked for a test on.
     * The code tools, sql-select and the post-meta pair are absent because their switches are
     * off, which is the same "a disabled tool does not exist" rule the module tools obey here.
     */
    private const CORE_TOOLS = [
        'site-info', 'list-posts', 'get-post',
        'create-post', 'update-post', 'delete-post',
        'list-revisions', 'get-revision', 'restore-revision',
        'list-terms', 'create-term', 'delete-term',
        'list-media', 'get-media', 'upload-media', 'delete-media',
        'list-comments', 'moderate-comment', 'reply-comment',
        'list-users', 'get-user', 'get-option', 'list-plugins', 'list-themes',
    ];

    /** Every tool that arrives through the seam. Absent from a bare core, present in the control. */
    private const MODULE_TOOLS = [
        'list-menus', 'get-menu', 'add-menu-item', 'update-menu-item', 'remove-menu-item',
        'list-content-types',
    ];

    private string $staged = '';

    protected function tearDown(): void
    {
        if ($this->staged !== '') {
            self::rmrf($this->staged);
            $this->staged = '';
        }
    }

    /**
     * @group sprint-seam
     */
    public function testTheCoreAssemblesItsRegistryWithNoModuleOnDisk(): void
    {
        $probe = $this->probeBareCore();

        self::assertSame(
            self::CORE_TOOLS,
            $probe['names'],
            'The core tool list changed with no module loaded. Either a tool moved in or out of'
            . ' the core without that being decided, or a module is leaking into it.'
        );
    }

    /**
     * @group sprint-seam
     */
    public function testAModuleToolAnswersExactlyLikeANameNobodyRegistered(): void
    {
        $probe = $this->probeBareCore();

        self::assertFalse(
            $probe['absent_control'],
            'The control name is registered, so this test compares against nothing.'
        );

        foreach (self::MODULE_TOOLS as $name) {
            self::assertSame(
                $probe['absent_control'],
                $probe['registered'][$name],
                "With no module on disk, {$name} must answer the dispatcher's registry lookup"
                . ' exactly as a name nobody ever registered does - the same false, so tools/call'
                . ' answers the same -32602 "Unknown tool" and says nothing about this site.'
            );
        }
    }

    /**
     * The control. Same question, same process, modules present: every one of those names is
     * registered, so the assertions above are about the modules being gone and not about the
     * names being wrong.
     *
     * @group sprint-seam
     */
    public function testTheSameNamesAreRegisteredWhenTheModulesAreThere(): void
    {
        WordPressStubs::loadPlugin();
        WordPressRuntime::install();

        $tools = \wpmcp_tools();

        foreach (self::MODULE_TOOLS as $name) {
            self::assertArrayHasKey(
                $name,
                $tools,
                "{$name} is not registered with the modules loaded either, so the bare-core"
                . ' assertions prove nothing about the seam.'
            );
        }

        foreach (self::CORE_TOOLS as $name) {
            self::assertArrayHasKey($name, $tools, "{$name} left the registry.");
        }

        self::assertSame(
            count(self::CORE_TOOLS) + count(self::MODULE_TOOLS),
            count($tools),
            'The registry holds something that is neither a core tool nor a module tool, with'
            . ' every opt-in switch off: ' . implode(', ', array_keys($tools))
        );
    }

    /**
     * Not one file the manifest names reached the staged copy - the negative that makes the
     * test above mean "no module was loaded" rather than "the copy happened to work".
     *
     * @group sprint-seam
     */
    public function testTheStagedCopyCarriesNoModuleFile(): void
    {
        WordPressStubs::loadPlugin();

        $dir = $this->stage();

        self::assertDirectoryDoesNotExist(
            $dir . '/modules',
            'The staged copy has a modules/ directory, so the bare-core probe was not bare.'
        );

        foreach (\wpmcp_module_manifest() as $slug => $relative) {
            self::assertFileDoesNotExist(
                $dir . '/' . $relative,
                "The module '{$slug}' was staged, so the probe loaded it."
            );
            self::assertFileExists(
                \WPMCP_PLUGIN_DIR . '/' . $relative,
                "The manifest names '{$relative}' and the plugin does not have it."
            );
        }

        self::assertFileExists($dir . '/modules.php', 'The seam itself is core and must be staged.');
        self::assertFileExists($dir . '/wp-mcp.php');
        self::assertFileExists($dir . '/tools.php');
    }

    /* ------------------------------------------------------------------ the probe */

    /**
     * Load the staged core in a fresh PHP process and read back what its registry holds.
     *
     * THE ANSWER IS FENCED, because this process cannot control what the subprocess's PHP
     * writes before `<?php` is reached - a build whose ini loads a missing extension says so on
     * stdout, and on this project's workstation one does. So the JSON comes back between two
     * markers and anything around it is startup noise; what the noise must NOT contain is
     * checked separately, and a fatal error leaves no markers at all.
     *
     * @return array{names: list<string>, registered: array<string, bool>, absent_control: bool}
     */
    private function probeBareCore(): array
    {
        $dir    = $this->stage();
        $script = $dir . '/probe.php';

        file_put_contents($script, $this->probeSource($dir));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open(
            [
                PHP_BINARY,
                '-d', 'display_startup_errors=0',
                '-d', 'error_reporting=' . (string) (E_ALL),
                $script,
            ],
            $descriptors,
            $pipes
        );

        self::assertIsResource($process, 'Could not start a PHP process for the bare-core probe.');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $combined = $stdout . "\n" . $stderr;

        foreach (['Fatal error', 'Parse error', 'Uncaught'] as $bad) {
            self::assertStringNotContainsString(
                $bad,
                $combined,
                "The core does not load without its modules: a {$bad} in the probe. Something in"
                . " the core reaches into a module.\n" . $combined
            );
        }

        // THE ONE PLACE THIS WORKSTATION CAN SEE A DEPLOYMENT DEPRECATION. bin/local-env.sh sets
        // error_reporting=0 for every other command here, so the unit tier's own
        // --display-deprecations run is the only other check - and it cannot see one raised while
        // a file is being LOADED by a process it did not start. This one can, because it sets
        // error_reporting itself.
        self::assertStringNotContainsString(
            'Deprecated',
            $combined,
            "Loading the plugin raised a deprecation:\n" . $combined
        );

        self::assertSame(0, $status, "The bare-core probe exited {$status}.\n" . $combined);

        self::assertSame(
            1,
            preg_match('/-----WPMCP-PROBE-----(.*)-----WPMCP-END-----/s', $stdout, $m),
            "The probe printed no fenced answer, so it did not reach its last line.\n" . $combined
        );

        $decoded = json_decode(trim($m[1]), true);

        self::assertIsArray($decoded, "The probe's answer is not JSON: " . trim($m[1]));

        return $decoded;
    }

    /** The probe, as PHP source. Paths are exported rather than interpolated. */
    private function probeSource(string $staged): string
    {
        $repo = str_replace('\\', '/', \WPMCP_PLUGIN_DIR);

        return "<?php\n"
            . 'require ' . var_export($repo . '/vendor/autoload.php', true) . ";\n"
            // The LOAD-TIME stubs only, the same seven WordPressStubs uses, from the same file.
            . 'require ' . var_export($repo . '/tests/Support/wp-stubs.php', true) . ";\n"
            . 'require ' . var_export(str_replace('\\', '/', $staged) . '/wp-mcp.php', true) . ";\n"
            . "\\WpMcp\\Tests\\Support\\WordPressRuntime::install();\n"
            . '$tools = wpmcp_tools();' . "\n"
            . '$names = array_keys($tools);' . "\n"
            . '$registered = array();' . "\n"
            . 'foreach (' . var_export(self::MODULE_TOOLS, true) . ' as $n) {'
            . ' $registered[$n] = isset($tools[$n]); }' . "\n"
            . "echo \"-----WPMCP-PROBE-----\\n\";\n"
            . 'echo json_encode(array('
            . "'names' => \$names,"
            . " 'registered' => \$registered,"
            // The control: a name nothing has ever registered, asked the same way.
            . " 'absent_control' => isset(\$tools['wpmcp-no-such-tool-7a1c']),"
            . ")), \"\\n\";\n"
            . "echo \"-----WPMCP-END-----\\n\";\n";
    }

    /**
     * Copy the core into a temp directory: every `*.php` in the plugin root, plus `src/`.
     * `modules/` is not copied, which is the whole point.
     */
    private function stage(): string
    {
        if ($this->staged !== '') {
            return $this->staged;
        }

        $dir = sys_get_temp_dir() . '/wpmcp-bare-core-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($dir . '/src', 0777, true), "Could not create {$dir}/src.");

        foreach ((array) glob(\WPMCP_PLUGIN_DIR . '/*.php') as $file) {
            self::assertTrue(
                copy((string) $file, $dir . '/' . basename((string) $file)),
                'Could not stage ' . basename((string) $file)
            );
        }

        foreach ((array) glob(\WPMCP_PLUGIN_DIR . '/src/*.php') as $file) {
            self::assertTrue(
                copy((string) $file, $dir . '/src/' . basename((string) $file)),
                'Could not stage src/' . basename((string) $file)
            );
        }

        $this->staged = $dir;

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $entry) {
            $entry = (string) $entry;
            if (is_dir($entry)) {
                self::rmrf($entry);
            } else {
                @unlink($entry);
            }
        }

        @rmdir($dir);
    }
}
