<?php
/**
 * Which build is this site running? Asked of every surface that answers it, on a real
 * site, over real HTTP (sprint 14c, G1-G4).
 *
 * WHY. Every dev zip cut from this repository said `Version: 1.1.0`. wp-admin,
 * initialize's serverInfo and site-info all reported that same string for Sprint 10's
 * build and for Sprint 14b's, so an installed zip could not be told from an older
 * installed zip at all - only the FILENAME distinguished them, and a filename is gone
 * the moment the plugin is installed. On 2026-09-16 that cost about an hour of
 * diagnosing a "stale file" that was a stale zip.
 *
 * THE SITE UNDER TEST IS A CHECKOUT, AND THIS FAILS RATHER THAN SKIPS IF IT IS NOT.
 * Both Local sites junction this working tree into their plugins directory and wp-env
 * maps it in, so build.txt's placeholders are unsubstituted everywhere the suite runs.
 * That is the case G1 is about, and it is the normal case for a developer: the plugin
 * must say `source` on every surface and invent nothing.
 *
 * THE SUBSTITUTED CASE COMES THROUGH THE FILTER, NOT THROUGH A FILE WRITE. This tree is
 * junctioned into two live sites; a test that rewrote build.txt would be changing the
 * code under its own run, and would leave a stamped file behind if it died. So the
 * stamped surfaces are read in ONE `wp eval` process with `wpmcp_build_id` filtered,
 * which is the same seam a packager that stamps differently would use. The VALUE fed
 * through it in G3 is not invented either: it is read out of a real `git archive` of
 * this very commit, so the chain "git substitutes -> the plugin parses -> every surface
 * reports it" is proved end to end without a browser and without installing anything.
 *
 * @group sprint-14c
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use RuntimeException;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class BuildIdentityTest extends FixtureIntegrationTestCase
{
    /** The word every surface prints when there is no build - wp-mcp.php's constant. */
    private const UNKNOWN = 'source';

    /** A build id no commit will ever have, for the surfaces-agree test. */
    private const SENTINEL = 'b1dfeed';

    private static function login(): string { return Fixtures::name('buildid-admin'); }
    private static function label(): string { return Fixtures::name('buildid-token'); }

    private static int $userId = 0;
    private static string $token = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
        self::$token  = Fixtures::mintToken('admin', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /**
     * G1. Running from a checkout, every surface says `source` and none of them invents
     * a value, echoes the placeholder, or leaves a field empty.
     *
     * @group sprint-14c
     */
    public function testOnACheckoutEverySurfaceSaysSourceAndNoneInventsABuild(): void
    {
        self::assertSame(
            '',
            WpCli::evaluate('echo wpmcp_build_id();'),
            'The site under test reports a build id, so build.txt has been substituted'
            . ' there and G1 - the checkout case, which is how every developer and CI'
            . ' runs this plugin - cannot be proved. A gate that cannot be proved is not'
            . ' a gate, so this fails rather than skips.'
        );

        $version = self::pluginHeaderVersion();

        // The two surfaces a CLIENT sees, over real HTTP.
        $server = $this->serverInfoOverHttp();
        self::assertSame('wp-mcp', $server['name'] ?? null);
        self::assertSame($version, $server['version'] ?? null);
        self::assertSame(self::UNKNOWN, $server['build'] ?? null, 'serverInfo.build');

        $site = $this->siteInfoOverHttp();
        self::assertSame($version, $site['version'] ?? null, 'site-info wp_mcp.version');
        self::assertSame(self::UNKNOWN, $site['build'] ?? null, 'site-info wp_mcp.build');

        // The two surfaces a HUMAN sees.
        $rendered = $this->renderedSurfaces();
        self::assertSame(self::UNKNOWN, $rendered['label']);
        self::assertStringContainsString(self::UNKNOWN, $rendered['admin']);
        self::assertStringContainsString(self::UNKNOWN, $rendered['row']);

        // Nothing anywhere reads as a build that is not one.
        foreach ($this->everyBuildBearingString($server, $site, $rendered) as $where => $text) {
            self::assertStringNotContainsString('Format:', $text, "{$where} carries a git placeholder.");
            self::assertStringNotContainsString('$', $text, "{$where} carries a literal dollar.");
            self::assertNotSame('', trim($text), "{$where} is empty.");
        }

        self::assertNotSame(
            $version,
            $rendered['label'],
            'The unknown build reads as the version number, which is the confusion this'
            . ' sprint exists to remove.'
        );
    }

    /**
     * G2. Given a substituted stamp, all four surfaces report THE SAME value - because
     * all four ask the same function.
     *
     * @group sprint-14c
     */
    public function testWithAStampEverySurfaceReportsTheSameBuild(): void
    {
        $surfaces = $this->renderedSurfaces(self::SENTINEL);

        self::assertSame(self::SENTINEL, $surfaces['label']);
        self::assertSame(self::SENTINEL, $surfaces['server']['build'] ?? null, 'serverInfo.build');
        self::assertSame(self::SENTINEL, $surfaces['site']['build'] ?? null, 'site-info wp_mcp.build');
        self::assertStringContainsString(self::SENTINEL, $surfaces['admin'], 'the settings page line');
        self::assertStringContainsString(self::SENTINEL, $surfaces['row'], 'the Plugins screen row');

        self::assertSame(
            'FOUND',
            $surfaces['page'],
            'The settings page does not render the build line it is supposed to carry.'
            . ' The line exists as a function and nothing calls it, which is the one way'
            . ' this can pass every other assertion and still leave a human looking at a'
            . ' page that cannot tell two zips apart.'
        );

        // And the version is untouched by any of it.
        self::assertSame(self::pluginHeaderVersion(), $surfaces['server']['version'] ?? null);
        self::assertSame(self::pluginHeaderVersion(), $surfaces['site']['version'] ?? null);
    }

    /**
     * G3. A zip built by the documented recipe from a commit carries that commit's short
     * hash, and installing it would show that value.
     *
     * PROVED WITHOUT A BROWSER AND WITHOUT INSTALLING. `git archive` of HEAD is built
     * into a temp directory on this machine; the one file the plugin reads is taken out
     * of it and handed, as bytes, to the site's own parser; the value that comes back is
     * fed through the build seam and every surface is read again. There is no step in
     * that chain the plugin does not do for real on a site that has the zip installed
     * except reading the file off its own disk, which G1 covers.
     *
     * @group sprint-14c
     */
    public function testAnArchiveOfThisCommitCarriesItsShortHashAndEverySurfaceWouldShowIt(): void
    {
        $expected = self::git(['rev-parse', '--short', 'HEAD']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $expected, 'git rev-parse --short HEAD');

        $stamp = self::archivedBuildFile();

        self::assertStringNotContainsString(
            'Format:',
            $stamp,
            'git archive substituted nothing in build.txt. Either .gitattributes no longer'
            . ' marks it export-subst, or the file is not in the archived commit - and a'
            . ' zip built from it would report `source` while looking like a release.'
        );
        self::assertStringContainsString('short=' . $expected . "\n", $stamp);
        self::assertStringContainsString('commit=' . self::git(['rev-parse', 'HEAD']) . "\n", $stamp);

        // The SITE's own parser, on the archived bytes. Not a second implementation.
        //
        // BASE64 AND NOT A PHP LITERAL: the file is several lines long and a `wp eval`
        // snippet has to stay on one line to survive wp-env's re-quoting into the
        // container. The bytes that reach the parser are the bytes git wrote.
        $parsed = WpCli::evaluate(
            '$s = wpmcp_build_stamp_parse(base64_decode(' . self::phpString(base64_encode($stamp)) . '));'
            . ' echo $s["short"], "|", $s["commit"], "|", ($s["date"] === "" ? "no-date" : "dated");'
        );

        self::assertSame(
            $expected . '|' . self::git(['rev-parse', 'HEAD']) . '|dated',
            $parsed,
            'The plugin does not read this commit out of the file git just wrote for it.'
        );

        $surfaces = $this->renderedSurfaces($expected);

        self::assertSame($expected, $surfaces['label']);
        self::assertSame($expected, $surfaces['server']['build'] ?? null);
        self::assertSame($expected, $surfaces['site']['build'] ?? null);
        self::assertStringContainsString($expected, $surfaces['admin']);
        self::assertStringContainsString($expected, $surfaces['row']);
    }

    /**
     * G4. A release-shaped build reads as a clean semantic version with no dev stamp,
     * and no surface shows an empty or placeholder-looking field.
     *
     * A RELEASE BUILD IS THE STAMPED CASE, not a different one: the stamp sits beside
     * the version and never inside it, so `1.1.0-dev+<sha>` exists nowhere and there is
     * nothing for a release to strip. What a release must not do is carry a version
     * that is not a version, or a build field that is blank, or the word `source` on a
     * copy that really was built.
     *
     * @group sprint-14c
     */
    public function testAReleaseShapedBuildReadsAsACleanVersionWithNothingEmpty(): void
    {
        $version  = self::pluginHeaderVersion();
        $surfaces = $this->renderedSurfaces(self::SENTINEL);

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'the plugin header version');

        foreach (['server', 'site'] as $where) {
            self::assertSame($version, $surfaces[$where]['version'] ?? null, "{$where}.version");
            self::assertStringNotContainsString('-dev', (string) ($surfaces[$where]['version'] ?? ''));
            self::assertStringNotContainsString('+', (string) ($surfaces[$where]['version'] ?? ''));
            self::assertNotSame(self::UNKNOWN, $surfaces[$where]['build'] ?? null, "{$where}.build on a built copy");
        }

        // The settings page prints the version itself. The Plugins screen row does NOT,
        // and must not: WordPress prints `Version 1.1.0` in that row from the plugin
        // header, and the row meta is what is appended BESIDE it. A row that printed the
        // version again would be saying it twice.
        self::assertStringContainsString($version, $surfaces['admin'], 'the settings page line');
        self::assertStringNotContainsString(
            $version,
            $surfaces['row'],
            'The Plugins screen row repeats the version WordPress already prints there.'
        );

        foreach (['admin' => $surfaces['admin'], 'row' => $surfaces['row']] as $where => $text) {
            self::assertStringNotContainsString(self::UNKNOWN, $text, "{$where} still says `source` on a built copy");
            self::assertStringNotContainsString('Format:', $text, "{$where} shows a placeholder");
            self::assertStringNotContainsString('<code></code>', $text, "{$where} renders an empty field");
        }

        // And the unstamped copy this suite actually runs on shows no empty field either.
        $bare = $this->renderedSurfaces();
        self::assertStringNotContainsString('<code></code>', $bare['admin']);
        self::assertNotSame('', trim($bare['row']));
    }

    /* ----------------------------------------------------------------------- *
     * Reading the surfaces
     * ----------------------------------------------------------------------- */

    /** initialize's serverInfo, over real HTTP with a real token. */
    private function serverInfoOverHttp(): array
    {
        $raw  = (string) $this->mcp(self::$token)
            ->post('initialize', ['protocolVersion' => '2025-06-18'])->getBody();
        $body = json_decode($raw, true);

        self::assertIsArray($body['result']['serverInfo'] ?? null, 'initialize returned no serverInfo.');

        return $body['result']['serverInfo'];
    }

    /** site-info's wp_mcp block, over real HTTP with a real token. */
    private function siteInfoOverHttp(): array
    {
        $data = $this->mcp(self::$token)->callTool('site-info')->data();

        self::assertIsArray(
            $data['wp_mcp'] ?? null,
            'site-info does not report this plugin\'s own version and build, so an agent'
            . ' cannot answer "which build is this site running" without leaving the tool'
            . ' surface. Keys: ' . implode(', ', array_keys($data))
        );

        return $data['wp_mcp'];
    }

    /**
     * Every surface, read in ONE site process, optionally with the build seam set.
     *
     * The whole settings page is rendered here too, and only the ANSWER crosses back:
     * the page lists this site's real tokens, so `FOUND`/`MISSING` is what is returned
     * and the page itself never reaches a test log.
     *
     * @return array{label:string,server:array,site:array,admin:string,row:string,page:string}
     */
    private function renderedSurfaces(string $build = ''): array
    {
        $filter = $build === ''
            ? ''
            : 'add_filter("wpmcp_build_id", static function () { return ' . self::phpString($build) . '; }, 99);';

        $snippet = $filter
            . ' $tools = wpmcp_core_tools();'
            . ' $site = call_user_func($tools["site-info"]["run"], array());'
            . ' $row = wpmcp_plugin_row_meta(array(), plugin_basename(WPMCP_PLUGIN_FILE));'
            . ' $line = wpmcp_admin_build_line();'
            . ' ob_start(); wpmcp_render_admin(); $page = ob_get_clean();'
            . ' echo wp_json_encode(array('
            . '"label" => wpmcp_build_label(),'
            . ' "server" => wpmcp_server_info(),'
            . ' "site" => isset($site["wp_mcp"]) ? $site["wp_mcp"] : array(),'
            . ' "admin" => $line,'
            . ' "row" => implode(" | ", $row),'
            . ' "page" => (strpos($page, $line) === false ? "MISSING" : "FOUND")'
            . '));';

        $raw     = WpCli::evaluate($snippet, self::$userId);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['label'])) {
            throw new RuntimeException('The surface read did not return JSON: ' . $raw);
        }

        return $decoded;
    }

    /**
     * Every string any surface offers as a build, for the "nothing is invented" sweep.
     *
     * @return array<string, string>
     */
    private function everyBuildBearingString(array $server, array $site, array $rendered): array
    {
        return [
            'serverInfo.build'      => (string) ($server['build'] ?? ''),
            'site-info wp_mcp.build' => (string) ($site['build'] ?? ''),
            'the settings page line' => $rendered['admin'],
            'the Plugins screen row' => $rendered['row'],
        ];
    }

    /* ----------------------------------------------------------------------- *
     * git, and the one file out of the archive
     * ----------------------------------------------------------------------- */

    /**
     * build.txt as `git archive HEAD` writes it - the file a built zip would carry.
     *
     * NO `tar`, NO ZipArchive, NO PIPE. The tar is written to a temp file and walked
     * here: fourteen lines, and it works identically on the Windows workstation and on
     * the Linux runner. git archive's tar starts with a pax_global_header entry, so the
     * walk looks for the name rather than assuming the first member.
     */
    private static function archivedBuildFile(): string
    {
        $dir = sys_get_temp_dir() . '/wpmcp-14c-' . bin2hex(random_bytes(6));

        if (!mkdir($dir) && !is_dir($dir)) {
            self::fail('Could not create a temp directory for the archive: ' . $dir);
        }

        $tarPath = $dir . '/build.tar';

        try {
            self::git(['archive', '--format=tar', '--output=' . $tarPath, 'HEAD', 'build.txt']);

            $tar = (string) file_get_contents($tarPath);
            $at  = 0;

            while ($at + 512 <= strlen($tar)) {
                $name = rtrim(substr($tar, $at, 100), "\0");

                if ($name === '') { break; }

                $size = (int) octdec(trim(substr($tar, $at + 124, 12)));

                if ($name === 'build.txt') {
                    return substr($tar, $at + 512, $size);
                }

                $at += 512 + (int) (ceil($size / 512) * 512);
            }
        } finally {
            @unlink($tarPath);
            @rmdir($dir);
        }

        self::fail(
            'git archive HEAD produced no build.txt. The stamp file is not in the'
            . ' committed tree, so every zip built from this commit reports `source`.'
        );
    }

    /** `git <args>` in this working tree. A git that is not there is a failure, not a skip. */
    private static function git(array $args): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = @proc_open(
            array_merge(['git', '-C', WPMCP_PLUGIN_DIR], $args),
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            self::fail(
                'git could not be started, so the sprint-14c gate cannot prove that a'
                . ' built zip carries its commit. git is on PATH on both development'
                . ' machines and on the CI runner; a gate that skips is not a gate.'
            );
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            self::fail('git ' . implode(' ', $args) . " exited {$code}: " . trim($err));
        }

        return trim($out);
    }

    /* ----------------------------------------------------------------------- *
     * Small helpers
     * ----------------------------------------------------------------------- */

    /** The `Version:` line of the plugin header in THIS working tree. */
    private static function pluginHeaderVersion(): string
    {
        $source = (string) file_get_contents(WPMCP_PLUGIN_DIR . '/wp-mcp.php');

        self::assertSame(
            1,
            preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $source, $m),
            'wp-mcp.php has no single `Version:` header line.'
        );

        return $m[1];
    }

    /** $value as a single-quoted PHP literal, for embedding in a `wp eval` snippet. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
