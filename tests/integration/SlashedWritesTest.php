<?php
/**
 * A backslash survives every write tool, byte for byte, and comes back out of the read
 * tool that serves it.
 *
 * THE CLASS, NOT A BUG. WordPress's write functions take SLASHED input and unslash it on
 * the way to the database - `wp_insert_post()` at `post.php:4981`, `wp_insert_term()` at
 * `taxonomy.php:2509-2511`, `wp_insert_comment()` at `comment.php:2159`, and all three of
 * `add_metadata()` / `update_metadata()` / `delete_metadata()` at `meta.php:61-63`,
 * `:218-222`, `:419-421`. The convention is older than the REST API and it is still the
 * contract, which is why every core REST controller calls `wp_slash()` immediately before
 * the write (posts `:776`/`:980`, comments `:793`, terms `:550`, attachments `:891`,
 * `:1323`). A tool that decodes JSON and hands it straight in loses ONE BACKSLASH from
 * every value that has one - and reports the mangled value back honestly, because it
 * re-read the row it just wrote. That is why it survived v1.0, a sprint and two reviews.
 *
 * `wp_update_post()` makes it worse in a way worth stating separately: it reads the
 * existing row and `wp_slash()`es it (`post.php:5345`, "Escape data pulled from DB")
 * before merging the caller's array over the top, so an unslashed array is two conventions
 * inside one structure.
 *
 * TWO ROLES FOR THE POST FIELDS, and they are two different losses. An Editor holds
 * `unfiltered_html`, so `content_save_pre` runs no kses and the only unslash is
 * `wp_insert_post()`'s. An Author does not, so `wp_filter_post_kses()` runs first and it
 * is `addslashes( wp_kses( stripslashes( $data ) ) )` - a second place that assumes
 * slashed input. Both are asserted, because a fix that satisfied one could miss the other.
 *
 * EVERY ASSERTION READS THROUGH A READ TOOL, not through the database. The write tool's
 * own answer is a re-read of the row and would agree with itself about a value that was
 * never what the caller sent; the question here is what the NEXT agent sees.
 *
 * @group sprint-11
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\WpCli;

final class SlashedWritesTest extends FixtureIntegrationTestCase
{
    /** The mu-plugin slug: the fake HTTP response upload-media downloads. */
    private const DOWNLOAD = 'sideload';

    /** Sent to make that fixture answer this run's one download. */
    private const ARM_HEADER = 'X-Wpmcp-Test-Sideload';

    private static function label(): string { return Fixtures::name('slashed'); }

    private static function editorLogin(): string { return Fixtures::name('slasheditor'); }
    private static function authorLogin(): string { return Fixtures::name('slashauthor'); }

    /**
     * The payload. Three shapes of backslash in one string, because they fail
     * differently: a Windows path (`\U`, `\m` - no escape meaning), a regex (`\d`), and a
     * doubled one, which is what survives when exactly one round of stripping happens.
     */
    private static function marked(string $what): string
    {
        return Fixtures::name($what) . ' C:\\Users\\max \\d+ a\\\\b';
    }

    private static int $editorId = 0;
    private static int $authorId = 0;
    private static string $editorToken = '';
    private static string $authorToken = '';

    /** Everything the TOOLS created during the run, cleared in teardown. */
    private static array $posts = [];
    private static array $terms = [];
    private static array $attachments = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        // An Editor holds unfiltered_html and manage_categories; an Author holds neither,
        // which is what puts kses in front of one of them and not the other.
        self::$editorId = Fixtures::createUser(self::editorLogin(), 'editor');
        self::$authorId = Fixtures::createUser(self::authorLogin(), 'author');

        MuPlugin::drop(self::DOWNLOAD, self::downloadSource());

        self::$editorToken = Fixtures::mintToken('admin', self::label(), self::$editorId);
        self::$authorToken = Fixtures::mintToken('admin', self::label(), self::$authorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::DOWNLOAD);

        // FORCE, AND WITH THE FILE. An attachment created by upload-media has real bytes
        // in wp-content/uploads, and the debris check cannot see a file there - it only
        // knows the active theme. wp_delete_attachment($id, true) takes both.
        foreach (self::$attachments as $id) {
            WpCli::tryEvaluate(sprintf('echo (int) (bool) wp_delete_attachment(%d, true);', (int) $id));
        }

        // AND BY NAME, because the attachment does not always know its own file. MEASURED
        // on the stress site: an image-conversion plugin writes a `.webp` beside the
        // uploaded `.png` and repoints `_wp_attached_file` at the `.webp`, so the delete
        // above removes the row and the `.webp` and orphans the `.png` - one file per run,
        // eight before anybody looked. purge() below does the same sweep for any class.
        foreach (Fixtures::ours(Fixtures::leftoverUploadFiles()) as $relative => $name) {
            Fixtures::deleteUploadFile((string) $relative);
        }

        foreach (self::$posts as $id) { Fixtures::deletePost((int) $id); }

        foreach (self::$terms as $id) {
            WpCli::tryRun(['term', 'delete', 'category', (string) $id]);
        }

        self::$posts = [];
        self::$terms = [];
        self::$attachments = [];

        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * wp_insert_post / wp_update_post
     * ---------------------------------------------------------------- */

    /**
     * title, content and excerpt survive create-post and update-post for an EDITOR, who
     * holds `unfiltered_html` - so the only unslash in the path is wp_insert_post()'s.
     *
     * @group sprint-11
     */
    public function testPostFieldsKeepTheirBackslashesForAnEditor(): void
    {
        $this->assertPostFieldsSurvive('editor', self::$editorToken);
    }

    /**
     * The same for an AUTHOR, who does not hold `unfiltered_html` - so `content_save_pre`
     * runs `wp_filter_post_kses()`, which is `addslashes( wp_kses( stripslashes( $data ) ) )`
     * and assumes slashed input of its own.
     *
     * SPLIT FROM THE EDITOR CASE ON PURPOSE. As one test with a loop, the Editor's
     * assertion failed first on the unfixed code and the Author half never ran - so the
     * second path would have been proven green and never proven red.
     *
     * @group sprint-11
     */
    public function testPostFieldsKeepTheirBackslashesForAnAuthor(): void
    {
        $this->assertPostFieldsSurvive('author', self::$authorToken);
    }

    /**
     * kses ran for the Author and did not run for the Editor - observed, not assumed.
     *
     * THE GAP THIS CLOSES. The two role tests above go red identically on the unfixed
     * code, so neither of them proves the Author's content actually took the kses path;
     * a site where the Author somehow held unfiltered_html would pass both. `<script>`
     * is the observable: wp_filter_post_kses removes the tag for a user without
     * unfiltered_html and nothing touches it for one who has it. The backslash payload
     * rides in the same string, so this also shows kses and the slash fix composing.
     *
     * @group sprint-11
     */
    public function testKsesStripsScriptForTheAuthorAndNotForTheEditor(): void
    {
        $results = [];

        foreach (['editor' => self::$editorToken, 'author' => self::$authorToken] as $role => $token) {
            $created = $this->mcp($token)->callTool('create-post', [
                'title'   => Fixtures::name($role . '-kses'),
                'content' => '<script>wpmcp()</script>' . self::marked($role . '-kses-body'),
            ]);

            self::assertFalse($created->isError, $created->text);

            $id = (int) $created->data()['id'];
            self::$posts[] = $id;

            $results[$role] = $this->mcp($token)->callTool('get-post', ['id' => $id])->data()['content'];
        }

        self::assertStringContainsString(
            '<script>',
            $results['editor'],
            'The Editor\'s <script> was stripped, so the Editor is not on the unfiltered_html'
            . ' path this test depends on - the comparison below would prove nothing.'
        );
        self::assertStringNotContainsString(
            '<script>',
            $results['author'],
            'The Author\'s <script> survived, so kses did NOT run on the Author\'s content and'
            . ' testPostFieldsKeepTheirBackslashesForAnAuthor never exercised the kses path.'
        );
        self::assertStringContainsString(
            self::marked('author-kses-body'),
            $results['author'],
            'kses removed the tag and took a backslash with it: the slashed value went through'
            . ' stripslashes/addslashes and came back one backslash short.'
        );
    }

    /* ------------------------------------------------------------------
     * the read side: lookups that stripslashes their input
     * ---------------------------------------------------------------- */

    /**
     * Two create-post calls naming the same backslashed category produce ONE term, and
     * get-post reports that term's name with its backslash.
     *
     * O1, THE READ SIDE OF THE SLASHING CLASS. wpmcp_apply_terms() looks a name up with
     * get_term_by('name'), which stripslashes its input inside WP_Term_Query
     * (class-wp-term-query.php:548-549). Once the insert was slashed and the lookup was
     * not, `A\B` was stored correctly, looked up as `AB`, missed - and the second post
     * created `ab-2`. Counted through list-terms, not guessed from ids.
     *
     * @group sprint-11
     */
    public function testNamingTheSameBackslashedTermTwiceCreatesItOnce(): void
    {
        $name = self::marked('dup-term');
        $ids  = [];

        foreach (['first', 'second'] as $which) {
            $created = $this->mcp(self::$editorToken)->callTool('create-post', [
                'title' => Fixtures::name('dup-term-carrier-' . $which),
                'terms' => ['category' => [$name]],
            ]);

            self::assertFalse($created->isError, $created->text);

            $ids[$which] = (int) $created->data()['id'];
            self::$posts[] = $ids[$which];
        }

        $listed = $this->mcp(self::$editorToken)->callTool('list-terms', [
            'taxonomy' => 'category',
            'search'   => Fixtures::name('dup-term'),
        ]);

        self::assertFalse($listed->isError, $listed->text);

        $matching = [];

        foreach ($listed->data()['terms'] as $term) {
            self::$terms[] = (int) $term['id'];

            if (str_starts_with($term['name'], Fixtures::name('dup-term'))) {
                $matching[] = $term['name'] . ' (' . $term['slug'] . ')';
            }
        }

        self::assertCount(
            1,
            $matching,
            'Naming the same backslashed category on two posts created more than one term:'
            . ' the name lookup is unslashed while the insert is slashed. Found: '
            . implode(' | ', $matching)
        );

        foreach ($ids as $which => $id) {
            $terms = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => $id])->data()['terms'];

            self::assertSame(
                [$name],
                array_column($terms['category'] ?? [], 'name'),
                "get-post on the {$which} post does not report the one backslashed term by name."
            );
        }
    }

    /**
     * list-posts can find a backslashed value it just stored.
     *
     * The same read-side rule as the term lookup, found by the sweep rather than
     * reported: WP_Query::parse_search() stripslashes `s` (class-wp-query.php:1439), so
     * after the write fix a search for `C:\Users` was stripped to `C:Users` and could no
     * longer match the row that now correctly holds `C:\Users`.
     *
     * @group sprint-11
     */
    public function testSearchFindsTheBackslashedContentItStored(): void
    {
        $content = self::marked('searchable');

        $created = $this->mcp(self::$editorToken)->callTool('create-post', [
            'title'   => Fixtures::name('searchable-title'),
            'content' => $content,
            'status'  => 'publish',
        ]);

        self::assertFalse($created->isError, $created->text);

        $id = (int) $created->data()['id'];
        self::$posts[] = $id;

        $found = $this->mcp(self::$editorToken)->callTool('list-posts', [
            'search' => Fixtures::name('searchable') . ' C:\\Users\\max',
        ]);

        self::assertFalse($found->isError, $found->text);
        self::assertContains(
            $id,
            $found->column('id'),
            'list-posts could not find a post by the backslashed text it holds: WP_Query'
            . ' stripslashes `s`, so the search has to be slashed like the write was.'
        );
    }

    /** Create, read back, update, read back - every field byte-exact. */
    private function assertPostFieldsSurvive(string $role, string $token): void
    {
        $title   = self::marked($role . '-title');
        $content = self::marked($role . '-content');
        $excerpt = self::marked($role . '-excerpt');

        $created = $this->mcp($token)->callTool('create-post', [
            'title'   => $title,
            'content' => $content,
            'excerpt' => $excerpt,
        ]);

        self::assertFalse($created->isError, $created->text);

        $id = (int) $created->data()['id'];
        self::$posts[] = $id;

        $post = $this->mcp($token)->callTool('get-post', ['id' => $id])->data();

        self::assertSame($title, $post['title'], "create-post lost a backslash in the title as the {$role}.");
        self::assertSame($content, $post['content'], "create-post lost a backslash in the content as the {$role}.");
        self::assertSame($excerpt, $post['excerpt'], "create-post lost a backslash in the excerpt as the {$role}.");

        // AND THE UPDATE PATH, which is a different loss: wp_update_post slashes the row
        // it read from the database before merging the caller's array over it.
        $title2   = self::marked($role . '-title-2');
        $content2 = self::marked($role . '-content-2');
        $excerpt2 = self::marked($role . '-excerpt-2');

        $updated = $this->mcp($token)->callTool('update-post', [
            'id'      => $id,
            'title'   => $title2,
            'content' => $content2,
            'excerpt' => $excerpt2,
        ]);

        self::assertFalse($updated->isError, $updated->text);

        $after = $this->mcp($token)->callTool('get-post', ['id' => $id])->data();

        self::assertSame($title2, $after['title'], "update-post lost a backslash in the title as the {$role}.");
        self::assertSame($content2, $after['content'], "update-post lost a backslash in the content as the {$role}.");
        self::assertSame($excerpt2, $after['excerpt'], "update-post lost a backslash in the excerpt as the {$role}.");
    }

    /* ------------------------------------------------------------------
     * wp_insert_term, both call sites
     * ---------------------------------------------------------------- */

    /**
     * create-term keeps a backslash in the name AND in the description, and list-terms
     * reads both back.
     *
     * TWO FIELDS, because `wp_insert_term()` unslashes two: `name` and `description`
     * (`taxonomy.php:2510-2511`). Slug cannot carry one - `sanitize_title()` removes it -
     * which is why slashing the whole args array is a no-op there and still the right rule.
     *
     * @group sprint-11
     */
    public function testCreateTermKeepsItsBackslashes(): void
    {
        $name        = self::marked('term-name');
        $description = self::marked('term-desc');

        $created = $this->mcp(self::$editorToken)->callTool('create-term', [
            'taxonomy'    => 'category',
            'name'        => $name,
            'description' => $description,
        ]);

        self::assertFalse($created->isError, $created->text);

        $id = (int) $created->data()['id'];
        self::$terms[] = $id;

        self::assertSame($name, $created->data()['name'], 'create-term reported a different name than it was given.');

        $term = $this->termFrom(self::$editorToken, $id);

        self::assertSame($name, $term['name'], 'create-term lost a backslash in the term name.');

        // THE DESCRIPTION HAS NO READ TOOL - list-terms returns id, name, slug, taxonomy,
        // count and parent - so this one is read from the site through wp-cli instead,
        // which is still a second process and a different path from the write. It is
        // asserted because wp_insert_term() unslashes `description` as well as `name`
        // (taxonomy.php:2510-2511), and a fix that slashed only the name would pass every
        // other assertion in this class.
        self::assertSame(
            $description,
            Fixtures::termField($id, 'category', 'description'),
            'create-term lost a backslash in the description, which is why the whole args'
            . ' array is slashed and not just the name.'
        );
    }

    /**
     * The OTHER `wp_insert_term()` call: a term brought into being by name through
     * create-post's `terms` argument.
     *
     * Two call sites, two fixes; a test of one would have passed with the other broken.
     *
     * @group sprint-11
     */
    public function testATermCreatedThroughCreatePostKeepsItsBackslashes(): void
    {
        $name = self::marked('term-via-post');

        $created = $this->mcp(self::$editorToken)->callTool('create-post', [
            'title' => Fixtures::name('slashed-term-carrier'),
            'terms' => ['category' => [$name]],
        ]);

        self::assertFalse($created->isError, $created->text);

        $data = $created->data();
        self::$posts[] = (int) $data['id'];

        self::assertArrayNotHasKey('terms_refused', $data, 'The Editor could not create the term at all.');

        $listed = $this->mcp(self::$editorToken)->callTool('list-terms', [
            'taxonomy' => 'category',
            'search'   => Fixtures::name('term-via-post'),
        ]);

        self::assertFalse($listed->isError, $listed->text);

        $names = array_column($listed->data()['terms'], 'name', 'id');

        self::assertContains(
            $name,
            array_values($names),
            'The term create-post made through wpmcp_apply_terms lost a backslash. Found: '
            . implode(' | ', array_values($names))
        );

        foreach ($names as $id => $found) {
            if ($found === $name) { self::$terms[] = (int) $id; }
        }
    }

    /* ------------------------------------------------------------------
     * media_handle_sideload and the alt-text meta
     * ---------------------------------------------------------------- */

    /**
     * upload-media keeps a backslash in the title and in the alt text, and get-media
     * reads both back.
     *
     * NOTHING LEAVES THIS MACHINE. The mu-plugin answers `pre_http_request` for this run's
     * one armed call with a 1x1 PNG written straight into the temp file `download_url()`
     * was going to stream into, so there is no outbound request, no dependency on a URL
     * somebody else owns, and no loopback to a `.local` certificate. The attachment is
     * force-deleted with its file in teardown, because the debris check knows the theme
     * directory and not the uploads one.
     *
     * @group sprint-11
     */
    public function testUploadMediaKeepsBackslashesInTitleAndAlt(): void
    {
        $title = self::marked('media-title');
        $alt   = self::marked('media-alt');

        $uploaded = $this->mcp(self::$editorToken)->callTool(
            'upload-media',
            [
                'source_url' => 'https://example.invalid/' . Fixtures::name('pixel') . '.png',
                'filename'   => Fixtures::name('pixel') . '.png',
                'title'      => $title,
                'alt'        => $alt,
            ],
            [self::ARM_HEADER => 'on']
        );

        self::assertFalse($uploaded->isError, $uploaded->text);

        $id = (int) $uploaded->data()['id'];
        self::$attachments[] = $id;

        $media = $this->mcp(self::$editorToken)->callTool('get-media', ['id' => $id]);

        self::assertFalse($media->isError, $media->text);
        self::assertSame(
            $title,
            $media->data()['title'],
            'upload-media lost a backslash in the title. media_handle_sideload puts it'
            . ' straight into wp_insert_attachment, which is wp_insert_post.'
        );
        self::assertSame(
            $alt,
            $media->data()['alt'],
            'upload-media lost a backslash in the alt text. That is an ordinary'
            . ' update_post_meta, and update_metadata unslashes what it is given.'
        );
    }

    /* ------------------------------------------------------------------
     * wp_new_comment
     * ---------------------------------------------------------------- */

    /**
     * reply-comment keeps a backslash in the comment body, and list-comments reads it
     * back.
     *
     * The Editor is used deliberately: `pre_comment_content` is kses for a user without
     * `unfiltered_html`, so an Author's body would take the second path as well - what is
     * under test here is `wp_insert_comment()`'s own unslash, with kses out of the way.
     *
     * @group sprint-11
     */
    public function testReplyCommentKeepsItsBackslashes(): void
    {
        $body = self::marked('comment-body');

        $post = $this->mcp(self::$editorToken)->callTool('create-post', [
            'title'   => Fixtures::name('slashed-comment-host'),
            'content' => Fixtures::name('slashed-comment-host-body'),
            'status'  => 'publish',
        ]);

        self::assertFalse($post->isError, $post->text);

        $postId = (int) $post->data()['id'];
        self::$posts[] = $postId;

        // comment_status follows the site default, so open it explicitly - a closed
        // thread refuses for the wrong reason and the test would prove nothing.
        WpCli::run(['post', 'update', (string) $postId, '--comment_status=open']);

        // reply-comment replies to a COMMENT, so there has to be one to reply to.
        $parent = Fixtures::createComment($postId, Fixtures::name('slashed-parent'), true);

        $replied = $this->mcp(self::$editorToken)->callTool('reply-comment', [
            'id'      => $parent,
            'content' => $body,
        ]);

        self::assertFalse($replied->isError, $replied->text);

        $listed = $this->mcp(self::$editorToken)->callTool('list-comments', ['post' => $postId]);

        self::assertFalse($listed->isError, $listed->text);

        $contents = array_column($listed->data()['items'], 'content');

        self::assertContains(
            $body,
            $contents,
            'reply-comment lost a backslash. wp_new_comment filters and then calls'
            . ' wp_insert_comment, which opens with wp_unslash($commentdata). Found: '
            . implode(' | ', $contents)
        );
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /** One term as list-terms reports it, by id. */
    private function termFrom(string $token, int $id): array
    {
        $listed = $this->mcp($token)->callTool('list-terms', ['taxonomy' => 'category']);

        self::assertFalse($listed->isError, $listed->text);

        foreach ($listed->data()['terms'] as $item) {
            if ((int) $item['id'] === $id) { return $item; }
        }

        self::fail("list-terms does not report the term {$id} this test just created.");
    }

    /**
     * The fixture: one fake HTTP download, for this run's armed request only.
     *
     * `download_url()` calls `wp_safe_remote_get()` with `stream => true` and a
     * `filename`, so a `pre_http_request` short-circuit has to WRITE those bytes itself -
     * returning a response and leaving the file empty makes `wp_handle_sideload()` refuse
     * with "File is empty", which would be a green-looking red.
     *
     * The body is a 1x1 transparent PNG, small enough to sit in this file as base64 and
     * real enough that `wp_check_filetype_and_ext()` and the image editor accept it.
     */
    private static function downloadSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $arm    = 'HTTP_' . strtoupper(str_replace('-', '_', self::ARM_HEADER));
        $png    = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return <<<PHP
/**
 * wp-mcp sprint-11 sideload fixture for run {$run}. Dropped and removed by
 * tests/integration/SlashedWritesTest.php. IT ANSWERS ONLY FOR THIS RUN'S ARMED
 * REQUESTS, so no other request on this site is affected, and it makes no outbound
 * connection of its own. If you are reading this on a live site, the run that wrote it
 * crashed; deleting the file is safe.
 */
add_filter('pre_http_request', static function (\$pre, \$args, \$url) {
    \$mine = isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '{$run}'
        && isset(\$_SERVER['{$arm}']) && \$_SERVER['{$arm}'] === 'on';

    if (!\$mine) {
        return \$pre;
    }

    \$body = base64_decode('{$png}');

    // download_url() streams to a file it created; an empty one is refused downstream
    // as "File is empty", so the bytes have to be put there.
    if (!empty(\$args['filename'])) {
        file_put_contents(\$args['filename'], \$body);
    }

    return array(
        'headers'  => array('content-type' => 'image/png'),
        'body'     => empty(\$args['filename']) ? \$body : '',
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies'  => array(),
        'filename' => isset(\$args['filename']) ? \$args['filename'] : null,
    );
}, 10, 3);
PHP;
    }
}
