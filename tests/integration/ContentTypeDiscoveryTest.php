<?php
/**
 * list-content-types, THE SEAM'S SECOND CONSUMER - and the answer to the mislead a cold client
 * ranked first (analysis/66).
 *
 * WHAT WENT WRONG WITHOUT IT. An instance forbidden from reading this source was pointed at a
 * real customer site, called `list-posts`, got ONE item, and had no way to learn that the site's
 * actual content sat in five custom post types. It eventually found them by reverse-engineering
 * the `object` field of `get-menu` results. `list-posts` defaults to `post_type: "post"`, and
 * nothing in the tool surface named anything else; `list-terms` defaults to `category`, which on
 * that site held one term because none of the five types registered a taxonomy.
 *
 * SO THE TEST BUILDS THAT SITE. A mu-plugin registers a PUBLIC custom post type with its own
 * public taxonomy, and a second type that is not public at all. Two published posts and a draft
 * go into the public one. Then the questions this class answers are the cold client's questions:
 *
 *   - is the custom type NAMED, to a plain read-scope token?
 *   - is the name it gives one `list-posts` actually accepts? (the loop, closed)
 *   - is the taxonomy in the SAME answer, so finding it does not need a second call nobody knew
 *     to make?
 *   - are the counts capability-scoped, so a token bound to an Author is told how many published
 *     posts there are and NOT how many drafts?
 *   - is the NON-public type absent? A type list that reported everything would be a plugin
 *     inventory, and list-plugins is admin-scope on purpose.
 *
 * THE POST TYPE SLUGS ARE SHORT, and not fixture-prefixed, because register_post_type() refuses
 * a name over 20 characters and `wpmcp-test-<run id>-` is exactly 20 on its own. They still carry
 * the run id, so two runners register two types and neither sees the other's. Nothing needs
 * cleaning up after a post type: it exists only while the code that registers it runs, so an
 * inert leftover mu-plugin registers nothing. The POSTS carry prefixed titles, which is what
 * Fixtures::leftoverPosts() matches on - it lists `--post_type=any`.
 *
 * @group sprint-seam
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;

final class ContentTypeDiscoveryTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('ctypes'); }
    private static function authorLogin(): string { return Fixtures::name('ctypes-author'); }
    private static function adminLogin(): string { return Fixtures::name('ctypes-admin'); }

    /** The mu-plugin that registers the fixture types. */
    private const TYPES = 'content-types';

    private static int $authorId = 0;
    private static int $adminId = 0;
    private static string $authorToken = '';
    private static string $adminToken = '';

    /** @var list<int> */
    private static array $postIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        // BEFORE the posts: `wp post create --post_type=<slug>` refuses a type the wp-cli
        // process has never heard of, and mu-plugins are loaded by wp-cli too.
        MuPlugin::drop(self::TYPES, self::typesSource());

        self::$authorId = Fixtures::createUser(self::authorLogin(), 'author');
        self::$adminId  = Fixtures::createUser(self::adminLogin(), 'administrator');

        self::$authorToken = Fixtures::mintToken('read', self::label(), self::$authorId);
        self::$adminToken  = Fixtures::mintToken('read', self::label(), self::$adminId);

        foreach ([['one', 'publish'], ['two', 'publish'], ['three', 'draft']] as [$what, $status]) {
            self::$postIds[] = Fixtures::createPostWith([
                'post_type'   => self::publicType(),
                'post_title'  => Fixtures::name('ctype-' . $what),
                'post_status' => $status,
                'post_author' => self::$adminId,
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        foreach (self::$postIds as $id) {
            Fixtures::deletePost($id);
        }

        self::$postIds = [];

        MuPlugin::remove(self::TYPES);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteUser(self::$adminId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * THE MISLEAD, CLOSED. A read-scope token bound to an Author is told the custom type exists,
     * and the name it is given is one list-posts accepts and answers with the fixture's posts.
     *
     * @group sprint-seam
     */
    public function testACustomPostTypeIsNamedAndTheNameWorksInListPosts(): void
    {
        $types = array_column($this->discover(self::$authorToken)['post_types'], null, 'name');

        self::assertArrayHasKey(
            self::publicType(),
            $types,
            'The custom post type is not named, so a cold client is exactly as stuck as it was.'
            . ' Named: ' . implode(', ', array_keys($types))
        );

        // AND THE NAME IS USABLE, which is the half that makes discovery worth anything. Without
        // this the tool could report a type list-posts refuses and still look right.
        $result = $this->mcp(self::$authorToken)
            ->callTool('list-posts', ['post_type' => self::publicType(), 'limit' => 20]);

        $titles = array_column($result->data()['items'], 'title');

        self::assertContains(Fixtures::name('ctype-one'), $titles);
        self::assertContains(Fixtures::name('ctype-two'), $titles);
        self::assertNotContains(
            Fixtures::name('ctype-three'),
            $titles,
            'An Author-bound read token was shown another user\'s draft in the custom type.'
        );
    }

    /**
     * Every field the description promises, and no others - on a post type and on a taxonomy.
     *
     * ASSERTED AS AN ORDERED KEY LIST, because "returns what it says" is the claim the unit
     * tier's description contract cannot check: nothing there calls the tool.
     *
     * @group sprint-seam
     */
    public function testTheAnswerCarriesExactlyTheFieldsTheDescriptionNames(): void
    {
        $data = $this->discover(self::$adminToken);

        self::assertSame(
            ['post_types', 'taxonomies'],
            array_keys($data),
            'The result shape changed.'
        );

        $types = array_column($data['post_types'], null, 'name');
        $taxes = array_column($data['taxonomies'], null, 'name');

        self::assertSame(
            [
                'name', 'label', 'singular_label', 'description', 'hierarchical', 'public',
                'show_in_rest', 'rest_base', 'taxonomies', 'counts',
            ],
            array_keys($types[self::publicType()]),
            'A post type entry does not carry the ten fields the description names.'
        );
        self::assertSame(
            [
                'name', 'label', 'singular_label', 'description', 'hierarchical', 'public',
                'show_in_rest', 'rest_base', 'post_types', 'terms',
            ],
            array_keys($taxes[self::taxonomy()]),
            'A taxonomy entry does not carry the ten fields the description names.'
        );

        $type = $types[self::publicType()];

        self::assertSame(self::publicTypeLabel(), $type['label']);
        self::assertTrue($type['public']);
        self::assertTrue($type['show_in_rest']);
        self::assertSame(self::publicType() . '-items', $type['rest_base'], 'rest_base is not the registered one.');
        self::assertContains(self::taxonomy(), $type['taxonomies'], 'The type does not name its own taxonomy.');
        self::assertNotContains(
            self::hiddenTaxonomy(),
            $type['taxonomies'],
            'The post type\'s own `taxonomies` field names a taxonomy that is not viewable, which'
            . ' the description says is withheld - and list-terms accepts any name it is given, so'
            . ' the caller enumerates it next. This is the field the top-level taxonomies[] gate'
            . ' cannot see.'
        );

        $tax = $taxes[self::taxonomy()];

        self::assertContains(self::publicType(), $tax['post_types'], 'The taxonomy does not name its type.');
        self::assertIsInt($tax['terms']);

        // rest_base is NULL rather than a guess for a taxonomy that is not in REST, and `post`
        // is there to prove the true case is a real string and not always null.
        self::assertSame('posts', $types['post']['rest_base']);
    }

    /**
     * THE COUNTS ARE CAPABILITY-SCOPED, by the same function list-posts scopes its query with.
     *
     * An Administrator's token is told there is a draft. An Author's is told about published posts
     * and NOT about the draft - not "0 drafts" either, which would still be a fact about somebody
     * else's unpublished work, but no draft key at all.
     *
     * THE ASSERTION IS A SUBSET AND A DENY-LIST, NOT AN EXACT KEY LIST, and the reason is a
     * MEASUREMENT on the stress site rather than caution: ACF registers `acf-disabled` with
     * `public => true`, and wpmcp_listable_statuses() deliberately reads the registry rather than
     * the five core statuses, so an Author's token is shown that status too - correctly, because
     * `public` is the flag whose author said it is on the front end. An exact list here would make
     * this class a test of which plugins the site has.
     *
     * @group sprint-seam
     */
    public function testTheCountsSayOnlyWhatTheCallerMaySeeListed(): void
    {
        $asAdmin = $this->typeEntry(self::$adminToken, self::publicType())['counts'];

        self::assertSame(2, $asAdmin['publish'] ?? null, 'The published count is wrong for an admin.');
        self::assertSame(1, $asAdmin['draft'] ?? null, 'An Administrator is not told about the draft.');

        $asAuthor = $this->typeEntry(self::$authorToken, self::publicType())['counts'];

        self::assertSame(2, $asAuthor['publish'] ?? null, 'The published count is wrong for an author.');

        foreach (['draft', 'pending', 'future', 'private'] as $status) {
            self::assertArrayNotHasKey(
                $status,
                $asAuthor,
                "An Author-bound token was told about the {$status} bucket of another user's posts."
                . ' Statuses given: ' . implode(', ', array_keys($asAuthor))
            );
        }

        self::assertSame(
            [],
            array_diff(array_keys($asAuthor), array_keys($asAdmin)),
            'An Author was given a status an Administrator was not, which cannot be a narrowing:'
            . ' ' . implode(', ', array_diff(array_keys($asAuthor), array_keys($asAdmin)))
        );
    }

    /**
     * WHAT IS LEFT OUT, and this is a disclosure decision rather than tidiness. A type nobody can
     * view is a type some plugin registered for itself, and an unfiltered list is a plugin
     * inventory by another route - which list-plugins makes admin-scope on purpose. `attachment`
     * is out because list-posts refuses it too; list-media is the tool for media. `nav_menu` is
     * the taxonomy side of the same rule.
     *
     * @group sprint-seam
     */
    public function testNothingUnviewableIsListed(): void
    {
        $data = $this->discover(self::$adminToken);

        $types = array_column($data['post_types'], 'name');
        $taxes = array_column($data['taxonomies'], 'name');

        self::assertNotContains(
            self::hiddenType(),
            $types,
            'A post type registered public => false is named, so the tool reports what a plugin'
            . ' keeps to itself.'
        );
        self::assertNotContains('attachment', $types, 'attachment is listed and list-posts refuses it.');
        self::assertNotContains('revision', $types);
        self::assertNotContains('nav_menu_item', $types);

        self::assertNotContains('nav_menu', $taxes, 'nav_menu is not viewable and must not be listed.');
        self::assertNotContains('wp_theme', $taxes);
        self::assertNotContains(
            self::hiddenTaxonomy(),
            $taxes,
            'The fixture taxonomy registered public => false is listed at the top level.'
        );

        // AND NOT INSIDE ANY TYPE EITHER, because the field is built per type and one gate has
        // to cover them all.
        //
        // COLLECTED AND ASSERTED ONCE, NOT ASSERTED PER PAIR, and that is about the INSTRUMENT
        // rather than about this test. An assertion inside the loop makes this class's assertion
        // count a function of how many (type, taxonomy) pairs the site has - 42 on a plugin-heavy
        // site and 41 on a bare one - and `--group sprint-seam`'s total is the counted invariant
        // the sprint loop reads to notice a gate that has quietly shrunk. A count that differs by
        // one for a legitimate reason is exactly the sentence that hides the next illegitimate
        // one. One assertion over a collected list is site-independent, and it reports BETTER: it
        // names every offender instead of dying on the first.
        $inTypes = [];

        foreach ($data['post_types'] as $type) {
            foreach ((array) $type['taxonomies'] as $name) {
                if ($name === self::hiddenTaxonomy()) {
                    $inTypes[] = 'post_types[' . $type['name'] . '].taxonomies';
                }
            }
        }

        self::assertSame(
            [],
            $inTypes,
            'A post type\'s own `taxonomies` field names the non-viewable fixture taxonomy at: '
            . implode(', ', $inTypes)
            . '. The description says a non-viewable taxonomy is withheld, and list-terms accepts'
            . ' any name it is given, so the caller enumerates it next. This is the field the'
            . ' top-level taxonomies[] gate cannot see.'
        );

        // And the positive control, or "nothing is listed" would pass this.
        self::assertContains('post', $types);
        self::assertContains('page', $types);
        self::assertContains('category', $taxes);
        self::assertContains('post_tag', $taxes);
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return array{post_types: list<array>, taxonomies: list<array>} */
    private function discover(string $token): array
    {
        return $this->mcp($token)->callTool('list-content-types')->data();
    }

    private function typeEntry(string $token, string $name): array
    {
        $types = array_column($this->discover($token)['post_types'], null, 'name');

        self::assertArrayHasKey($name, $types, "{$name} is not in the answer.");

        return $types[$name];
    }

    /** `wm<run id>c`: 11 characters, inside register_post_type()'s cap of 20. */
    private static function publicType(): string
    {
        return 'wm' . Fixtures::runId() . 'c';
    }

    /** The same, for the type that is registered public => false. */
    private static function hiddenType(): string
    {
        return 'wm' . Fixtures::runId() . 'h';
    }

    /** A taxonomy name may be 32 characters, so this one can carry the ordinary prefix. */
    private static function taxonomy(): string
    {
        return Fixtures::name('ctax');
    }

    /**
     * A `public => false` taxonomy attached to the PUBLIC type - the case that catches an
     * unfiltered per-type `taxonomies` field, which the top-level gate cannot see.
     */
    private static function hiddenTaxonomy(): string
    {
        return Fixtures::name('chtax');
    }

    private static function publicTypeLabel(): string
    {
        return 'WPMCP Fixture Types';
    }

    /**
     * On `init`, because that is the only hook register_post_type() may be called from, and with
     * `show_in_rest` and an explicit `rest_base` so the REST fields have a value worth asserting.
     */
    private static function typesSource(): string
    {
        $public = self::publicType();
        $hidden = self::hiddenType();
        $tax    = self::taxonomy();
        $label  = self::publicTypeLabel();
        $htax   = self::hiddenTaxonomy();

        return <<<PHP
add_action('init', static function () {
    register_post_type('{$public}', array(
        'label'        => '{$label}',
        'labels'       => array('name' => '{$label}', 'singular_name' => 'WPMCP Fixture Type'),
        'description'  => 'wp-mcp integration fixture: a public custom post type.',
        'public'       => true,
        'hierarchical' => false,
        'show_in_rest' => true,
        'rest_base'    => '{$public}-items',
        'supports'     => array('title', 'editor', 'author'),
    ));

    // NOT VIEWABLE, so list-content-types must not name it: no public front end, no REST.
    register_post_type('{$hidden}', array(
        'label'              => 'WPMCP Fixture Hidden',
        'public'             => false,
        'publicly_queryable' => false,
        'show_in_rest'       => false,
    ));

    // PUBLIC => FALSE, ON THE PUBLIC TYPE. is_taxonomy_viewable() refuses it, so it must not
    // appear in the top-level list NOR inside that type's own `taxonomies` field - and only the
    // second of those two was true of the first version of the tool.
    register_taxonomy('{$htax}', array('{$public}'), array(
        'label'              => 'WPMCP Fixture Hidden Taxonomy',
        'public'             => false,
        'publicly_queryable' => false,
        'show_in_rest'       => false,
        'hierarchical'       => false,
    ));

    register_taxonomy('{$tax}', array('{$public}'), array(
        'label'        => 'WPMCP Fixture Taxonomy',
        'labels'       => array('name' => 'WPMCP Fixture Taxonomy', 'singular_name' => 'WPMCP Fixture Term'),
        'description'  => 'wp-mcp integration fixture: a public taxonomy on a custom type.',
        'public'       => true,
        'hierarchical' => true,
        'show_in_rest' => true,
        'rest_base'    => '{$tax}-terms',
    ));
}, 5);
PHP;
    }
}
