<?php
/**
 * Every value a tool returns survives being written back (sprint 14d, G1).
 *
 * THE CLASS. A client reads a field and later sends it back - to change something beside
 * it, to copy it, or because it is echoing the whole object. If the read form is not the
 * write form, that ordinary act corrupts data, and nothing in a write-then-read test can see
 * it: the corruption appears only when what is written is what was READ. The title bug
 * (sprint 14, B-TITLE) was one instance; this class is the sweep of every field that some
 * tool returns and some tool accepts, measured on both Local sites before a line was
 * written - the table is in the sprint's implementer report:
 *
 *   post      title, content, excerpt, slug, status, date, author, featured_image, terms
 *             (get-post -> update-post)
 *   term      name (list-terms, get-post -> create-term, update-post's terms)
 *   menu      the item's own label and url (get-menu -> update-menu-item), the menu name
 *   comment   status (list-comments -> moderate-comment, list-comments' own filter)
 *   media     title and alt (get-media -> upload-media)
 *   code      a theme file's bytes (code-read -> code-write)
 *   meta      a post meta value (get-post-meta -> set-post-meta)
 *
 * WHAT "SURVIVES" MEANS, and it is two assertions, not one: the stored bytes read in
 * another process are identical before and after the write-back, AND the second read
 * returns identical bytes to the first. The fixtures carry `&`, a double quote, an
 * apostrophe, a backslash and `<` wherever the field can hold them - the characters every
 * earlier fixture in this suite happened not to contain.
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
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\WpCli;

final class RoundTripTest extends FixtureIntegrationTestCase
{
    /** The mu-plugin: code tools, this run's meta keys, and one fake download, for this run only. */
    private const SWITCHES = 'roundtrip';

    /** Sent to make the fake download answer. */
    private const ARM_HEADER = 'X-Wpmcp-Test-Roundtrip';

    /** The characters every earlier fixture lacked. */
    private const HARD = '& "quoted" Tom\'s A\\B x < y';

    private static function label(): string { return Fixtures::name('roundtrip'); }
    private static function login(): string { return Fixtures::name('rt-admin'); }
    private static function metaKey(): string { return Fixtures::name('rt-meta'); }
    private static function arrayKey(): string { return Fixtures::name('rt-meta-array'); }
    private static function themeFile(): string { return Fixtures::name('rt-file.css'); }

    private static int $userId = 0;
    private static string $token = '';

    /** @var list<int> */
    private static array $posts = [];
    /** @var list<array{0: string, 1: int}> taxonomy, id */
    private static array $terms = [];
    /** @var list<int> */
    private static array $menus = [];
    /** @var list<int> */
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

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
        self::$token  = Fixtures::mintToken('admin', self::label(), self::$userId);

        MuPlugin::drop(self::SWITCHES, self::switchesSource());
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::SWITCHES);

        foreach (self::$attachments as $id) {
            WpCli::tryEvaluate(sprintf('echo (int) (bool) wp_delete_attachment(%d, true);', $id));
        }
        foreach (self::$menus as $id) { Fixtures::deleteMenu($id); }
        foreach (self::$posts as $id) { Fixtures::deletePost($id); }
        foreach (self::$terms as [$taxonomy, $id]) {
            WpCli::tryRun(['term', 'delete', $taxonomy, (string) $id]);
        }
        if (Fixtures::themeFileExists(self::themeFile())) {
            Fixtures::deleteThemeFile(self::themeFile());
        }

        self::$attachments = [];
        self::$menus = [];
        self::$posts = [];
        self::$terms = [];

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * posts
     * ---------------------------------------------------------------- */

    /**
     * G1, posts. Every field get-post returns that update-post accepts, written back as
     * read, on three posts that each fail differently on the unfixed code: a published
     * post with a slug, terms, an author and a featured image; a draft nobody dated; and
     * a title wp-admin stored with tag-like text in it.
     *
     * @group sprint-14d
     */
    public function testEveryPostFieldWrittenBackAsReadStoresTheSameBytes(): void
    {
        // Categories named "Array" before, so the check below is about THIS write-back and
        // not about whatever the site already had.
        $arrays = self::termIdsNamed('category', 'Array');

        $image = Fixtures::createAttachment(
            Fixtures::name('rt-image'),
            self::$userId,
            Fixtures::name('rt-image.png')
        );
        self::$posts[] = $image;

        $tag = Fixtures::createTerm('post_tag', Fixtures::name('rt-tag ') . self::HARD);
        self::$terms[] = ['post_tag', $tag];

        $cases = [
            'a published post with every field set' => $this->newPost([
                'post_title'   => Fixtures::name('rt-published ') . self::HARD,
                'post_content' => '<p>' . self::HARD . '</p>',
                'post_excerpt' => self::HARD,
                'post_status'  => 'publish',
                'post_name'    => Fixtures::name('rt-slug-%d0%bf%d1%80'),
                'post_author'  => self::$userId,
            ]),
            'a draft nobody dated' => $this->newPost([
                'post_title'  => Fixtures::name('rt-floating ') . self::HARD,
                'post_status' => 'draft',
                'post_author' => self::$userId,
            ]),
            'a title wp-admin stored with tag-like text' => $this->newPost([
                'post_title'  => Fixtures::name('rt-tagged x<y z') . ' <b>',
                'post_status' => 'draft',
                'post_author' => self::$userId,
            ]),
        ];

        $first = array_key_first($cases);
        Fixtures::setPostTerms($cases[$first], 'post_tag', [$tag]);
        WpCli::evaluate(sprintf('echo (int) set_post_thumbnail(%d, %d);', $cases[$first], $image));

        foreach ($cases as $what => $id) {
            $rowBefore = self::row($id);
            $read      = $this->call('get-post', ['id' => $id])->data();

            $sent = [
                'id'             => $id,
                'title'          => $read['title'],
                'content'        => $read['content'],
                'excerpt'        => $read['excerpt'],
                'slug'           => $read['slug'],
                'status'         => $read['status'],
                'date'           => $read['date'],
                'author'         => $read['author']['id'],
                'featured_image' => $read['featured_image']['id'] ?? 0,
                // get-post's OWN shape - {id, name, slug} objects - which is what a client
                // echoing the object sends. Before the fix: a category named "Array".
                'terms'          => array_filter(
                    (array) $read['terms'],
                    static fn ($entries) => $entries !== []
                ),
            ];

            $written = $this->call('update-post', $sent)->data();

            self::assertSame(
                [],
                $written['changed'],
                "Writing {$what} back as read changed something: " . implode(', ', $written['changed'])
            );
            self::assertSame(
                $rowBefore,
                self::row($id),
                "Writing {$what} back as read changed the stored row."
            );

            $again = $this->call('get-post', ['id' => $id])->data();
            self::assertSame($read, $again, "{$what} reads back differently after the write-back.");
        }

        // list-posts serves the same title get-post does, for the post it was listed for.
        $listed = $this->call('list-posts', ['search' => Fixtures::name('rt-published'), 'status' => 'publish'])->items();
        self::assertSame(
            $this->call('get-post', ['id' => $cases[$first]])->data()['title'],
            $listed[0]['title'] ?? null,
            'list-posts and get-post disagree about one title.'
        );

        self::assertSame(
            $arrays,
            self::termIdsNamed('category', 'Array'),
            'Writing get-post\'s terms back created a term named "Array".'
        );
    }

    /* ------------------------------------------------------------------
     * term names
     * ---------------------------------------------------------------- */

    /**
     * G1, term names. Read as typed; written back through create-term and through
     * update-post's terms, the SAME term is found and its stored bytes do not move.
     *
     * @group sprint-14d
     */
    public function testATermNameReadIsTheNameTypedAndWritingItBackFindsTheSameTerm(): void
    {
        foreach (['category', 'post_tag'] as $taxonomy) {
            $name    = Fixtures::name('rt-term-' . $taxonomy . ' ') . self::HARD;
            $created = $this->call('create-term', ['taxonomy' => $taxonomy, 'name' => $name])->data();
            $termId  = (int) $created['id'];
            self::$terms[] = [$taxonomy, $termId];

            $stored = Fixtures::termField($termId, $taxonomy, 'name');
            self::assertStringContainsString('&amp;', $stored, 'The premise moved: core no longer escapes a term name.');

            $listed = $this->call('list-terms', [
                'taxonomy' => $taxonomy,
                'search'   => Fixtures::name('rt-term-' . $taxonomy),
            ])->items();

            self::assertCount(1, $listed, "list-terms found the {$taxonomy} fixture more or less than once.");
            self::assertSame($name, $listed[0]['name'], "list-terms returned a {$taxonomy} name nobody typed.");
            self::assertSame($name, $created['name'], 'create-term returned a name nobody typed.');

            // create-term with the name as read: the existing term, by id, and no second one.
            $again = $this->mcp(self::$token)->callTool('create-term', ['taxonomy' => $taxonomy, 'name' => $listed[0]['name']]);
            self::assertTrue($again->isError, "create-term made a second {$taxonomy} term from a name it had just read: " . $again->text);
            self::assertStringContainsString('term ' . $termId, $again->text, 'The refusal does not name the existing term.');
            self::assertSame([$termId], self::termIdsNamed($taxonomy, $stored), 'A duplicate term exists.');

            // update-post's terms, by the name as read.
            $post = $this->newPost(['post_title' => Fixtures::name('rt-term-carrier'), 'post_status' => 'draft', 'post_author' => self::$userId]);
            $this->call('update-post', ['id' => $post, 'terms' => [$taxonomy => [$listed[0]['name']]]]);

            $onPost = $this->call('get-post', ['id' => $post])->data()['terms'][$taxonomy];
            self::assertSame([$termId], array_column($onPost, 'id'), "update-post did not find the {$taxonomy} term by the name list-terms gave.");
            self::assertSame($name, $onPost[0]['name'], 'get-post returned a term name nobody typed.');
            self::assertSame($stored, Fixtures::termField($termId, $taxonomy, 'name'), 'Writing the name back moved its stored bytes.');
        }
    }

    /* ------------------------------------------------------------------
     * menus
     * ---------------------------------------------------------------- */

    /**
     * G1, menus. A label and url written back as get-menu read them keep their stored
     * bytes - the label we saved, and the `&#038;` wp-admin saves - and the menu's own
     * name reads as typed. The fixture menu is assigned to NO location, so nothing a
     * visitor sees changes while this runs.
     *
     * @group sprint-14d
     */
    public function testAMenuLabelAndUrlWrittenBackAsReadKeepTheirStoredBytes(): void
    {
        $menuName = Fixtures::name('rt-menu') . ' & friends';
        $menuId   = Fixtures::createMenu($menuName);
        self::$menus[] = $menuId;

        $page = $this->newPost(['post_title' => Fixtures::name('rt-page FDA & GMP'), 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::$userId]);

        $custom = $this->call('add-menu-item', [
            'menu_id' => $menuId,
            'type'    => 'custom',
            'url'     => 'https://example.com/?a=1&b=2',
            'title'   => Fixtures::name('rt-label ') . self::HARD,
        ])->data()['id'];

        // The form wp-admin stored on the stress site: `&#038;` for `&` in an own label.
        $admin = Fixtures::createMenuItem($menuId, [
            'menu-item-type'      => 'post_type',
            'menu-item-object'    => 'page',
            'menu-item-object-id' => $page,
            'menu-item-title'     => Fixtures::name('rt-admin-label FDA &#038; GMP'),
        ]);

        // A category item with NO label of its own: its label is the term's name, which core
        // hands back escaped (`&amp;`) - round 2, should-fix 4.
        $catName = Fixtures::name('rt-menu-cat') . ' Arts & Crafts';
        $catId   = (int) $this->call('create-term', ['taxonomy' => 'category', 'name' => $catName])->data()['id'];
        self::$terms[] = ['category', $catId];
        $catItem = Fixtures::createMenuItem($menuId, [
            'menu-item-type'      => 'taxonomy',
            'menu-item-object'    => 'category',
            'menu-item-object-id' => $catId,
            'menu-item-title'     => '',
        ]);

        $rowsBefore = self::labelsAndUrls($menuId);
        self::assertSame('', $rowsBefore[$catItem]['title'], 'The premise moved: the category item has a label of its own.');

        // Round 3, should-fix 2: add-menu-item given the term's name as read makes an item
        // that FOLLOWS the term (no own label), as update-menu-item already does.
        $added = (int) $this->call('add-menu-item', [
            'menu_id' => $menuId, 'type' => 'category', 'object_id' => $catId, 'title' => $catName,
        ])->data()['id'];
        self::assertSame('', self::labelsAndUrls($menuId)[$added]['title'], 'add-menu-item stored the term name as an own label.');
        $rowsBefore = self::labelsAndUrls($menuId);
        self::assertStringContainsString('&#038;', $rowsBefore[$admin]['title'], 'The premise moved: the wp-admin label is not stored with &#038;.');

        $menu = $this->call('get-menu', ['id' => $menuId])->data();
        self::assertSame($menuName, $menu['name'], 'get-menu returned a menu name nobody typed.');

        $items = [];
        foreach ($menu['items'] as $item) { $items[(int) $item['id']] = $item; }

        self::assertSame(Fixtures::name('rt-admin-label FDA & GMP'), $items[$admin]['title'], 'A wp-admin label came back with an entity nobody typed.');
        self::assertSame(Fixtures::name('rt-label ') . self::HARD, $items[$custom]['title']);

        $this->call('update-menu-item', ['id' => $custom, 'title' => $items[$custom]['title'], 'url' => $items[$custom]['url']]);
        $this->call('update-menu-item', ['id' => $admin, 'title' => $items[$admin]['title']]);
        self::assertSame($catName, $items[$catItem]['title'], 'A category item fallback label came back escaped.');
        $this->call('update-menu-item', ['id' => $catItem, 'title' => $items[$catItem]['title']]);

        self::assertSame($rowsBefore, self::labelsAndUrls($menuId), 'Writing a label or url back as read changed its stored bytes.');

        $again = [];
        foreach ($this->call('get-menu', ['id' => $menuId])->data()['items'] as $item) { $again[(int) $item['id']] = $item; }
        self::assertSame($items[$custom]['title'], $again[$custom]['title']);
        self::assertSame($items[$admin]['title'], $again[$admin]['title']);
        self::assertSame($items[$custom]['url'], $again[$custom]['url']);
        self::assertSame($items[$catItem]['title'], $again[$catItem]['title']);

        $listed = array_column($this->call('list-menus', [])->data()['menus'], 'name', 'id');
        self::assertSame($menuName, $listed[$menuId] ?? null, 'list-menus returned a menu name nobody typed.');
    }

    /* ------------------------------------------------------------------
     * comments
     * ---------------------------------------------------------------- */

    /**
     * G1, comments. The status list-comments reports is accepted back by moderate-comment
     * and by list-comments' own filter.
     *
     * @group sprint-14d
     */
    public function testACommentStatusReadIsAcceptedBack(): void
    {
        $post    = $this->newPost(['post_title' => Fixtures::name('rt-comments'), 'post_status' => 'publish', 'post_author' => self::$userId, 'comment_status' => 'open']);
        $comment = Fixtures::createComment($post, Fixtures::name('rt-comment'), true);

        $read = $this->call('list-comments', ['post' => $post])->items();
        self::assertSame('approved', $read[0]['status']);

        $moderated = $this->call('moderate-comment', ['id' => $comment, 'action' => $read[0]['status']])->data();
        self::assertSame('approved', $moderated['status']);

        $this->call('moderate-comment', ['id' => $comment, 'action' => 'unapprove']);
        $held = $this->call('list-comments', ['post' => $post, 'status' => 'unapproved'])->items();
        self::assertSame([$comment], array_column($held, 'id'), 'list-comments did not accept its own status word as a filter.');

        $back = $this->call('moderate-comment', ['id' => $comment, 'action' => $held[0]['status']])->data();
        self::assertSame('unapproved', $back['status']);
    }

    /* ------------------------------------------------------------------
     * media
     * ---------------------------------------------------------------- */

    /**
     * G1, media. A title and alt text read from get-media and uploaded again are stored
     * and read back identically. Nothing leaves this machine: the fake download answers.
     *
     * @group sprint-14d
     */
    public function testAMediaTitleAndAltReadAndUploadedAgainAreIdentical(): void
    {
        $first = $this->upload(Fixtures::name('rt-media ') . self::HARD, 'alt ' . self::HARD);
        $read  = $this->call('get-media', ['id' => $first])->data();

        $second = $this->upload($read['title'], $read['alt']);
        $again  = $this->call('get-media', ['id' => $second])->data();

        self::assertSame($read['title'], $again['title'], 'A media title written back as read came back different.');
        self::assertSame($read['alt'], $again['alt'], 'Alt text written back as read came back different.');
        self::assertSame(
            Fixtures::postField($first, 'post_title'),
            Fixtures::postField($second, 'post_title'),
            'The stored titles differ.'
        );
    }

    /* ------------------------------------------------------------------
     * code
     * ---------------------------------------------------------------- */

    /**
     * G1, code. A theme file read with code-read and written back with code-write keeps
     * every byte: CRLF, a tab, a BOM-free UTF-8 character, quotes, a backslash.
     *
     * @group sprint-14d
     */
    public function testAThemeFileReadAndWrittenBackKeepsEveryByte(): void
    {
        $bytes = "/* " . self::HARD . " */\r\n.a::after {\tcontent: \"\\201C\"; }\n/* \u{2019} */\n";
        Fixtures::writeThemeFile(self::themeFile(), $bytes);

        $read = $this->call('code-read', ['path' => self::themeFile()])->data();
        self::assertSame($bytes, $read['content'], 'code-read did not return the bytes on disk.');

        $this->call('code-write', ['path' => self::themeFile(), 'content' => $read['content']]);

        self::assertSame($bytes, Fixtures::readThemeFile(self::themeFile()), 'code-write of what code-read returned changed the file.');
        self::assertSame($read['content'], $this->call('code-read', ['path' => self::themeFile()])->data()['content']);
    }

    /* ------------------------------------------------------------------
     * post meta
     * ---------------------------------------------------------------- */

    /**
     * G1, meta. A scalar and a list of rows written back as get-post-meta read them keep
     * their rows; a key holding ONE serialised array is refused rather than split into
     * one row per element.
     *
     * @group sprint-14d
     */
    public function testPostMetaWrittenBackAsReadKeepsItsRowsOrIsRefused(): void
    {
        $post = $this->newPost(['post_title' => Fixtures::name('rt-meta-post'), 'post_status' => 'draft', 'post_author' => self::$userId]);

        foreach ([[self::HARD], ['one ' . self::HARD, 'two']] as $rows) {
            WpCli::evaluate(sprintf(
                'delete_post_meta(%1$d, %2$s); foreach (json_decode(base64_decode(%3$s), true) as $v) { add_post_meta(%1$d, %2$s, wp_slash($v)); } echo "ok";',
                $post,
                var_export(self::metaKey(), true),
                var_export(base64_encode((string) json_encode($rows)), true)
            ));

            $before = self::rawMetaRows($post, self::metaKey());
            $read   = $this->call('get-post-meta', ['id' => $post, 'key' => self::metaKey()])->data()['meta'][self::metaKey()];

            $this->call('set-post-meta', ['id' => $post, 'key' => self::metaKey(), 'value' => $read]);

            self::assertSame($before, self::rawMetaRows($post, self::metaKey()), 'Meta written back as read changed its rows.');
        }

        WpCli::evaluate(sprintf(
            'delete_post_meta(%1$d, %2$s); add_post_meta(%1$d, %2$s, array("a", "b")); echo "ok";',
            $post,
            var_export(self::arrayKey(), true)
        ));
        $before = self::rawMetaRows($post, self::arrayKey());
        $read   = $this->call('get-post-meta', ['id' => $post, 'key' => self::arrayKey()])->data()['meta'][self::arrayKey()];
        self::assertSame(['a', 'b'], $read);

        $refused = $this->mcp(self::$token)->callTool('set-post-meta', ['id' => $post, 'key' => self::arrayKey(), 'value' => $read]);
        self::assertTrue($refused->isError, 'A list written back onto one serialised array was accepted: ' . $refused->text);
        self::assertSame($before, self::rawMetaRows($post, self::arrayKey()), 'The refused write changed the rows.');
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    private function newPost(array $fields): int
    {
        $id = Fixtures::createPostExact($fields);
        self::$posts[] = $id;

        return $id;
    }

    private function call(string $tool, array $arguments): ToolResult
    {
        $result = $this->mcp(self::$token)->callTool($tool, $arguments);

        if ($result->isError) {
            throw new RuntimeException("{$tool} failed: " . $result->text);
        }

        return $result;
    }

    private function upload(string $title, string $alt): int
    {
        $result = $this->mcp(self::$token)->callTool('upload-media', [
            'source_url' => 'https://example.invalid/' . Fixtures::name('rt-upload.png'),
            'filename'   => Fixtures::name('rt-upload.png'),
            'title'      => $title,
            'alt'        => $alt,
        ], [self::ARM_HEADER => 'on']);

        if ($result->isError) {
            throw new RuntimeException('upload-media failed: ' . $result->text);
        }

        $id = (int) $result->data()['id'];
        self::$attachments[] = $id;

        return $id;
    }

    /**
     * Every column update-post can move, plus terms, thumbnail and revision count, read in
     * another process - base64 JSON so no byte is lost on stdout.
     */
    private static function row(int $id): array
    {
        $out = WpCli::evaluate(sprintf(
            'clean_post_cache(%1$d); $p = get_post(%1$d); $t = array();'
            . ' foreach (get_object_taxonomies($p->post_type) as $x) { $ids = wp_get_object_terms($p->ID, $x, array("fields" => "ids")); $ids = array_map("intval", (array) $ids); sort($ids); $t[$x] = $ids; }'
            . ' echo "\n", base64_encode(wp_json_encode(array($p->post_title, $p->post_content, $p->post_excerpt, $p->post_name,'
            . ' $p->post_status, $p->post_date, $p->post_date_gmt, $p->post_modified, (int) $p->post_author,'
            . ' (int) get_post_thumbnail_id($p), $t, count(wp_get_post_revisions($p->ID, array("fields" => "ids"))))));',
            $id
        ));

        return self::lastJson($out);
    }

    /**
     * The meta_value column of every row under $key, as MySQL holds it - serialised text
     * and all - so "the rows did not change" is a statement about bytes, not about what
     * get_post_meta() makes of them.
     *
     * @return list<string>
     */
    private static function rawMetaRows(int $postId, string $key): array
    {
        $out = WpCli::evaluate(sprintf(
            'global $wpdb; echo "\n", base64_encode(wp_json_encode($wpdb->get_col($wpdb->prepare('
            . '"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %%d AND meta_key = %%s ORDER BY meta_id", %d, %s))));',
            $postId,
            var_export($key, true)
        ));

        return self::lastJson($out);
    }

    /** @return array<int, array{title: string, url: string}> */
    private static function labelsAndUrls(int $menuId): array
    {
        $out = [];
        foreach (Fixtures::menuItemRows($menuId) as $row) {
            $out[(int) $row['id']] = ['title' => $row['title'], 'url' => $row['url']];
        }
        ksort($out);

        return $out;
    }

    /** @return list<int> ids of the terms in $taxonomy whose STORED name is exactly $stored */
    private static function termIdsNamed(string $taxonomy, string $stored): array
    {
        $out = WpCli::evaluate(sprintf(
            'global $wpdb; echo "\n", base64_encode(wp_json_encode(array_map("intval", $wpdb->get_col($wpdb->prepare('
            . '"SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id'
            . ' WHERE tt.taxonomy = %%s AND BINARY t.name = %%s ORDER BY t.term_id", %s, base64_decode(%s))))));',
            var_export($taxonomy, true),
            var_export(base64_encode($stored), true)
        ));

        return self::lastJson($out);
    }

    private static function lastJson(string $out): array
    {
        $lines   = preg_split('/\r?\n/', trim($out));
        $decoded = json_decode((string) base64_decode((string) end($lines), true), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Could not read a fixture row: ' . substr($out, 0, 300));
        }

        return $decoded;
    }

    /**
     * For THIS RUN'S requests only: the code tools on, this run's two meta keys on the
     * allow-list, and - when armed - a fake download for upload-media. Every other request
     * to the site, another runner's included, sees nothing.
     */
    private static function switchesSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $arm    = 'HTTP_' . strtoupper(str_replace('-', '_', self::ARM_HEADER));
        $keys   = var_export([self::metaKey(), self::arrayKey()], true);
        $png    = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return <<<PHP
/**
 * wp-mcp sprint-14d round-trip fixture for run {$run}. Dropped and removed by
 * tests/integration/RoundTripTest.php. IT ANSWERS ONLY FOR THIS RUN'S REQUESTS. If you are
 * reading this on a live site, the run that wrote it crashed; deleting the file is safe.
 */
\$wpmcpRtMine = static function () {
    return isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '{$run}';
};
add_filter('pre_option_wpmcp_code_enabled', static function (\$pre) use (\$wpmcpRtMine) {
    return \$wpmcpRtMine() ? 1 : \$pre;
});
add_filter('pre_option_wpmcp_meta_keys', static function (\$pre) use (\$wpmcpRtMine) {
    return \$wpmcpRtMine() ? {$keys} : \$pre;
});
add_filter('pre_http_request', static function (\$pre, \$args, \$url) use (\$wpmcpRtMine) {
    if (!\$wpmcpRtMine() || !isset(\$_SERVER['{$arm}']) || \$_SERVER['{$arm}'] !== 'on') {
        return \$pre;
    }
    \$body = base64_decode('{$png}');
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
