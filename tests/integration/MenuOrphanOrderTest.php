<?php
/**
 * WHERE AN ORPHANED MENU ITEM APPEARS (1.1.1, sprint SWAP item 4, decision D7).
 *
 * `wpmcp_menu_shape()` / `flatten()` / `tree()` became core's `Walker::walk()` with a
 * collecting subclass, and this is the ONE thing a caller can observe about the swap. An
 * "orphan" here means an item of this menu whose stored `_menu_item_menu_item_parent` names a
 * row that is not an item of this menu - deleted, or in another menu. Our own traversal showed
 * it interleaved at the top level by `menu_order`; core's walker shows every orphan AFTER every
 * top-level tree, flat (class-wp-walker.php:253-264), which is where `wp_nav_menu()` puts it.
 * So get-menu's `position` now agrees with what a visitor sees, by construction.
 *
 * NOTHING OBSERVABLE CHANGES ON A REAL SITE, which is why this is a free swap: D7 measured ZERO
 * orphaned items on jaygroup (81 items in 5 menus) and on seosemia (44 items), and re-measuring
 * the five jaygroup menus after the swap gave identical order and identical parents. The
 * CHANGELOG names the move for anybody else's site, and this class is where the new rule is
 * held - on a menu built to have an orphan, because no real one does.
 *
 * THE FIXTURE IS BUILT SO THE TWO ANSWERS DIFFER. The orphan is created FIRST, so it holds the
 * lowest `menu_order`: interleaved-by-menu_order puts it first, and after-every-tree puts it
 * last. A fixture where the orphan happened to be last already would pass either way.
 *
 * AND THERE ARE TWO MENUS OF THE SAME SHAPE, because this suite runs with
 * `executionOrder="depends,defects"` - a method that failed last time runs FIRST. The write test
 * renumbers and adds an item, so it gets a menu nobody else reads; the read tests get one nothing
 * writes to. A class whose methods pass only in declaration order is a class that goes green on
 * the second run for the wrong reason, which is how this one first failed.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;

final class MenuOrphanOrderTest extends FixtureIntegrationTestCase
{
    /** No item of any menu can have this id, so a parent pointing at it is an orphan. */
    private const MISSING_PARENT = 999999999;

    private static int $menuId = 0;
    private static int $writeMenuId = 0;
    private static int $userId = 0;
    private static string $token = '';
    private static int $orphan = 0;
    private static int $top = 0;
    private static int $child = 0;
    private static int $second = 0;

    /** @var array<int, array<string, int>> each menu's four items, by role */
    private static array $items = [];

    private static function label(): string { return Fixtures::name('menu-orphan'); }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$userId = Fixtures::createUser(Fixtures::name('orphan-admin'), 'administrator');
        self::$token  = Fixtures::mintToken('admin', self::label(), self::$userId);

        self::$menuId      = self::buildMenu('read');
        self::$writeMenuId = self::buildMenu('write');

        $read = self::$items[self::$menuId];

        self::$orphan = $read['orphan'];
        self::$top    = $read['top'];
        self::$child  = $read['child'];
        self::$second = $read['second'];
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    /**
     * One menu holding: an orphan FIRST (so it has the lowest menu_order), a top-level item with
     * a child, and a second top-level item. The ids are remembered by role, because the same
     * shape is built twice - see the class docblock for why.
     */
    private static function buildMenu(string $which): int
    {
        $menuId = Fixtures::createMenu(Fixtures::name('orphan-' . $which . '-menu'));

        // FIRST, so it holds the lowest menu_order and the two orderings disagree.
        $orphan = Fixtures::createMenuItem($menuId, [
            'menu-item-title' => Fixtures::name('orphan-' . $which . '-item'),
            'menu-item-url'   => 'https://example.com/orphan',
        ]);
        $top = Fixtures::createMenuItem($menuId, [
            'menu-item-title' => Fixtures::name('orphan-' . $which . '-top'),
            'menu-item-url'   => 'https://example.com/top',
        ]);
        $child = Fixtures::createMenuItem($menuId, [
            'menu-item-title'     => Fixtures::name('orphan-' . $which . '-child'),
            'menu-item-url'       => 'https://example.com/child',
            'menu-item-parent-id' => $top,
        ]);
        $second = Fixtures::createMenuItem($menuId, [
            'menu-item-title' => Fixtures::name('orphan-' . $which . '-second'),
            'menu-item-url'   => 'https://example.com/second',
        ]);

        // AND NOW IT IS ORPHANED: its stored parent names a row that is not an item of this
        // menu. This is the state a deleted parent leaves behind on somebody else's site, and
        // the state neither real test site has.
        Fixtures::setPostMeta($orphan, '_menu_item_menu_item_parent', (string) self::MISSING_PARENT);

        self::$items[$menuId] = [
            'orphan' => $orphan, 'top' => $top, 'child' => $child, 'second' => $second,
        ];

        return $menuId;
    }

    private static function destroy(): void
    {
        Fixtures::deleteMenu(self::$writeMenuId);
        Fixtures::deleteMenu(self::$menuId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /**
     * The control: the fixture really is orphaned, and it really does hold the lowest
     * menu_order - so "last" below is a change of behaviour and not an accident of the data.
     *
     * @group sprint-14d
     */
    public function testTheFixtureIsAnOrphanWithTheLowestMenuOrder(): void
    {
        $rows = Fixtures::menuItemRows(self::$menuId);
        $byId = [];

        foreach ($rows as $row) { $byId[(int) $row['id']] = $row; }

        self::assertCount(4, $rows, 'The fixture menu does not hold four items.');
        self::assertSame(
            self::MISSING_PARENT,
            (int) $byId[self::$orphan]['parent'],
            'The orphan\'s stored parent is not the missing id, so nothing here is an orphan.'
        );
        self::assertSame(
            self::$orphan,
            (int) $rows[0]['id'],
            'The orphan does not hold the lowest menu_order, so the old interleaved ordering'
            . ' and the new after-every-tree ordering would agree and this class would pass'
            . ' whichever one was in force.'
        );
    }

    /**
     * get-menu shows the orphan AFTER every top-level tree, and the tree itself is intact.
     *
     * @group sprint-14d
     */
    public function testGetMenuShowsAnOrphanAfterEveryTree(): void
    {
        $items = $this->mcp(self::$token)->callTool('get-menu', ['id' => self::$menuId])->data()['items'];

        $top = array_map(static fn (array $i) => (int) $i['id'], $items);

        self::assertSame(
            [self::$top, self::$second, self::$orphan],
            $top,
            'The top level of get-menu is not "every tree, then the orphans". Core\'s walker'
            . ' shows an item whose parent it cannot find after every top-level element'
            . ' (class-wp-walker.php:253-264), which is where wp_nav_menu() shows it - so this'
            . ' is the one thing the swap changed, and it is what makes get-menu\'s position'
            . ' agree with the site.'
        );

        self::assertSame(
            [self::$child],
            array_map(static fn (array $i) => (int) $i['id'], $items[0]['children']),
            'The real tree lost its child, so the walker is not nesting at all.'
        );
        self::assertSame([], $items[2]['children'], 'The orphan has children it never had.');

        // POSITION IS PER SIBLING LIST, FROM 1, and the orphan is now the third top-level
        // element rather than the first - which is the number a caller passes back to
        // update-menu-item, so it has to agree with the order it was printed in.
        self::assertSame([1, 2, 3], array_map(static fn (array $i) => (int) $i['position'], $items));
        self::assertSame(
            0,
            (int) $items[2]['parent'],
            'The orphan reports its missing stored parent instead of 0. The parent a caller is'
            . ' shown has to be the one the item is DISPLAYED under, or position means nothing.'
        );
    }

    /**
     * AND THE NEXT WRITE PERSISTS IT. The renumber is built from the same walk, so a write
     * anywhere in the menu moves the stored `menu_order` to the order get-menu printed.
     *
     * THIS IS THE PART TO NAME IN THE CHANGELOG. On a site that has an orphan, the first write
     * after upgrading rewrites `menu_order` - not because the tool was asked to, but because the
     * renumber's job is to make 1..N mean the displayed order, and the displayed order moved.
     *
     * @group sprint-14d
     */
    public function testAWriteRenumbersTheMenuIntoTheWalkersOrder(): void
    {
        $write = self::$items[self::$writeMenuId];

        $added = $this->mcp(self::$token)->callTool('add-menu-item', [
            'menu_id' => self::$writeMenuId,
            'type'    => 'custom',
            'title'   => Fixtures::name('orphan-added'),
            'url'     => 'https://example.com/added',
        ]);
        self::assertFalse($added->isError, $added->text);

        $order = [];

        foreach (Fixtures::menuItemRows(self::$writeMenuId) as $row) {
            $order[(int) $row['id']] = (int) $row['order'];
        }

        self::assertGreaterThan(
            $order[$write['second']],
            $order[$write['orphan']],
            'The stored menu_order still puts the orphan before the trees, so the renumber and'
            . ' get-menu disagree - which is the state this swap exists to make impossible.'
        );
        self::assertSame(
            [1, 2, 3],
            [$order[$write['top']], $order[$write['child']], $order[$write['second']]],
            'The real tree was not numbered depth-first from 1: ' . json_encode($order)
        );
    }
}
