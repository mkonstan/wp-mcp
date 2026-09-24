<?php
/**
 * Sprint 13: classic navigation menus - list-menus, get-menu, add-menu-item,
 * update-menu-item and remove-menu-item.
 *
 * WHAT IS OURS TO TEST. Not whether WordPress can store a menu item - it can. What is ours:
 *
 *   - THE TWO GATES. Reading a menu is core's REST rule (edit_theme_options, or edit_posts
 *     on any post type shown in REST), so an Editor reads and a Subscriber does not.
 *     Writing is edit_theme_options, which is what every capability core maps for a
 *     nav_menu_item or the nav_menu taxonomy resolves to (measured, every role, both
 *     sites).
 *   - THE ORDER. Core's wp_update_nav_menu_item() does not shift siblings: position 2
 *     on a menu of A(0) B(2) C(3) stores a second 2 (measured). wp-admin's JavaScript
 *     renumbers the whole list on save; these tools do it on every write.
 *   - THE CHILDREN of a removed item. Core's wp_delete_post() leaves them pointing at an
 *     id that no longer exists (measured); wp-admin's removeMenuItem lifts them one level
 *     (nav-menu.js:1844, shiftDepthClass(-1) + updateParentMenuItemDBId).
 *   - WHAT A READER MAY SEE of an item that links to a draft or a private page:
 *     wp_setup_nav_menu_item() hands out its title and its ?page_id= link to anybody.
 *   - THE REFUSALS core does not make: a parent from another menu (core stores it), a
 *     cycle, a javascript: url (core silently stores an EMPTY url).
 *   - THE SLASHING: wp_update_nav_menu_item() hands the title to wp_insert_post(), which
 *     unslashes, and compares wp_unslash() of it with the linked page's title.
 *
 * EVERY WRITE IS READ BACK TWO WAYS: through get-menu, and through the database in
 * another process. EVERY REFUSAL ASSERTS THE ROWS DID NOT MOVE.
 *
 * FIXTURE MENUS ONLY, on the stress site a real client's. No test assigns a fixture menu
 * to a stored theme location: the location assertions use a run-header-gated mu-plugin
 * that registers a fixture location and maps it for this run's own requests, and the
 * stored theme mod is asserted unchanged around it.
 *
 * @group sprint-13
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\McpClient;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\WpCli;

final class MenuToolsTest extends FixtureIntegrationTestCase
{
    /** No site this suite runs against has an id anywhere near this. */
    private const MISSING_ID = 2000000000;

    /** The mu-plugin that registers and maps one fixture location, for this run's requests. */
    private const LOCATION_PLUGIN = 'menulocation';

    /** A backslash without escape meaning, quotes of both kinds, HTML, a regex, a doubled one. */
    private const TITLE = 'A\\B "quoted" \'single\' <b>bold</b> C:\\Users\\max \\d+ a\\\\b';

    private static function label(): string { return Fixtures::name('menus'); }
    private static function login(string $role): string { return Fixtures::name('menu' . $role); }
    private static function locationSlug(): string { return Fixtures::name('menu-location'); }
    private static function locationDescription(): string { return Fixtures::name('Menu Location Description'); }

    private static int $adminId = 0;
    private static int $editorId = 0;
    private static int $authorId = 0;
    private static int $subscriberId = 0;

    private static string $adminToken = '';
    private static string $editorToken = '';
    private static string $authorToken = '';
    private static string $subscriberToken = '';

    private static int $draftPageId = 0;
    private static int $privatePageId = 0;
    private static int $publicPageId = 0;
    private static int $categoryId = 0;

    private static int $locationMenuId = 0;
    private static int $plainMenuId = 0;

    /** @var list<int> every fixture menu, for destroy() */
    private static array $menus = [];

    /** @var list<int> posts a test created, for destroy() */
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

        self::$adminId      = Fixtures::createUser(self::login('admin'), 'administrator');
        self::$editorId     = Fixtures::createUser(self::login('editor'), 'editor');
        self::$authorId     = Fixtures::createUser(self::login('author'), 'author');
        self::$subscriberId = Fixtures::createUser(self::login('subscriber'), 'subscriber');

        // The Editor's pages. The Author may read the published one and neither of the others.
        self::$draftPageId   = Fixtures::createPost(Fixtures::name('menu-draft-page'), 'draft', self::$editorId, 'x', 'page');
        self::$privatePageId = Fixtures::createPost(Fixtures::name('menu-private-page'), 'private', self::$editorId, 'x', 'page');
        self::$publicPageId  = Fixtures::createPost(Fixtures::name('menu-public-page'), 'publish', self::$editorId, 'x', 'page');

        self::$categoryId = Fixtures::createTerm('category', Fixtures::name('menu-category'));

        self::$locationMenuId = self::menu('located');
        self::$plainMenuId    = self::menu('unlocated');

        MuPlugin::drop(self::LOCATION_PLUGIN, self::locationSource(self::$locationMenuId));

        self::$adminToken      = Fixtures::mintToken('admin', self::label(), self::$adminId);
        self::$editorToken     = Fixtures::mintToken('admin', self::label(), self::$editorId);
        self::$authorToken     = Fixtures::mintToken('admin', self::label(), self::$authorId);
        self::$subscriberToken = Fixtures::mintToken('admin', self::label(), self::$subscriberId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::LOCATION_PLUGIN);

        foreach (self::$menus as $id) { Fixtures::deleteMenu((int) $id); }
        self::$menus = [];

        foreach (array_merge(self::$posts, [self::$draftPageId, self::$privatePageId, self::$publicPageId]) as $id) {
            Fixtures::deletePost((int) $id);
        }
        self::$posts = [];

        if (self::$categoryId > 0) { Fixtures::deleteTerm('category', self::$categoryId); }

        foreach ([self::$adminId, self::$editorId, self::$authorId, self::$subscriberId] as $id) {
            Fixtures::deleteUser($id);
        }

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * G1 - the two gates
     * ---------------------------------------------------------------- */

    /**
     * G1. A Subscriber - admin-scope token, so the scope gate is not what refuses - is
     * refused by all five tools, and nothing changes.
     *
     * @group sprint-13
     */
    public function testASubscriberIsRefusedByAllFiveMenuTools(): void
    {
        self::assertFalse(Fixtures::userCan(self::$subscriberId, 'edit_posts'), 'Premise: a Subscriber here holds edit_posts.');

        [$menuId, $ids] = self::menuWithItems('g1s', ['A' => [], 'B' => []]);
        $before = Fixtures::menuItemRows($menuId);
        $client = $this->mcp(self::$subscriberToken);

        foreach (self::oneCallOfEachTool($menuId, $ids['A'], $ids['B']) as [$tool, $arguments]) {
            $result = $client->callTool($tool, $arguments);

            self::assertTrue($result->isError, "A Subscriber was answered by {$tool}: " . $result->text);
            self::assertStringContainsString('not allowed', $result->text, "{$tool} refused a Subscriber for another reason.");
        }

        self::assertSame($before, Fixtures::menuItemRows($menuId), 'A refused Subscriber changed the menu.');
    }

    /**
     * G1. An Editor - no edit_theme_options, the marketing role - reads menus through both
     * read tools, and is refused by all three write tools.
     *
     * @group sprint-13
     */
    public function testAnEditorReadsMenusButCannotWriteThem(): void
    {
        self::assertFalse(Fixtures::userCan(self::$editorId, 'edit_theme_options'), 'Premise: an Editor here holds edit_theme_options.');

        [$menuId, $ids] = self::menuWithItems('g1e', ['A' => ['A1' => []], 'B' => []]);
        $before = Fixtures::menuItemRows($menuId);
        $client = $this->mcp(self::$editorToken);

        $list = $client->callTool('list-menus');
        self::assertFalse($list->isError, $list->text);
        self::assertContains($menuId, array_column($list->data()['menus'], 'id'), 'An Editor does not see the menu in list-menus.');

        $menu = $client->callTool('get-menu', ['id' => $menuId]);
        self::assertFalse($menu->isError, $menu->text);
        self::assertSame([$ids['A'], $ids['A1'], $ids['B']], array_column(self::flatten($menu->data()['items']), 'id'));
        self::assertSame(Fixtures::name('g1e-A1'), self::flatten($menu->data()['items'])[1]['title']);

        foreach (array_slice(self::oneCallOfEachTool($menuId, $ids['A'], $ids['B']), 2) as [$tool, $arguments]) {
            $result = $client->callTool($tool, $arguments);

            self::assertTrue($result->isError, "An Editor could call {$tool}: " . $result->text);
            self::assertStringContainsString('not allowed', $result->text, "{$tool} refused an Editor for another reason.");
        }

        self::assertSame($before, Fixtures::menuItemRows($menuId), 'A refused Editor changed the menu.');
    }

    /**
     * G1. An Administrator can add, change and remove an item - target and classes
     * included - and the database says so.
     *
     * @group sprint-13
     */
    public function testAnAdministratorWritesMenus(): void
    {
        [$menuId, $ids] = self::menuWithItems('g1a', ['A' => []]);
        $client = $this->mcp(self::$adminToken);

        $added = $client->callTool('add-menu-item', [
            'menu_id' => $menuId, 'type' => 'custom', 'url' => 'https://example.com/g1a', 'title' => Fixtures::name('g1a-new'),
        ]);
        self::assertFalse($added->isError, $added->text);
        $newId = (int) $added->data()['id'];
        self::assertSame([$ids['A'], $newId], array_column(Fixtures::menuItemRows($menuId), 'id'));

        $updated = $client->callTool('update-menu-item', [
            'id' => $newId, 'target' => '_blank', 'classes' => ['wpmcp-one', 'wpmcp-two'],
        ]);
        self::assertFalse($updated->isError, $updated->text);

        $item = self::itemById($client->callTool('get-menu', ['id' => $menuId]), $newId);
        self::assertSame('_blank', $item['target']);
        self::assertSame(['wpmcp-one', 'wpmcp-two'], $item['classes']);
        self::assertSame(Fixtures::name('g1a-new'), $item['title'], 'Changing the target lost the title.');
        self::assertSame('https://example.com/g1a', $item['url'], 'Changing the target lost the url.');

        $removed = $client->callTool('remove-menu-item', ['id' => $newId]);
        self::assertFalse($removed->isError, $removed->text);
        self::assertFalse(Fixtures::isNavMenuItem($newId), 'remove-menu-item left the item in the database.');
        self::assertSame([$ids['A']], array_column(Fixtures::menuItemRows($menuId), 'id'));
    }

    /* ------------------------------------------------------------------
     * G2 - position, and a contiguous order
     * ---------------------------------------------------------------- */

    /**
     * G2. add-menu-item with a position places the item exactly there among its siblings -
     * at the top level and under a parent - and the whole menu is renumbered 1..N in
     * depth-first order, in the database and in get-menu.
     *
     * Red without the renumbering: core stores the new item at the end, or beside a sibling
     * holding the same order (measured: position 2 on A(0) B(2) C(3) stores a second 2).
     *
     * @group sprint-13
     */
    public function testAddPlacesAnItemExactlyWhereAskedAmongItsSiblings(): void
    {
        [$menuId, $ids] = self::menuWithItems('g2', ['A' => [], 'B' => ['B1' => [], 'B2' => []], 'C' => []]);
        $client = $this->mcp(self::$adminToken);

        self::assertNotSame(range(1, 5), array_column(Fixtures::menuItemRows($menuId), 'order'), 'Premise: core stored a contiguous order by itself.');

        $x = $this->addCustom($client, $menuId, 'g2-X', ['position' => 2]);
        $y = $this->addCustom($client, $menuId, 'g2-Y', ['parent_id' => $ids['B'], 'position' => 1]);
        $z = $this->addCustom($client, $menuId, 'g2-Z', ['parent_id' => $ids['B']]);

        $expected = [$ids['A'], $x, $ids['B'], $y, $ids['B1'], $ids['B2'], $z, $ids['C']];
        $rows     = Fixtures::menuItemRows($menuId);

        self::assertSame($expected, array_column($rows, 'id'), 'The items are not where they were asked to go.');
        self::assertSame(range(1, 8), array_column($rows, 'order'), 'The stored order is not contiguous.');
        self::assertSame($ids['B'], $rows[3]['parent'], 'Y is not under B.');

        $menu = $client->callTool('get-menu', ['id' => $menuId]);
        self::assertFalse($menu->isError, $menu->text);
        $flat = self::flatten($menu->data()['items']);

        self::assertSame($expected, array_column($flat, 'id'));
        self::assertSame(range(1, 8), array_column($flat, 'menu_order'));
        self::assertSame([$ids['A'], $x, $ids['B'], $ids['C']], array_column($menu->data()['items'], 'id'));
        self::assertSame([1, 2, 3, 4], array_column($menu->data()['items'], 'position'));
        self::assertSame([$y, $ids['B1'], $ids['B2'], $z], array_column($menu->data()['items'][2]['children'], 'id'));
        self::assertSame([1, 2, 3, 4], array_column($menu->data()['items'][2]['children'], 'position'));
    }

    /**
     * G2. update-menu-item moves an item - within its siblings, and under a new parent -
     * and the order stays contiguous after every move.
     *
     * @group sprint-13
     */
    public function testUpdateMovesAnItemAndTheOrderStaysContiguous(): void
    {
        [$menuId, $ids] = self::menuWithItems('g2m', ['A' => ['A1' => []], 'B' => [], 'C' => []]);
        $client = $this->mcp(self::$adminToken);

        $this->update($client, ['id' => $ids['C'], 'position' => 1]);
        $this->assertOrder($menuId, [$ids['C'], $ids['A'], $ids['A1'], $ids['B']]);

        $this->update($client, ['id' => $ids['A1'], 'parent_id' => $ids['B']]);
        $this->assertOrder($menuId, [$ids['C'], $ids['A'], $ids['B'], $ids['A1']]);
        self::assertSame($ids['B'], Fixtures::menuItemRows($menuId)[3]['parent']);

        $this->update($client, ['id' => $ids['B'], 'position' => 1]);
        $this->assertOrder($menuId, [$ids['B'], $ids['A1'], $ids['C'], $ids['A']]);

        $this->update($client, ['id' => $ids['A1'], 'parent_id' => 0, 'position' => 2]);
        $this->assertOrder($menuId, [$ids['B'], $ids['A1'], $ids['C'], $ids['A']]);
        self::assertSame(0, Fixtures::menuItemRows($menuId)[1]['parent']);
    }

    /* ------------------------------------------------------------------
     * G3 - removing an item with children
     * ---------------------------------------------------------------- */

    /**
     * G3. remove-menu-item lifts the removed item's children to its parent, in place, and
     * leaves every remaining item in the order it had - renumbered 1..N. Both at the top
     * level (children go to the top) and one level down (grandchildren go to the parent).
     *
     * Red without the re-parenting: core leaves the children pointing at the deleted id.
     *
     * @group sprint-13
     */
    public function testRemoveKeepsTheOrderAndLiftsChildrenOneLevel(): void
    {
        [$menuId, $ids] = self::menuWithItems('g3', [
            'A' => ['A1' => [], 'A2' => []],
            'B' => ['B1' => ['B1a' => [], 'B1b' => []], 'B2' => []],
            'C' => [],
        ]);
        $client = $this->mcp(self::$adminToken);
        $before = array_column(Fixtures::menuItemRows($menuId), 'id');

        $removed = $client->callTool('remove-menu-item', ['id' => $ids['A']]);
        self::assertFalse($removed->isError, $removed->text);
        self::assertSame([$ids['A1'], $ids['A2']], $removed->data()['reparented']);

        $this->assertOrder($menuId, array_values(array_diff($before, [$ids['A']])));
        $parents = array_column(Fixtures::menuItemRows($menuId), 'parent', 'id');
        self::assertSame(0, $parents[$ids['A1']], 'A1 still points at the removed item.');
        self::assertSame(0, $parents[$ids['A2']], 'A2 still points at the removed item.');

        $removed = $client->callTool('remove-menu-item', ['id' => $ids['B1']]);
        self::assertFalse($removed->isError, $removed->text);

        $this->assertOrder($menuId, array_values(array_diff($before, [$ids['A'], $ids['B1']])));
        $parents = array_column(Fixtures::menuItemRows($menuId), 'parent', 'id');
        self::assertSame($ids['B'], $parents[$ids['B1a']], 'B1a was not lifted to B.');
        self::assertSame($ids['B'], $parents[$ids['B1b']], 'B1b was not lifted to B.');

        $menu = $client->callTool('get-menu', ['id' => $menuId]);
        self::assertSame([$ids['A1'], $ids['A2'], $ids['B'], $ids['C']], array_column($menu->data()['items'], 'id'));
        self::assertSame([$ids['B1a'], $ids['B1b'], $ids['B2']], array_column($menu->data()['items'][2]['children'], 'id'));
        self::assertFalse(Fixtures::isNavMenuItem($ids['B1']));
    }

    /* ------------------------------------------------------------------
     * G4 - what a reader may see
     * ---------------------------------------------------------------- */

    /**
     * G4. An item that links to a draft or a private page the caller may not read comes back
     * as an item - with its label, its link and the linked id withheld, and marked - and
     * the page's title and link appear nowhere in the answer. An item with a label of its
     * own is withheld too. A caller who may read the page sees all of it.
     *
     * @group sprint-13
     */
    public function testAnItemLinkingToContentTheCallerMayNotReadIsWithheld(): void
    {
        self::assertTrue(Fixtures::userCan(self::$authorId, 'edit_posts'), 'Premise: an Author here cannot read menus at all.');
        self::assertFalse(Fixtures::userCan(self::$authorId, 'read_post', self::$draftPageId), 'Premise: an Author may read the draft page.');
        self::assertFalse(Fixtures::userCan(self::$authorId, 'read_post', self::$privatePageId), 'Premise: an Author may read the private page.');

        $menuId = self::menu('g4');
        $link   = static fn (int $pageId, string $title = ''): array => [
            'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $pageId, 'menu-item-title' => $title,
        ];
        $draft    = Fixtures::createMenuItem($menuId, $link(self::$draftPageId));
        $private  = Fixtures::createMenuItem($menuId, $link(self::$privatePageId));
        $labelled = Fixtures::createMenuItem($menuId, $link(self::$draftPageId, Fixtures::name('g4-secret-label')));
        $public   = Fixtures::createMenuItem($menuId, $link(self::$publicPageId));
        $custom   = Fixtures::createMenuItem($menuId, ['menu-item-title' => Fixtures::name('g4-custom'), 'menu-item-url' => 'https://example.com/g4']);

        $author = $this->mcp(self::$authorToken)->callTool('get-menu', ['id' => $menuId]);
        self::assertFalse($author->isError, $author->text);

        foreach ([$draft, $private, $labelled] as $id) {
            $item = self::itemById($author, $id);
            self::assertTrue($item['withheld'], "Item {$id} is not marked withheld.");
            self::assertNull($item['title'], "Item {$id}'s label reached a caller who may not read the page.");
            self::assertNull($item['url'], "Item {$id}'s link reached a caller who may not read the page.");
            self::assertNull($item['object_id'], "Item {$id}'s page id reached a caller who may not read the page.");
        }

        foreach ([Fixtures::name('menu-draft-page'), Fixtures::name('menu-private-page'), Fixtures::name('g4-secret-label'),
                  'page_id=' . self::$draftPageId, 'page_id=' . self::$privatePageId] as $secret) {
            self::assertStringNotContainsString($secret, $author->text, "get-menu told an Author '{$secret}'.");
        }

        $publicItem = self::itemById($author, $public);
        self::assertFalse($publicItem['withheld']);
        self::assertSame(Fixtures::name('menu-public-page'), $publicItem['title']);
        self::assertNotSame('', (string) $publicItem['url']);
        self::assertSame(self::$publicPageId, $publicItem['object_id']);
        self::assertSame(Fixtures::name('g4-custom'), self::itemById($author, $custom)['title']);

        $editor = $this->mcp(self::$editorToken)->callTool('get-menu', ['id' => $menuId]);
        self::assertFalse($editor->isError, $editor->text);
        self::assertFalse(self::itemById($editor, $draft)['withheld'], 'The draft page\'s own author is refused it.');
        self::assertSame(Fixtures::name('menu-draft-page'), self::itemById($editor, $draft)['title']);
        self::assertSame(Fixtures::name('g4-secret-label'), self::itemById($editor, $labelled)['title']);
        self::assertSame(Fixtures::name('menu-private-page'), self::itemById($editor, $private)['title']);
    }

    /* ------------------------------------------------------------------
     * G5 - one not_found, and the named refusals
     * ---------------------------------------------------------------- */

    /**
     * G5. An id that is not a menu - a page, a category, a menu ITEM - answers get-menu and
     * add-menu-item exactly as a missing id does; an id that is not a menu item answers
     * update-menu-item and remove-menu-item exactly as a missing id does - an item that is in
 * no menu included; a linked object of
     * the wrong kind answers add-menu-item as a missing object does. Nothing changes.
     *
     * @group sprint-13
     */
    public function testAnIdOfTheWrongKindIsTheMissingIdAnswer(): void
    {
        [$menuId, $ids] = self::menuWithItems('g5n', ['A' => []]);
        $client = $this->mcp(self::$adminToken);
        $before = Fixtures::menuItemRows($menuId);
        $custom = ['type' => 'custom', 'url' => 'https://example.com/g5n', 'title' => Fixtures::name('g5n-never')];

        // POST IDS AND TERM IDS ARE SEPARATE COUNTERS, AND ON AN ALMOST-EMPTY DATABASE THEY
        // COLLIDE. `get-menu` resolves its `id` as a nav_menu TERM, so a PAGE whose post id
        // happens to equal one of this site's nav_menu term ids is, to that tool, a real menu -
        // and the premise below correctly refuses to write to it. Found by sharding (D18): on a
        // shard, this class runs in a container where almost nothing else has created posts or
        // terms, `menu-public-page` came out as post 6 and the fixture menu as term 6, and the
        // premise failed. It had never failed on a single full run, where thirty other classes
        // have pushed the two counters far apart before this one starts - which is a hidden
        // dependency on what ran BEFORE, and exactly the class of defect running the suite on
        // eight empty databases was always going to surface.
        //
        // So the candidates are CHOSEN rather than assumed: each keeps its kind and moves to a
        // fresh id of the same kind if its integer happens to name a menu. A term id cannot
        // collide - a category and a nav_menu are both terms, so one id is one of them - and
        // needs no dance.
        $page = self::idThatIsNotAMenu(
            self::$publicPageId,
            static function (): int {
                $id = Fixtures::createPost(Fixtures::name('g5n-page'), 'publish', self::$editorId, 'x', 'page');
                self::$posts[] = $id;

                return $id;
            }
        );

        $item = self::idThatIsNotAMenu(
            $ids['A'],
            static function () use ($menuId): int {
                return Fixtures::createMenuItem($menuId, [
                    'menu-item-title' => Fixtures::name('g5n-spare'),
                    'menu-item-url'   => 'https://example.com/g5n-spare',
                ]);
            }
        );

        foreach ([$page, self::$categoryId, $item] as $id) {
            // BEFORE any write: a wrong id must never be a real menu of the site under test.
            self::assertFalse(Fixtures::isNavMenu($id), "Premise: id {$id} is a menu on this site; the test would write to it.");

            $this->assertSameNotFound($client, 'get-menu', ['id' => $id], ['id' => self::MISSING_ID]);
            $this->assertSameNotFound($client, 'add-menu-item', ['menu_id' => $id] + $custom, ['menu_id' => self::MISSING_ID] + $custom);
        }

        foreach ([self::$publicPageId, self::$draftPageId] as $id) {
            self::assertFalse(Fixtures::isNavMenuItem($id), "Premise: id {$id} is a menu item.");

            $this->assertSameNotFound($client, 'update-menu-item', ['id' => $id, 'title' => 'x'], ['id' => self::MISSING_ID, 'title' => 'x']);
            $this->assertSameNotFound($client, 'remove-menu-item', ['id' => $id], ['id' => self::MISSING_ID]);
        }

        // AN ITEM IN NO MENU - core's own draft orphan. It is a nav_menu_item, so only the
        // menu it belongs to can refuse it, and it belongs to none.
        $orphan = Fixtures::createOrphanMenuItem(Fixtures::name('g5n-orphan'));
        self::$posts[] = $orphan;
        self::assertTrue(Fixtures::isNavMenuItem($orphan), 'Premise: the orphan fixture is not a menu item.');

        $this->assertSameNotFound($client, 'update-menu-item', ['id' => $orphan, 'title' => 'x'], ['id' => self::MISSING_ID, 'title' => 'x']);
        $this->assertSameNotFound($client, 'remove-menu-item', ['id' => $orphan], ['id' => self::MISSING_ID]);
        self::assertTrue(Fixtures::isNavMenuItem($orphan), 'remove-menu-item deleted an item that is in no menu.');

        // A POST OF ANOTHER TYPE as a page - a menu item, which can never be a page. NOT a
        // term id: term ids and post ids are separate counters, and on the stress site the
        // fixture category's term id 499 was also the id of a real published page, which
        // add-menu-item then rightly linked (into this fixture menu) - measured.
        $this->assertSameNotFound(
            $client,
            'add-menu-item',
            ['menu_id' => $menuId, 'type' => 'page', 'object_id' => $ids['A']],
            ['menu_id' => $menuId, 'type' => 'page', 'object_id' => self::MISSING_ID]
        );

        self::assertSame($before, Fixtures::menuItemRows($menuId), 'A refused call changed the menu.');
        self::assertSame('page', Fixtures::postField(self::$publicPageId, 'post_type'), 'A page given as a menu item id was changed.');
        self::assertSame(Fixtures::name('menu-public-page'), Fixtures::postField(self::$publicPageId, 'post_title'));
    }

    /**
     * G5. A parent that belongs to another menu is refused, by name, on add and on update -
     * core would store it (measured) - and neither menu changes.
     *
     * @group sprint-13
     */
    public function testAParentFromAnotherMenuIsRefusedAndNothingChanges(): void
    {
        [$menuId, $ids]   = self::menuWithItems('g5p', ['A' => [], 'B' => []]);
        [$otherId, $other] = self::menuWithItems('g5q', ['X' => []]);
        $client = $this->mcp(self::$adminToken);
        $before = [Fixtures::menuItemRows($menuId), Fixtures::menuItemRows($otherId)];

        $add = $client->callTool('add-menu-item', [
            'menu_id' => $menuId, 'type' => 'custom', 'url' => 'https://example.com/g5p', 'title' => Fixtures::name('g5p-never'),
            'parent_id' => $other['X'],
        ]);
        self::assertTrue($add->isError, 'A parent from another menu was accepted: ' . $add->text);
        self::assertStringContainsString('parent', $add->text);

        $update = $client->callTool('update-menu-item', ['id' => $ids['B'], 'parent_id' => $other['X']]);
        self::assertTrue($update->isError, 'A parent from another menu was accepted: ' . $update->text);
        self::assertStringContainsString('parent', $update->text);

        self::assertSame($before, [Fixtures::menuItemRows($menuId), Fixtures::menuItemRows($otherId)], 'A refused parent changed a menu.');
    }

    /**
     * G5. An item may not be made its own parent, or the child of anything inside it.
     *
     * Red without the check even for the first case: core silently resets a self-parent to 0
     * (nav-menu.php:583-586), so the call "succeeds" and moves the item to the top.
     *
     * @group sprint-13
     */
    public function testACycleIsRefusedAndNothingChanges(): void
    {
        [$menuId, $ids] = self::menuWithItems('g5c', ['A' => ['A1' => ['A1a' => []]], 'B' => []]);
        $client = $this->mcp(self::$adminToken);
        $before = Fixtures::menuItemRows($menuId);

        foreach ([[$ids['A1'], $ids['A1']], [$ids['A'], $ids['A1a']], [$ids['A1'], $ids['A1a']]] as [$id, $parent]) {
            $result = $client->callTool('update-menu-item', ['id' => $id, 'parent_id' => $parent]);

            self::assertTrue($result->isError, "Item {$id} was put under {$parent}: " . $result->text);
            self::assertStringContainsString('parent', $result->text);
        }

        self::assertSame($before, Fixtures::menuItemRows($menuId), 'A refused cycle changed the menu.');
    }

    /**
     * G5. A javascript: url - however it is spelled - is refused by name on add and on
     * update; core would store an EMPTY url and say nothing (measured).
     *
     * @group sprint-13
     */
    public function testAJavascriptUrlIsRefusedAndNothingChanges(): void
    {
        [$menuId, $ids] = self::menuWithItems('g5j', ['A' => []]);
        $client = $this->mcp(self::$adminToken);
        $before = Fixtures::menuItemRows($menuId);

        foreach (['javascript:alert(1)', ' JaVaScRiPt:alert(document.cookie)', 'data:text/html,x'] as $url) {
            $add = $client->callTool('add-menu-item', [
                'menu_id' => $menuId, 'type' => 'custom', 'url' => $url, 'title' => Fixtures::name('g5j-never'),
            ]);
            self::assertTrue($add->isError, "add-menu-item accepted {$url}: " . $add->text);
            self::assertStringContainsString('url', $add->text);

            $update = $client->callTool('update-menu-item', ['id' => $ids['A'], 'url' => $url]);
            self::assertTrue($update->isError, "update-menu-item accepted {$url}: " . $update->text);
            self::assertStringContainsString('url', $update->text);
        }

        self::assertSame($before, Fixtures::menuItemRows($menuId), 'A refused url changed the menu.');
        self::assertSame('https://example.com/A', $before[0]['url']);
    }

    /* ------------------------------------------------------------------
     * G6 - slashing
     * ---------------------------------------------------------------- */

    /**
     * G6. A label with backslashes, both quotes and HTML goes in through add-menu-item and
     * update-menu-item and comes out of get-menu and the database byte for byte.
     *
     * @group sprint-13
     */
    public function testATitleWithBackslashesQuotesAndHtmlRoundTrips(): void
    {
        $menuId = self::menu('g6');
        $client = $this->mcp(self::$adminToken);
        $title  = Fixtures::name('g6') . ' ' . self::TITLE;

        $id = $this->addCustom($client, $menuId, '', ['title' => $title]);

        self::assertSame($title, self::itemById($client->callTool('get-menu', ['id' => $menuId]), $id)['title']);
        self::assertSame($title, Fixtures::menuItemRows($menuId)[0]['title']);

        $second = Fixtures::name('g6b') . ' x\\y \\\\ "z" \'w\' <em>e</em>';
        $this->update($client, ['id' => $id, 'title' => $second]);

        self::assertSame($second, self::itemById($client->callTool('get-menu', ['id' => $menuId]), $id)['title']);
        self::assertSame($second, Fixtures::menuItemRows($menuId)[0]['title']);
    }

    /**
     * G6. A linked item given its page's own backslashed title stays in step with the page:
     * core compares wp_unslash() of the label with the page title and stores nothing when
     * they match (nav-menu.php:514-516) - which it only can if the label arrived slashed.
     *
     * @group sprint-13
     */
    public function testALinkedItemGivenItsPagesBackslashedTitleStaysInStep(): void
    {
        $pageTitle = Fixtures::name('g6-page') . ' C:\\menu\\page';
        $pageId    = Fixtures::createPostExact([
            'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::$editorId, 'post_title' => $pageTitle,
        ]);
        self::$posts[] = $pageId;
        self::assertSame($pageTitle, Fixtures::postField($pageId, 'post_title'), 'Premise: the fixture page lost its backslashes.');

        $menuId = self::menu('g6s');
        $client = $this->mcp(self::$adminToken);

        $added = $client->callTool('add-menu-item', ['menu_id' => $menuId, 'type' => 'page', 'object_id' => $pageId, 'title' => $pageTitle]);
        self::assertFalse($added->isError, $added->text);

        self::assertSame($pageTitle, self::itemById($client->callTool('get-menu', ['id' => $menuId]), (int) $added->data()['id'])['title']);
        self::assertSame('', Fixtures::menuItemRows($menuId)[0]['title'], 'The label was stored as a copy, not kept in step with the page.');
    }

    /* ------------------------------------------------------------------
     * list-menus: locations and the block-theme flag
     * ---------------------------------------------------------------- */

    /**
     * list-menus reports the site's registered locations and what each is assigned to,
     * each menu's own locations, and block_theme exactly as wp_is_block_theme() says - true
     * on the bare site, false on the stress site. The STORED location assignments do not
     * move.
     *
     * @group sprint-13
     */
    public function testListMenusReportsLocationsAndTheBlockTheme(): void
    {
        $site = json_decode(self::lastLine(WpCli::evaluate(
            '$locs = get_nav_menu_locations(); $out = array();'
            . ' foreach (get_registered_nav_menus() as $loc => $desc) {'
            . '  $m = !empty($locs[$loc]) ? wp_get_nav_menu_object((int) $locs[$loc]) : false;'
            . '  $out[$loc] = array("description" => $desc, "menu_id" => $m ? (int) $m->term_id : null); }'
            . ' echo "\n", wp_json_encode(array("block" => wp_is_block_theme(), "locations" => (object) $out));'
        )), true);
        self::assertIsArray($site);

        $storedBefore = Fixtures::menuLocationsRaw();
        $result       = $this->mcp(self::$adminToken)->callTool('list-menus');

        self::assertFalse($result->isError, $result->text);
        $data = $result->data();

        self::assertSame($site['block'], $data['block_theme'], 'block_theme is not what wp_is_block_theme() says.');

        $expected = $site['locations'];
        $expected[self::locationSlug()] = ['description' => self::locationDescription(), 'menu_id' => self::$locationMenuId];

        $reported = [];
        foreach ($data['locations'] as $location) {
            $reported[$location['location']] = ['description' => $location['description'], 'menu_id' => $location['menu_id']];
        }
        ksort($expected);
        ksort($reported);
        self::assertSame($expected, $reported, 'The registered locations, or what they are assigned to, are wrong.');

        $menus = array_column($data['menus'], null, 'id');
        self::assertSame(
            [['location' => self::locationSlug(), 'description' => self::locationDescription()]],
            $menus[self::$locationMenuId]['locations']
        );
        self::assertSame([], $menus[self::$plainMenuId]['locations']);
        self::assertSame(Fixtures::name('menu-unlocated'), $menus[self::$plainMenuId]['name']);
        self::assertSame(0, $menus[self::$plainMenuId]['count']);

        self::assertSame($storedBefore, Fixtures::menuLocationsRaw(), 'list-menus changed the stored location assignments.');
    }

    /* ------------------------------------------------------------------
     * Round 2 - review S3, S2, S7
     * ---------------------------------------------------------------- */

    /**
     * R2 (S3). An update that does not send parent_id carries the item's STORED parent unchanged,
     * even one pointing at an item that is gone. Core's own delete leaves exactly that pointer
     * behind (measured), and a theme's walker shows such an item at the END of the menu; rewriting
     * it to 0 moved it into the middle of a live menu. Only a parent_id the caller sends changes it.
     *
     * AND SINCE 1.1.1 THE STORED ORDER AGREES WITH THAT WALKER, which is the sentence above coming
     * true. The menu tree is built by core's `Walker::walk()` now, and it shows an item whose
     * parent is not an item of this menu AFTER every top-level tree (class-wp-walker.php:253-264);
     * the renumbering is built from the same walk, so the first write moves B1 from the middle of
     * the stored order to the end - where the theme was already showing it. Before 1.1.1 this test
     * asserted A, B1, C, which is what our own traversal produced and what this docblock had
     * already recorded as disagreeing with the site. The PARENT claim, which is the point of the
     * test, is unchanged.
     *
     * @group sprint-13
     * @group sprint-14d
     */
    public function testAnUpdateThatDoesNotSendAParentKeepsTheStoredOne(): void
    {
        [$menuId, $ids] = self::menuWithItems('r2o', ['A' => [], 'B' => ['B1' => []], 'C' => []]);
        Fixtures::deletePost($ids['B']);

        $parentOf = static fn (int $id): int => array_column(Fixtures::menuItemRows($menuId), 'parent', 'id')[$id];

        self::assertFalse(Fixtures::isNavMenuItem($ids['B']), 'Premise: B was not deleted.');
        self::assertSame($ids['B'], $parentOf($ids['B1']), 'Premise: deleting B did not leave B1 pointing at it.');

        $client = $this->mcp(self::$adminToken);
        $before = array_column(Fixtures::menuItemRows($menuId), null, 'id');

        $this->update($client, ['id' => $ids['B1'], 'title' => Fixtures::name('r2o-B1-renamed')]);
        self::assertSame($ids['B'], $parentOf($ids['B1']), 'A title-only update rewrote the stored parent.');

        $this->update($client, ['id' => $ids['B1'], 'target' => '_blank']);
        self::assertSame($ids['B'], $parentOf($ids['B1']), 'A target-only update rewrote the stored parent.');

        $after = array_column(Fixtures::menuItemRows($menuId), null, 'id');
        self::assertSame(
            [$ids['A'], $ids['C'], $ids['B1']],
            array_keys($after),
            'The stored order is not the one core\'s walker prints: every top-level tree, then'
            . ' every item whose parent it cannot find. B1 is an orphan here - B was deleted - so'
            . ' it belongs at the end, which is where wp_nav_menu() has been showing it all along.'
        );

        foreach ([$ids['A'], $ids['C']] as $id) {
            self::assertSame(
                [$before[$id]['title'], $before[$id]['url'], $before[$id]['parent'], $before[$id]['status']],
                [$after[$id]['title'], $after[$id]['url'], $after[$id]['parent'], $after[$id]['status']],
                "Item {$id}, which nobody named, changed."
            );
        }

        self::assertSame($before[$ids['B1']]['url'], $after[$ids['B1']]['url'], 'An update that sent no url changed it.');
        self::assertSame(Fixtures::name('r2o-B1-renamed'), $after[$ids['B1']]['title']);

        // A parent_id the caller SENDS is what changes it.
        $this->update($client, ['id' => $ids['B1'], 'parent_id' => 0]);
        self::assertSame(0, $parentOf($ids['B1']), 'parent_id 0, sent, did not repair the pointer.');
    }

    /**
     * R2 (S2). A draft item - in the menu, not what visitors see - is listed only to a caller who
     * can edit theme options, as core's REST menu-items endpoint does: the collection defaults to
     * publish and refuses any other status without edit_theme_options (measured: an Editor and an
     * Author get 400 for status=draft, 403 for the draft item itself).
     *
     * @group sprint-13
     */
    public function testADraftItemIsListedOnlyToACallerWhoCanEditThemeOptions(): void
    {
        $menuId = self::menu('r2d');
        $public = Fixtures::createMenuItem($menuId, [
            'menu-item-title' => Fixtures::name('r2d-public'), 'menu-item-url' => 'https://example.com/r2d-public',
        ]);
        $draft = Fixtures::createMenuItem($menuId, [
            'menu-item-title' => Fixtures::name('r2d-draft-label'), 'menu-item-url' => 'https://example.com/r2d-draft-secret',
            'menu-item-status' => 'draft',
        ]);
        self::assertSame(['publish', 'draft'], array_column(Fixtures::menuItemRows($menuId), 'status'), 'Premise: the fixture statuses.');

        foreach (['Editor' => self::$editorToken, 'Author' => self::$authorToken] as $role => $token) {
            $client = $this->mcp($token);
            $menu   = $client->callTool('get-menu', ['id' => $menuId]);

            self::assertFalse($menu->isError, $menu->text);
            self::assertSame([$public], array_column(self::flatten($menu->data()['items']), 'id'), "An {$role} was shown a draft item.");
            self::assertSame(1, $menu->data()['count'], "get-menu counted a draft item for an {$role}.");
            self::assertStringNotContainsString(Fixtures::name('r2d-draft-label'), $menu->text);
            self::assertStringNotContainsString('r2d-draft-secret', $menu->text);

            $list = $client->callTool('list-menus');
            self::assertFalse($list->isError, $list->text);
            self::assertSame(1, array_column($list->data()['menus'], 'count', 'id')[$menuId], "list-menus counted a draft item for an {$role}.");
        }

        $admin = $this->mcp(self::$adminToken)->callTool('get-menu', ['id' => $menuId]);
        self::assertSame([$public, $draft], array_column(self::flatten($admin->data()['items']), 'id'), 'An Administrator was not shown the draft item.');
        self::assertSame('draft', self::itemById($admin, $draft)['status']);
        self::assertSame(2, $admin->data()['count']);
    }

    /**
     * R2 (S7). mailto: and tel: links - a contact menu's - are accepted on add and on update and
     * stored as sent, and //host stays allowed. A url with a backslash is refused on both: browsers
     * read /\host as //host, and WordPress's own cleaning would store a different link from the one
     * sent (measured: /\host becomes the path /host, \\host becomes http://host).
     *
     * @group sprint-13
     */
    public function testMailtoAndTelAreAcceptedAndABackslashUrlIsRefused(): void
    {
        [$menuId, $ids] = self::menuWithItems('r2u', ['A' => []]);
        $client = $this->mcp(self::$adminToken);

        $mail = $this->addCustom($client, $menuId, 'r2u-mail', ['url' => 'mailto:hello@example.com']);
        $tel  = $this->addCustom($client, $menuId, 'r2u-tel', ['url' => 'tel:+15551234567']);
        $cdn  = $this->addCustom($client, $menuId, 'r2u-cdn', ['url' => '//cdn.example/x']);
        $this->update($client, ['id' => $ids['A'], 'url' => 'tel:+15550000000']);

        $urls = array_column(Fixtures::menuItemRows($menuId), 'url', 'id');
        self::assertSame('mailto:hello@example.com', $urls[$mail]);
        self::assertSame('tel:+15551234567', $urls[$tel]);
        self::assertSame('//cdn.example/x', $urls[$cdn]);
        self::assertSame('tel:+15550000000', $urls[$ids['A']]);

        $before = Fixtures::menuItemRows($menuId);

        foreach (['/\\evil.example/x', '\\/evil.example/x', '\\\\evil.example/x'] as $url) {
            $add = $client->callTool('add-menu-item', [
                'menu_id' => $menuId, 'type' => 'custom', 'url' => $url, 'title' => Fixtures::name('r2u-never'),
            ]);
            self::assertTrue($add->isError, "add-menu-item accepted {$url}: " . $add->text);
            self::assertStringContainsString('url', $add->text);

            $update = $client->callTool('update-menu-item', ['id' => $mail, 'url' => $url]);
            self::assertTrue($update->isError, "update-menu-item accepted {$url}: " . $update->text);
            self::assertStringContainsString('url', $update->text);
        }

        self::assertSame($before, Fixtures::menuItemRows($menuId), 'A refused url changed the menu.');
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /**
     * B-TITLE, menus. A linked item with no label of its own shows the linked page's
     * title, and core's wp_setup_nav_menu_item() runs that through the_title
     * (nav-menu.php:897) - so a page titled with a straight quote came back texturized,
     * while a custom label (the item's own post_title) did not. Both are raw now.
     *
     * @group sprint-13
     */
    public function testALinkedItemLabelComesBackAsThePageStoresIt(): void
    {
        $pageTitle = Fixtures::name('g7-page') . ' A\\B "quoted" it\'s & more';
        $pageId    = Fixtures::createPost($pageTitle, 'publish', self::$editorId, 'x', 'page');
        self::$posts[] = $pageId;

        $menuId = self::menu('g7');
        $client = $this->mcp(self::$adminToken);

        $added = $client->callTool('add-menu-item', ['menu_id' => $menuId, 'type' => 'page', 'object_id' => $pageId]);
        self::assertFalse($added->isError, $added->text);

        $item = self::itemById($client->callTool('get-menu', ['id' => $menuId]), (int) $added->data()['id']);

        self::assertSame($pageTitle, $item['title'], 'The linked label is not the page title as stored.');
        self::assertSame('', Fixtures::menuItemRows($menuId)[0]['title'], 'The label was stored as a copy, not left to follow the page.');
        self::assertSame($pageTitle, Fixtures::postField($pageId, 'post_title'), 'The page title itself changed.');
    }

    private static function menu(string $what): int
    {
        $id = Fixtures::createMenu(Fixtures::name('menu-' . $what));
        self::$menus[] = $id;

        return $id;
    }

    /**
     * A fixture menu of custom links shaped like $tree (label => children), created through
     * core in depth-first order - so the stored orders are CORE'S: 0 for the first item,
     * then counting up from 2 (measured), not a contiguous 1..N.
     *
     * @return array{0:int, 1:array<string, int>}
     */
    /**
     * An id of the right KIND whose integer does not also name one of this site's nav_menu terms.
     *
     * WHY THIS IS NOT PARANOIA. `wp_get_nav_menu_object()` takes an integer and looks it up as a
     * term, so "a page id" and "a menu id" are the same kind of thing to it - they are just
     * integers, from two counters that both start at 1. A test that wants to say "this id is NOT a
     * menu" therefore cannot pick an id and assume; it has to look, and move on if it lost the
     * coin toss. On a full run the two counters are hundreds apart by the time this class starts
     * and the toss is never lost; on a shard, or on a fresh site, it is.
     *
     * $another must return a fresh id OF THE SAME KIND - a page for a page, a menu item for a
     * menu item - or the case under test changes into a different case. Bounded, because an
     * unbounded retry against a broken fixture is a hanging test: post ids only go up and this
     * site has a handful of menus, so one retry is already generous.
     *
     * @param callable():int $another
     */
    private static function idThatIsNotAMenu(int $candidate, callable $another): int
    {
        for ($tries = 0; $tries < 4; $tries++) {
            if (!Fixtures::isNavMenu($candidate)) {
                return $candidate;
            }

            $candidate = $another();
        }

        self::fail(
            "Could not find an id that is not one of this site's menus after four tries; the last"
            . " was {$candidate}. Either the fixture factory is returning the same id every time or"
            . ' this site has an implausible number of menus.'
        );
    }

    private static function menuWithItems(string $what, array $tree): array
    {
        $menuId = self::menu($what);
        $ids    = [];

        $walk = static function (array $nodes, int $parent) use (&$walk, &$ids, $menuId, $what): void {
            foreach ($nodes as $label => $children) {
                $label       = (string) $label;
                $ids[$label] = Fixtures::createMenuItem($menuId, [
                    'menu-item-title'     => Fixtures::name($what . '-' . $label),
                    'menu-item-url'       => 'https://example.com/' . $label,
                    'menu-item-parent-id' => $parent,
                ]);
                $walk($children, $ids[$label]);
            }
        };
        $walk($tree, 0);

        return [$menuId, $ids];
    }

    /** @return list<array{0:string, 1:array}> read, read, write, write, write */
    private static function oneCallOfEachTool(int $menuId, int $itemId, int $otherItemId): array
    {
        return [
            ['list-menus', []],
            ['get-menu', ['id' => $menuId]],
            ['add-menu-item', ['menu_id' => $menuId, 'type' => 'custom', 'url' => 'https://example.com/never', 'title' => Fixtures::name('never')]],
            ['update-menu-item', ['id' => $itemId, 'title' => Fixtures::name('never')]],
            ['remove-menu-item', ['id' => $otherItemId]],
        ];
    }

    private function addCustom(McpClient $client, int $menuId, string $what, array $extra): int
    {
        $result = $client->callTool('add-menu-item', $extra + [
            'menu_id' => $menuId, 'type' => 'custom', 'url' => 'https://example.com/' . $what, 'title' => Fixtures::name($what),
        ]);
        self::assertFalse($result->isError, $result->text);

        return (int) $result->data()['id'];
    }

    private function update(McpClient $client, array $arguments): void
    {
        $result = $client->callTool('update-menu-item', $arguments);
        self::assertFalse($result->isError, $result->text);
    }

    /** The stored order is exactly $ids, numbered 1..N. */
    private function assertOrder(int $menuId, array $ids): void
    {
        $rows = Fixtures::menuItemRows($menuId);

        self::assertSame($ids, array_column($rows, 'id'), 'The items are not in the expected order.');
        self::assertSame(range(1, count($ids)), array_column($rows, 'order'), 'The stored order is not contiguous.');
    }

    /** get-menu's tree, depth first. */
    private static function flatten(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = $item;
            array_push($out, ...self::flatten($item['children'] ?? []));
        }

        return $out;
    }

    private static function itemById(ToolResult $menu, int $id): array
    {
        self::assertFalse($menu->isError, $menu->text);

        foreach (self::flatten($menu->data()['items']) as $item) {
            if ($item['id'] === $id) { return $item; }
        }

        self::fail("get-menu has no item {$id}: " . $menu->text);
    }

    private function assertSameNotFound(McpClient $client, string $tool, array $refused, array $missing): void
    {
        $answer  = $client->callTool($tool, $refused);
        $control = $client->callTool($tool, $missing);

        self::assertTrue($control->isError, "{$tool} did not refuse a missing id.");
        self::assertTrue($answer->isError, "{$tool} answered " . json_encode($refused) . ': ' . $answer->text);
        self::assertSame(
            $control->text,
            $answer->text,
            "{$tool} answers " . json_encode($refused) . ' differently from a missing id, so'
            . ' probing ids tells a caller something exists.'
        );
    }

    private static function lastLine(string $out): string
    {
        return trim((string) substr($out, (int) strrpos("\n" . $out, "\n")));
    }

    /**
     * Registers one fixture location and maps it to $menuId - for THIS RUN'S REQUESTS ONLY.
     * Nothing is stored: `theme_mod_nav_menu_locations` filters what get_nav_menu_locations()
     * reads, and every other request on the site, wp-cli included, sees the real mod.
     */
    private static function locationSource(int $menuId): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $slug   = self::locationSlug();
        $desc   = self::locationDescription();

        return <<<PHP
/**
 * wp-mcp sprint-13 menu-location fixture for run {$run}. Dropped and removed by
 * tests/integration/MenuToolsTest.php. Gated on this run's request header; stores nothing.
 */
if (!isset(\$_SERVER['{$header}']) || \$_SERVER['{$header}'] !== '{$run}') {
    return;
}

add_action('after_setup_theme', static function () {
    register_nav_menus(array('{$slug}' => '{$desc}'));
}, 999);

add_filter('theme_mod_nav_menu_locations', static function (\$locations) {
    \$locations = is_array(\$locations) ? \$locations : array();
    \$locations['{$slug}'] = {$menuId};
    return \$locations;
});
PHP;
    }
}
