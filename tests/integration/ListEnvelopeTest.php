<?php
/**
 * Every paged list tool answers in one envelope, with one date format, and its paging
 * ENDS (sprint 14d, G3).
 *
 * THREE DEFECTS OF ONE CLASS, all found by clients reading the tools on a real site:
 *
 *   - list-media returned `Y-m-d H:i:s` in UTC where every other tool returned ISO 8601
 *     site-local, and list-comments did the same; list-users said `+00:00`.
 *   - list-media, list-comments and list-terms had no page / limit / has_more envelope,
 *     so an agent could not page them the way it pages list-posts.
 *   - Every paged tool clamped `page` to 100 and went on answering `has_more: true`, so an
 *     agent paging to the end of a site with more than 100 pages looped for ever
 *     (list-users, found live). has_more is now false at the cap.
 *
 * THE CAP IS PROVED WITH 101 ROWS AND limit 1, for every paged tool: page 100 is then a
 * page with a row after it, which is exactly the page that used to say has_more: true.
 * The rows are made in ONE `wp eval` per kind and removed the same way - 101 wp-cli spawns
 * per kind would cost minutes on Windows and far more through wp-env.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use RuntimeException;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class ListEnvelopeTest extends FixtureIntegrationTestCase
{
    private const ROWS = 101;

    /** The one date format every list tool returns: ISO 8601, site-local, no offset. */
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/';

    private const ENVELOPE = ['count', 'page', 'limit', 'has_more', 'items'];

    private const CODE_SWITCH = 'envelope-code';

    private static function label(): string { return Fixtures::name('envelope'); }
    private static function login(): string { return Fixtures::name('le-admin'); }
    private static function themeFile(): string { return Fixtures::name('le-file.css'); }

    private static int $userId = 0;
    private static string $token = '';

    /** @var array{posts: list<int>, users: list<int>, terms: list<int>} */
    private static array $made = ['posts' => [], 'users' => [], 'terms' => []];

    private static int $commentPost = 0;
    private static int $revisionPost = 0;

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

        MuPlugin::drop(self::CODE_SWITCH, self::codeSwitchSource());

        // Posts, attachments, a post carrying the comments, a post carrying the revisions,
        // users and tags: 101 of each, one process per kind.
        $made = self::bulk(sprintf(
            '$u = %1$d; $p = %2$s; $n = %3$d; $out = array("posts" => array(), "users" => array(), "terms" => array());'
            . ' for ($i = 1; $i <= $n; $i++) {'
            . '  $out["posts"][] = wp_insert_post(array("post_title" => $p . "le-post " . $i, "post_status" => "publish", "post_author" => $u));'
            . '  $out["posts"][] = wp_insert_attachment(array("post_title" => $p . "le-media " . $i, "post_mime_type" => "image/png", "post_status" => "inherit", "post_author" => $u), false);'
            . '  $out["users"][] = wp_insert_user(array("user_login" => $p . "le-user-" . $i, "user_pass" => wp_generate_password(24), "user_email" => $p . "le-user-" . $i . "@example.invalid", "role" => "subscriber"));'
            . '  $t = wp_insert_term($p . "le-tag " . $i, "post_tag"); $out["terms"][] = is_wp_error($t) ? 0 : (int) $t["term_id"];'
            . ' }'
            . ' $c = wp_insert_post(array("post_title" => $p . "le-comments", "post_status" => "publish", "post_author" => $u, "comment_status" => "open")); $out["posts"][] = $c; $out["comment_post"] = $c;'
            . ' for ($i = 1; $i <= $n; $i++) { wp_insert_comment(array("comment_post_ID" => $c, "comment_content" => $p . "le-comment " . $i, "comment_approved" => 1, "comment_author" => $p . "le")); }'
            . ' $r = wp_insert_post(array("post_title" => $p . "le-revisions", "post_status" => "publish", "post_author" => $u, "post_content" => "r0")); $out["posts"][] = $r; $out["revision_post"] = $r;'
            . ' for ($i = 1; $i <= $n; $i++) { $row = get_post($r, ARRAY_A); $row["post_content"] = "r" . $i; _wp_put_post_revision($row); }',
            self::$userId,
            var_export(Fixtures::runPrefix(), true),
            self::ROWS
        ));

        self::$made['posts'] = array_map('intval', $made['posts']);
        self::$made['users'] = array_map('intval', $made['users']);
        self::$made['terms'] = array_map('intval', $made['terms']);
        self::$commentPost   = (int) $made['comment_post'];
        self::$revisionPost  = (int) $made['revision_post'];

        foreach (['posts', 'users', 'terms'] as $kind) {
            if (count(array_filter(self::$made[$kind])) < self::ROWS) {
                throw new RuntimeException("Could not create {$kind} for the envelope fixture.");
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::CODE_SWITCH);

        if (self::$made['posts'] !== [] || self::$made['users'] !== [] || self::$made['terms'] !== []) {
            // Only rows this run made, and only ones still carrying its prefix.
            WpCli::tryEvaluate(sprintf(
                'require_once ABSPATH . "wp-admin/includes/user.php"; $p = %s; $d = json_decode(%s, true);'
                . ' foreach ($d["posts"] as $id) { $x = get_post($id); if ($x && strpos($x->post_title, $p) === 0) { $x->post_type === "attachment" ? wp_delete_attachment($id, true) : wp_delete_post($id, true); } }'
                . ' foreach ($d["users"] as $id) { $x = get_userdata($id); if ($x && strpos($x->user_login, $p) === 0) { wp_delete_user($id); } }'
                . ' foreach ($d["terms"] as $id) { $x = get_term($id, "post_tag"); if ($x && !is_wp_error($x) && strpos($x->name, $p) === 0) { wp_delete_term($id, "post_tag"); } }'
                . ' echo "ok";',
                var_export(Fixtures::runPrefix(), true),
                var_export((string) json_encode(self::$made), true)
            ));
        }
        if (Fixtures::themeFileExists(self::themeFile())) {
            Fixtures::deleteThemeFile(self::themeFile());
        }

        self::$made = ['posts' => [], 'users' => [], 'terms' => []];

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /**
     * G3. Six paged tools, one envelope, and page 100 of 101 rows at limit 1 says
     * has_more false - as does a page past the cap, which is answered as page 100.
     *
     * @group sprint-14d
     */
    public function testEveryPagedListToolSharesOneEnvelopeAndItsPagingEnds(): void
    {
        foreach (self::pagedCalls() as $tool => $arguments) {
            $first = $this->page($tool, $arguments, 1);
            self::assertSame(self::ENVELOPE, array_keys($first), "{$tool} does not answer in the shared envelope.");
            self::assertSame(1, $first['page'], $tool);
            self::assertSame(1, $first['limit'], $tool);
            self::assertTrue($first['has_more'], "{$tool} page 1 of 101 says there is nothing more.");

            $cap = $this->page($tool, $arguments, 100);
            self::assertSame(100, $cap['page'], $tool);
            self::assertFalse(
                $cap['has_more'],
                "{$tool} says has_more at page 100, the cap: an agent paging until has_more is"
                . ' false would ask for page 101, get page 100 again, and never stop.'
            );

            $past = $this->page($tool, $arguments, 150);
            self::assertSame(100, $past['page'], "{$tool} did not clamp a page past the cap.");
            self::assertFalse($past['has_more'], "{$tool} says has_more past the cap.");
        }
    }

    /**
     * G3. Every date every list tool returns is ISO 8601 in one form: site-local, no offset.
     *
     * @group sprint-14d
     */
    public function testEveryListToolReturnsDatesInOneIsoFormat(): void
    {
        $dates = [];

        foreach (self::pagedCalls() as $tool => $arguments) {
            $item = $this->page($tool, $arguments, 1)['items'][0] ?? [];
            foreach (['date', 'modified', 'registered'] as $field) {
                if (array_key_exists($field, $item)) { $dates["{$tool}.{$field}"] = $item[$field]; }
            }
        }

        // code-history: one stored version, from a second write of this run's file.
        $client = $this->mcp(self::$token);
        foreach (['/* one */', '/* two */'] as $content) {
            $w = $client->callTool('code-write', ['path' => self::themeFile(), 'content' => $content]);
            self::assertFalse($w->isError, $w->text);
        }
        $history = $client->callTool('code-history', ['path' => self::themeFile()])->data();
        $dates['code-history.saved_at'] = $history['versions'][0]['saved_at'] ?? null;

        foreach ([
            'list-posts.date', 'list-posts.modified', 'list-media.date', 'list-media.modified',
            'list-comments.date', 'list-revisions.date', 'list-users.registered', 'code-history.saved_at',
        ] as $expected) {
            self::assertArrayHasKey($expected, $dates, "{$expected} is not returned at all.");
        }

        foreach ($dates as $where => $value) {
            self::assertIsString($value, "{$where} is not a string.");
            self::assertMatchesRegularExpression(self::ISO, $value, "{$where} is not the list tools' ISO 8601 form: {$value}");
        }
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /** @return array<string, array<string, mixed>> tool => the arguments that select this run's 101 rows */
    private static function pagedCalls(): array
    {
        return [
            'list-posts'     => ['search' => Fixtures::name('le-post'), 'status' => 'publish'],
            'list-media'     => ['search' => Fixtures::name('le-media')],
            'list-comments'  => ['post' => self::$commentPost],
            'list-revisions' => ['id' => self::$revisionPost],
            'list-users'     => ['search' => Fixtures::name('le-user')],
            'list-terms'     => ['taxonomy' => 'post_tag', 'search' => Fixtures::name('le-tag')],
        ];
    }

    private function page(string $tool, array $arguments, int $page): array
    {
        $result = $this->mcp(self::$token)->callTool($tool, array_merge($arguments, ['limit' => 1, 'page' => $page]));

        if ($result->isError) {
            throw new RuntimeException("{$tool} failed: " . $result->text);
        }

        return $result->data();
    }

    /** Run PHP that fills $out, and return $out - base64 JSON on the last line. */
    private static function bulk(string $php): array
    {
        $raw     = WpCli::evaluate($php . ' echo "\n", base64_encode(wp_json_encode($out));');
        $lines   = preg_split('/\r?\n/', trim($raw));
        $decoded = json_decode((string) base64_decode((string) end($lines), true), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The envelope fixture could not be built: ' . substr($raw, 0, 300));
        }

        return $decoded;
    }

    /** The code tools, for THIS RUN'S requests only. */
    private static function codeSwitchSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));

        return <<<PHP
/**
 * wp-mcp sprint-14d list-envelope fixture for run {$run}: the code tools, for this run's
 * requests only. Dropped and removed by tests/integration/ListEnvelopeTest.php.
 */
add_filter('pre_option_wpmcp_code_enabled', static function (\$pre) {
    return (isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '{$run}') ? 1 : \$pre;
});
PHP;
    }
}
