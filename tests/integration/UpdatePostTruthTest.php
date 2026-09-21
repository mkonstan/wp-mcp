<?php
/**
 * update-post tells the truth about what it changed (sprint 14d, G2).
 *
 * THREE CLAIMS, EACH MEASURED ON BOTH SITES BEFORE IT WAS WRITTEN:
 *
 *   1. `changed` names exactly the fields whose stored value differs afterwards. It used to
 *      name every field SENT, so an identical title came back as `changed: ["title"]`
 *      (cold client #4), and core's own moves were caught only for the two fields somebody
 *      had thought to compare.
 *   2. The revision sentence. MEASURED: the first write that CHANGES something on a post
 *      with no revisions adds one
 *      revision whatever it changes - core's post_updated handler does it on its own - and
 *      an update of an identical title used to create it too, so "no text change, no
 *      revision" was false on a live site (seosemia.net, 2026-09-18). An update that
 *      changes nothing now writes nothing at all.
 *   3. The re-slug rule, stated as a rule: on a status change leaving draft or pending, a
 *      slug-less post gets a slug from its title and a slug another post holds gets a -N
 *      suffix; trashing appends __trashed; moves among published statuses keep the slug.
 *      The full transition table (5 x 6 statuses, slug-less / own slug / taken slug) is in
 *      the implementer report; this asserts one case of each branch and one of the rest.
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

final class UpdatePostTruthTest extends FixtureIntegrationTestCase
{
    private const ZERO_DATE = '0000-00-00 00:00:00';

    /** The mu-plugin that counts save_post on this run's requests, per post, in post meta. */
    private const SAVES = 'uptruth-saves';

    /** The meta key the counter writes. Protected (leading underscore), so no tool reads it. */
    private const SAVES_KEY = '_wpmcp_test_saves';

    /** Sent to make the fixture refuse this one save (wp_insert_post_empty_content). */
    private const FAIL_HEADER = 'X-Wpmcp-Test-Refuse-Save';

    private static function label(): string { return Fixtures::name('uptruth'); }
    private static function readLabel(): string { return Fixtures::name('uptruth-read'); }
    private static function login(): string { return Fixtures::name('uptruth-admin'); }

    private static int $userId = 0;
    private static string $token = '';
    private static string $readToken = '';

    /** @var list<int> */
    private static array $posts = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$userId    = Fixtures::createUser(self::login(), 'administrator');
        self::$token     = Fixtures::mintToken('admin', self::label(), self::$userId);
        self::$readToken = Fixtures::mintToken('read', self::readLabel(), self::$userId);

        MuPlugin::drop(self::SAVES, self::savesSource());
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::SAVES);

        foreach (self::$posts as $id) { Fixtures::deletePost($id); }
        self::$posts = [];

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteTokensLabelled(self::readLabel());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /**
     * G2. An update sending only unchanged values writes nothing: `changed` is empty, no
     * revision appears on a post that had none, the modified date and a floating draft's
     * date stay where they were.
     *
     * @group sprint-14d
     */
    public function testAnUpdateOfUnchangedValuesWritesNothingAndSaysSo(): void
    {
        $cases = [
            'a published post with no revisions' => $this->newPost([
                'post_title' => Fixtures::name('ut-same ') . '& "q"', 'post_status' => 'publish', 'post_content' => 'body',
            ]),
            'a draft nobody dated' => $this->newPost([
                'post_title' => Fixtures::name('ut-floating'), 'post_status' => 'draft', 'post_content' => 'body',
            ]),
        ];

        foreach ($cases as $what => $id) {
            // The modified time is pushed into the past so "it did not move" cannot pass
            // by landing in the same second as the create.
            self::setModified($id, '2020-01-01 00:00:00');
            $before = self::row($id);
            self::assertSame(0, $before['revisions'], "The fixture {$what} already has revisions.");

            $data = $this->update($id, [
                'title'   => $before['post_title'],
                'content' => $before['post_content'],
                'status'  => $before['post_status'],
                'date'    => str_replace(' ', 'T', $before['post_date']),
            ]);

            self::assertSame([], $data['changed'], "An unchanged update of {$what} reported changes: " . implode(', ', $data['changed']));
            self::assertSame($before, self::row($id), "An unchanged update of {$what} wrote to the row.");
        }
    }

    /**
     * G2. `changed` names a field exactly when its stored value differs: sent-and-equal
     * fields are left out, and every field it names is shown to differ by reading the row
     * in another process before and after. A date sent back unchanged beside a real change
     * is kept, not re-dated.
     *
     * @group sprint-14d
     */
    public function testChangedNamesExactlyTheFieldsThatDiffer(): void
    {
        $id = $this->newPost([
            'post_title' => Fixtures::name('ut-mixed'), 'post_status' => 'draft', 'post_content' => 'before',
        ]);
        WpCli::evaluate(sprintf(
            'global $wpdb; $wpdb->query($wpdb->prepare("UPDATE $wpdb->posts SET post_date = %%s WHERE ID = %%d", "2020-01-01 00:00:00", %d)); clean_post_cache(%d); echo "ok";',
            $id,
            $id
        ));

        $before = self::row($id);
        self::assertSame(self::ZERO_DATE, $before['post_date_gmt'], 'The fixture is not a floating draft.');

        $data = $this->update($id, [
            'title'   => $before['post_title'],
            'status'  => 'draft',
            'content' => 'after',
            'date'    => '2020-01-01T00:00:00',
        ]);
        $after = self::row($id);

        self::assertSame(['content'], $data['changed'], 'changed: ' . implode(', ', $data['changed']));

        $columns = [
            'title' => ['post_title'], 'content' => ['post_content'], 'status' => ['post_status'],
            'excerpt' => ['post_excerpt'], 'slug' => ['post_name'], 'date' => ['post_date', 'post_date_gmt'],
            'author' => ['post_author'], 'featured_image' => ['thumbnail'], 'terms' => ['terms'],
        ];
        foreach ($columns as $field => $keys) {
            $moved = false;
            foreach ($keys as $key) { $moved = $moved || $before[$key] !== $after[$key]; }

            self::assertSame(
                $moved,
                in_array($field, $data['changed'], true),
                "`changed` and the row disagree about {$field}."
            );
        }

        self::assertSame('2020-01-01 00:00:00', $after['post_date'], 'A date sent back unchanged was re-dated.');
        self::assertSame(self::ZERO_DATE, $after['post_date_gmt'], 'A date sent back unchanged made the draft a dated one.');
    }

    /**
     * G2. The revision behaviour the description states is the measured one: the first
     * write to a revision-less post adds exactly one revision whatever it changes; after
     * that a non-text change adds none and a text change adds one, the newest holding the
     * new text and the one below it the pre-edit text.
     *
     * @group sprint-14d
     */
    public function testTheRevisionBehaviourIsTheOneDescribed(): void
    {
        $id = $this->newPost([
            'post_title' => Fixtures::name('ut-rev'), 'post_status' => 'draft', 'post_content' => 'text-0',
        ]);
        self::assertSame(0, Fixtures::revisionCount($id));

        $this->update($id, ['status' => 'pending']);
        self::assertSame(1, Fixtures::revisionCount($id), 'The first write to a revision-less post, a status change, did not add exactly one revision.');

        $this->update($id, ['status' => 'draft']);
        self::assertSame(1, Fixtures::revisionCount($id), 'A status change on a revisioned post added a revision.');

        $this->update($id, ['content' => 'text-1']);
        self::assertSame(2, Fixtures::revisionCount($id), 'A text change did not add exactly one revision.');

        $items = $this->mcp(self::$token)->callTool('list-revisions', ['id' => $id])->items();
        self::assertSame('text-1', self::revisionContent((int) $items[0]['id']), 'The newest revision does not hold the new text.');
        self::assertSame('text-0', self::revisionContent((int) $items[1]['id']), 'The revision below does not hold the pre-edit text.');

        // A text change as the FIRST write: two, the pre-edit text below the new one.
        $fresh = $this->newPost([
            'post_title' => Fixtures::name('ut-rev-fresh'), 'post_status' => 'publish', 'post_content' => 'old',
        ]);
        $this->update($fresh, ['content' => 'new']);
        self::assertSame(2, Fixtures::revisionCount($fresh), 'A first-write text change did not leave the pre-edit copy and the new one.');
    }

    /**
     * G2. The re-slug rule, one case per branch, and `changed` names slug exactly when it
     * moved.
     *
     * @group sprint-14d
     */
    public function testAStatusChangeReSlugsByTheStatedRule(): void
    {
        // Leaving draft: a slug-less post is slugged from its title - for `future` too,
        // which the old description did not name.
        $slugless = $this->newPost(['post_title' => Fixtures::name('ut-slugless sched'), 'post_status' => 'draft']);
        $data = $this->update($slugless, ['status' => 'future', 'date' => '2031-01-01T10:00:00']);
        self::assertNotSame('', Fixtures::postField($slugless, 'post_name'), 'Scheduling did not derive a slug.');
        self::assertContains('slug', $data['changed']);

        // Leaving draft: a slug another post holds gets a numeric suffix.
        $taken = Fixtures::name('ut-taken');
        $this->newPost(['post_title' => Fixtures::name('ut-holder'), 'post_status' => 'publish', 'post_name' => $taken]);
        $draft = $this->newPost(['post_title' => Fixtures::name('ut-taker'), 'post_status' => 'draft', 'post_name' => $taken]);
        $data  = $this->update($draft, ['status' => 'private']);
        self::assertMatchesRegularExpression('/^' . preg_quote($taken, '/') . '-\d+$/', Fixtures::postField($draft, 'post_name'));
        self::assertContains('slug', $data['changed']);

        // Draft to pending: no slug derived.
        $pending = $this->newPost(['post_title' => Fixtures::name('ut-slugless pend'), 'post_status' => 'draft']);
        $data    = $this->update($pending, ['status' => 'pending']);
        self::assertSame('', Fixtures::postField($pending, 'post_name'));
        self::assertNotContains('slug', $data['changed']);

        // Among published statuses the slug stays.
        $published = $this->newPost(['post_title' => Fixtures::name('ut-pub'), 'post_status' => 'publish', 'post_name' => Fixtures::name('ut-pub-slug')]);
        $data      = $this->update($published, ['status' => 'private']);
        self::assertSame(Fixtures::name('ut-pub-slug'), Fixtures::postField($published, 'post_name'));
        self::assertNotContains('slug', $data['changed']);

        // Trashing appends __trashed.
        $data = $this->update($published, ['status' => 'trash']);
        self::assertSame(Fixtures::name('ut-pub-slug') . '__trashed', Fixtures::postField($published, 'post_name'));
        self::assertContains('slug', $data['changed']);
    }

    /**
     * G2, the prose. The served descriptions say what was measured - update-post to an
     * admin-scope token, list-revisions to a READ-scope one, which never sees update-post
     * or restore-revision and so must be told there which revision is which.
     *
     * @group sprint-14d
     */
    public function testTheServedDescriptionsStateTheMeasuredBehaviour(): void
    {
        $update = $this->servedDescription(self::$token, 'update-post');

        self::assertStringNotContainsString('no text change, no revision', $update, 'The false revision claim is back.');
        foreach ([
            'an update that changes nothing writes nothing',
            'first write that CHANGES something to a post with none',
            '?p=ID for a draft, pending, future or trashed post',
            'NEWEST revision',
            'pre-edit',
            'every field whose stored value now differs',
            'leaving draft or pending',
            '-N suffix',
            '__trashed',
            're-dates an undated draft',
        ] as $phrase) {
            self::assertStringContainsString($phrase, $update, "update-post no longer says: {$phrase}");
        }

        $list = $this->servedDescription(self::$readToken, 'list-revisions');
        self::assertStringContainsString('CURRENT', $list, 'list-revisions does not say the newest revision holds the current text.');
        self::assertStringContainsString('admin-scope token can restore', $list, 'list-revisions offers a read-scope token a restore it cannot make.');
        self::assertStringNotContainsString('read one with get-revision, restore one with restore-revision', $list, 'The old sentence, which offered every token a restore, is back.');
    }

    /**
     * Round 2, should-fix 1. A terms-only or featured-image-only update is a real save:
     * `modified` moves and save_post fires, as it did at eb75223 - a cache or search plugin
     * listening for saves must hear of it. A floating draft is not re-dated by it.
     *
     * @group sprint-14d
     */
    public function testATermsOnlyOrImageOnlyUpdateIsARealSave(): void
    {
        $tag   = Fixtures::createTerm('post_tag', Fixtures::name('ut-save-tag'));
        $image = Fixtures::createAttachment(Fixtures::name('ut-save-image'), self::$userId, Fixtures::name('ut-save-image.png'));
        self::$posts[] = $image;

        foreach (['terms' => ['terms' => ['post_tag' => [$tag]]], 'featured_image' => ['featured_image' => $image]] as $field => $sent) {
            $id = $this->newPost(['post_title' => Fixtures::name('ut-save-' . $field), 'post_status' => 'draft']);
            self::setModified($id, '2020-01-01 00:00:00');
            $before = self::row($id);

            $data  = $this->update($id, $sent);
            $after = self::row($id);

            self::assertSame([$field], $data['changed'], "{$field}-only update: " . implode(', ', $data['changed']));
            self::assertNotSame($before['post_modified'], $after['post_modified'], "A {$field}-only update did not move `modified`.");
            self::assertSame('1', self::saves($id), "A {$field}-only update did not fire save_post exactly once.");
            self::assertSame($before['post_date'], $after['post_date'], "A {$field}-only update re-dated a floating draft.");
            self::assertSame(self::ZERO_DATE, $after['post_date_gmt']);
        }

        WpCli::tryRun(['term', 'delete', 'post_tag', (string) $tag]);

        // And an update that changes nothing still saves nothing.
        $id = $this->newPost(['post_title' => Fixtures::name('ut-save-none'), 'post_status' => 'draft']);
        $this->update($id, ['title' => Fixtures::name('ut-save-none')]);
        self::assertSame('', self::saves($id), 'An update that changed nothing fired save_post.');
    }

    /**
     * Round 2, should-fix 2. `terms` REPLACES, so an empty list clears the taxonomy and
     * `changed` says so - except that WordPress gives a `post` left with no category its
     * default category (wp_insert_post: "'post' requires at least one category").
     *
     * @group sprint-14d
     */
    public function testAnEmptyTermsListClearsTheTaxonomy(): void
    {
        $tag = Fixtures::createTerm('post_tag', Fixtures::name('ut-clear-tag'));
        $cat = Fixtures::createTerm('category', Fixtures::name('ut-clear-cat'));
        $id  = $this->newPost(['post_title' => Fixtures::name('ut-clear'), 'post_status' => 'draft']);
        Fixtures::setPostTerms($id, 'post_tag', [$tag]);
        Fixtures::setPostTerms($id, 'category', [$cat]);

        $data = $this->update($id, ['terms' => ['post_tag' => []]]);
        self::assertSame([], self::row($id)['terms']['post_tag'], 'An empty post_tag list left the tags on the post.');
        self::assertSame(['terms'], $data['changed']);

        $default = (int) trim(WpCli::evaluate('echo (int) get_option("default_category");'));
        $data    = $this->update($id, ['terms' => ['category' => []]]);
        self::assertSame([$default], self::row($id)['terms']['category'], 'Clearing category did not leave exactly the default category.');
        self::assertSame(['terms'], $data['changed']);

        WpCli::tryRun(['term', 'delete', 'post_tag', (string) $tag]);
        WpCli::tryRun(['term', 'delete', 'category', (string) $cat]);
    }

    /**
     * Round 3, the blocker. Every field of update-post's result is read after the write
     * it reports on: publishing a draft answers the published permalink, not the draft's
     * `?p=N`, and a slug change answers the new link. Compared with get_permalink() read in
     * another process.
     *
     * @group sprint-14d
     */
    public function testTheResultIsReadAfterTheWrite(): void
    {
        $id   = $this->newPost(['post_title' => Fixtures::name('ut-link'), 'post_status' => 'draft']);
        $data = $this->update($id, ['status' => 'publish']);

        // The SCHEME is not compared: wp-cli has no HTTPS request, so home_url() answers http
        // there while the tool, called over https, answers https (KB 0.8) - measured on
        // jaygroup. What this asserts is the path: pretty, not the draft's `?p=N`.
        self::assertSame(self::unscheme(self::permalink($id)), self::unscheme($data['link']), 'Publishing a draft answered a link that is not its permalink now.');
        self::assertStringNotContainsString('?p=', $data['link'], 'Publishing a draft answered the draft link.');

        $data = $this->update($id, ['slug' => Fixtures::name('ut-link-renamed')]);
        self::assertSame(self::unscheme(self::permalink($id)), self::unscheme($data['link']), 'A slug change answered the old link.');
        self::assertStringContainsString(Fixtures::name('ut-link-renamed'), $data['link']);
    }

    /**
     * Round 3, should-fix 1. A save WordPress refuses leaves nothing half-written: the terms
     * and the featured image sent with it are not applied. The refusal is forced by a
     * fixture filter (wp_insert_post_empty_content) armed by one header, this run only.
     *
     * @group sprint-14d
     */
    public function testARefusedSaveLeavesTheTermsAndImageAlone(): void
    {
        $keep  = Fixtures::createTerm('post_tag', Fixtures::name('ut-fail-keep'));
        $other = Fixtures::createTerm('post_tag', Fixtures::name('ut-fail-other'));
        $image = Fixtures::createAttachment(Fixtures::name('ut-fail-image'), self::$userId, Fixtures::name('ut-fail-image.png'));
        self::$posts[] = $image;

        $id = $this->newPost(['post_title' => Fixtures::name('ut-fail'), 'post_status' => 'draft', 'post_content' => 'body']);
        Fixtures::setPostTerms($id, 'post_tag', [$keep]);
        $before = self::row($id);

        $result = $this->mcp(self::$token)->callTool('update-post', [
            'id'             => $id,
            'title'          => Fixtures::name('ut-fail-renamed'),
            'terms'          => ['post_tag' => [$other]],
            'featured_image' => $image,
        ], [self::FAIL_HEADER => 'on']);

        self::assertTrue($result->isError, 'The forced refusal did not happen, so this test measured nothing: ' . $result->text);
        self::assertSame($before, self::row($id), 'A refused save left part of the update written.');

        WpCli::tryRun(['term', 'delete', 'post_tag', (string) $keep]);
        WpCli::tryRun(['term', 'delete', 'post_tag', (string) $other]);
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    private function newPost(array $fields): int
    {
        $id = Fixtures::createPostExact(array_merge(['post_author' => self::$userId, 'post_content' => ''], $fields));
        self::$posts[] = $id;

        return $id;
    }

    private function update(int $id, array $arguments): array
    {
        $result = $this->mcp(self::$token)->callTool('update-post', array_merge(['id' => $id], $arguments));

        if ($result->isError) {
            throw new RuntimeException('update-post failed: ' . $result->text);
        }

        return $result->data();
    }

    private function revisionContent(int $revisionId): string
    {
        return (string) $this->mcp(self::$token)->callTool('get-revision', ['revision_id' => $revisionId])->data()['content'];
    }

    private static function setModified(int $id, string $when): void
    {
        WpCli::evaluate(sprintf(
            'global $wpdb; $wpdb->query($wpdb->prepare("UPDATE $wpdb->posts SET post_modified = %%s, post_modified_gmt = %%s WHERE ID = %%d", %s, %s, %d)); clean_post_cache(%d); echo "ok";',
            var_export($when, true),
            var_export($when, true),
            $id,
            $id
        ));
    }

    /** The row as another process reads it: the columns update-post can move, terms, thumbnail, revisions. */
    private static function row(int $id): array
    {
        $out = WpCli::evaluate(sprintf(
            'clean_post_cache(%1$d); $p = get_post(%1$d); $t = array();'
            . ' foreach (get_object_taxonomies($p->post_type) as $x) { $ids = wp_get_object_terms($p->ID, $x, array("fields" => "ids")); $ids = array_map("intval", (array) $ids); sort($ids); $t[$x] = $ids; }'
            . ' echo "\n", base64_encode(wp_json_encode(array("post_title" => $p->post_title, "post_content" => $p->post_content,'
            . ' "post_excerpt" => $p->post_excerpt, "post_name" => $p->post_name, "post_status" => $p->post_status,'
            . ' "post_date" => $p->post_date, "post_date_gmt" => $p->post_date_gmt, "post_modified" => $p->post_modified,'
            . ' "post_author" => (int) $p->post_author, "thumbnail" => (int) get_post_thumbnail_id($p), "terms" => $t,'
            . ' "revisions" => count(wp_get_post_revisions($p->ID, array("fields" => "ids"))))));',
            $id
        ));

        $lines   = preg_split('/\r?\n/', trim($out));
        $decoded = json_decode((string) base64_decode((string) end($lines), true), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Could not read post ' . $id . ': ' . substr($out, 0, 300));
        }

        return $decoded;
    }

    private static function unscheme(string $url): string
    {
        return (string) preg_replace('#^https?://#', '', $url);
    }

    /** The permalink as another process reads it now. */
    private static function permalink(int $id): string
    {
        $lines = preg_split('/\r?\n/', trim(WpCli::evaluate(sprintf('clean_post_cache(%1$d); echo "\n", get_permalink(%1$d);', $id))));

        return (string) end($lines);
    }

    /** How many times save_post fired for $id in this run's requests ('' for never). */
    private static function saves(int $id): string
    {
        return trim(WpCli::evaluate(sprintf('echo (string) get_post_meta(%d, %s, true);', $id, var_export(self::SAVES_KEY, true))));
    }

    /** save_post, counted per post in post meta, for THIS RUN'S requests only. */
    private static function savesSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $key    = self::SAVES_KEY;
        $fail   = 'HTTP_' . strtoupper(str_replace('-', '_', self::FAIL_HEADER));

        return <<<PHP
/**
 * wp-mcp sprint-14d save_post counter for run {$run}. Dropped and removed by
 * tests/integration/UpdatePostTruthTest.php. Counts only this run's requests.
 */
add_action('save_post', static function (\$postId) {
    if (!isset(\$_SERVER['{$header}']) || \$_SERVER['{$header}'] !== '{$run}') { return; }
    if (wp_is_post_revision(\$postId)) { return; }
    update_post_meta(\$postId, '{$key}', (int) get_post_meta(\$postId, '{$key}', true) + 1);
}, 10, 1);
add_filter('wp_insert_post_empty_content', static function (\$empty) {
    if (isset(\$_SERVER['{$header}'], \$_SERVER['{$fail}']) && \$_SERVER['{$header}'] === '{$run}' && \$_SERVER['{$fail}'] === 'on') { return true; }
    return \$empty;
});
PHP;
    }

    private function servedDescription(string $token, string $name): string
    {
        $cursor = null;

        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $body   = json_decode((string) $this->mcp($token)->post('tools/list', $params)->getBody(), true);

            foreach ($body['result']['tools'] ?? [] as $tool) {
                if (($tool['name'] ?? '') === $name) { return (string) ($tool['description'] ?? ''); }
            }

            $cursor = $body['result']['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');

        self::fail("tools/list does not serve {$name} to that token.");
    }
}
