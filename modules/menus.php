<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP MODULE: classic menus. list-menus / get-menu / add-menu-item /
 * update-menu-item / remove-menu-item.
 *
 * THIS FILE IS A MOVE, NOT A REWRITE. Every line below the registration call came out of
 * tools.php at cf326b9 - lines 3798-3846 (the Walker subclass) and 5318-6357 (everything
 * else) - byte for byte, docblocks and all, including the ones that could be better. The
 * sprint that moved it had the menu tests as its net, and a net only proves "identical"
 * while the code is identical; an improvement made on the way past would have turned a
 * green suite into a green suite that proved nothing. Improve it in a later commit, where
 * the diff is about the improvement.
 *
 * WHAT MAKES IT A MODULE: the one statement at the end of this file. It hands the seam a
 * slug and the name of the function that builds the tool array, and that is the whole of
 * the module's side of the contract - see modules.php for the other side, which is the
 * part that matters: nothing here is trusted. The array this file returns is checked on
 * the way OUT of registration, entry by entry, against the same rules a third-party
 * `wpmcp_tools` filter entry has always faced, plus one this file could not otherwise be
 * stopped from breaking - it may not re-declare a name the core already uses.
 *
 * WHAT THIS FILE MAY CALL, and the list is short because it was already short: four core
 * helpers in tools.php - wpmcp_cannot(), wpmcp_decode_specialchars(), wpmcp_post_type_ok()
 * and wpmcp_raw_title() - plus WordPress itself. It calls nothing in endpoint.php, nothing
 * in admin.php, and nothing in trace.php: a module does not reach into the request path,
 * the settings screen or the log. ARCHITECTURE.md's "The module seam" states the rule; this
 * paragraph is what it looks like from inside a module.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * `Walker` with the HTML taken out: it collects instead of printing.
 *
 * `Walker::walk()` needs three things from a subclass - which field is the id, which is the
 * parent, and what to do with each element - and nothing else. `start_el()` is called once
 * per element, in the order core shows them, with the depth core shows them at, so the
 * parent an item is DISPLAYED under is the entry one level up the stack. That is the whole
 * subclass; every ordering decision stays in core.
 *
 * `$db_fields` names the two properties wpmcp_menu_walk() decorates its objects with. They
 * are core's own names for them (`db_id`, `menu_item_parent`) so that anybody reading this
 * beside `wp_nav_menu()` sees the same two fields, but the VALUES are ours, read from the
 * row and the meta rather than from `wp_setup_nav_menu_item()`.
 *
 * end_el(), start_lvl() and end_lvl() are left as the base class's empty methods: the
 * nesting is recovered from `depth`, so there is nothing to do at a level boundary.
 */
class WpMcp_Menu_Collector extends Walker {

    /** @var array<string, string> */
    public $db_fields = array('parent' => 'menu_item_parent', 'id' => 'db_id');

    /** @var list<array{id: int, parent: int, depth: int}> */
    public $flat = array();

    /** @var array<int, int> depth => the id currently open at that depth */
    private $open = array();

    /**
     * @param string   $output      unused: nothing is printed
     * @param object   $data_object one decorated row
     * @param int      $depth       core's depth for it, 0 at the top level
     * @param array    $args        unused
     * @param int      $current_object_id unused
     */
    public function start_el(&$output, $data_object, $depth = 0, $args = array(), $current_object_id = 0) {
        $depth              = (int) $depth;
        $id                 = (int) $data_object->db_id;
        $this->open[$depth] = $id;

        $this->flat[] = array(
            'id'     => $id,
            // THE DISPLAYED PARENT. 0 at the top level and 0 for an orphan, which core shows
            // at depth 0 whatever its stored parent says.
            'parent' => ($depth > 0 && isset($this->open[$depth - 1])) ? $this->open[$depth - 1] : 0,
            'depth'  => $depth,
        );
    }
}

/* ============================================================
 * Classic menu tools (list-menus / get-menu / add-menu-item / update-menu-item /
 * remove-menu-item)
 *
 * CLASSIC MENUS ONLY: the `nav_menu` taxonomy and its `nav_menu_item` posts - what a
 * classic theme's wp_nav_menu() renders and what Appearance > Menus edits. A block theme's
 * Navigation block keeps its links in `wp_navigation` posts, which nothing here reads or
 * writes; list-menus reports block_theme so an agent knows when a classic menu it changes
 * may not be what visitors see.
 *
 * TWO GATES, AND THEY ARE CORE'S. Reading is the REST menus controllers' rule
 * (wpmcp_menu_can_read), so an Editor - the marketing role, no edit_theme_options - reads
 * menus. Writing is edit_theme_options (wpmcp_menu_can_write). Each gate runs first, before
 * any id is looked at, so a caller who may not read or write learns nothing about which
 * ids exist.
 *
 * ONE NOT_FOUND per kind of id: a menu id that is not a `nav_menu` term, and an item id that
 * is not a `nav_menu_item` in a menu, answer exactly as an id nobody used.
 *
 * THREE THINGS CORE LEAVES TO THE CALLER, measured on both sites (WP 7.1):
 *   - ORDER. wp_update_nav_menu_item() stores the position it is given and moves no other
 *     item; a new menu's first item gets 0. Every write here renumbers the menu 1..N, depth
 *     first, as wp-admin's JavaScript does before it saves (wpmcp_menu_renumber).
 *   - CHILDREN of a deleted item keep pointing at the deleted id. remove-menu-item lifts
 *     them to the removed item's parent, in place, as wp-admin's removeMenuItem does
 *     (wp-admin/js/nav-menu.js:1844).
 *   - WHAT A READER MAY SEE. wp_setup_nav_menu_item() resolves a linked item's label and
 *     link with no capability check; get-menu withholds both for content the caller may
 *     not read (wpmcp_menu_item_visible).
 *
 * SLASHING (KB 0.27). wp_update_nav_menu_item() hands the title, description and attribute
 * title to wp_insert_post()/wp_update_post(), which unslash, and compares
 * wp_unslash( $title ) with a linked object's title (nav-menu.php:514); core's REST
 * controller slashes its whole array first (menu-items controller :139, :232), and so does
 * every write below. The renumbering and the re-parenting pass ids and integers only.
 * ========================================================== */

/**
 * May the caller READ classic menus? Core's REST rule, restated: edit_theme_options, or
 * edit_posts, or the edit_posts capability of any post type shown in REST
 * (class-wp-rest-menus-controller.php:86-112; the menu-items controller makes the same check).
 * An Editor passes, a Subscriber does not.
 *
 * Core consults the `rest_menu_read_access` filter first; its callbacks are handed a
 * WP_REST_Request and a controller, and this is neither, so it is not applied here.
 */
function wpmcp_menu_can_read() {
    if (current_user_can('edit_theme_options') || current_user_can('edit_posts')) { return true; }

    foreach (get_post_types(array('show_in_rest' => true), 'objects') as $type) {
        if (current_user_can($type->cap->edit_posts)) { return true; }
    }

    return false;
}

/**
 * May the caller WRITE classic menus? edit_theme_options.
 *
 * That is what wp-admin's Menus screen requires, and it is what every capability the REST
 * menu-items controller checks resolves to - MEASURED for administrator, editor, author,
 * contributor and subscriber on both sites: nav_menu_item's create_posts, edit_post and
 * delete_post, and the nav_menu taxonomy's assign_terms, all map to edit_theme_options
 * (post.php:163-181, taxonomy.php:124-129). One capability, so one check.
 */
function wpmcp_menu_can_write() {
    return current_user_can('edit_theme_options');
}

/** A classic menu by id, or null. An id that is not a `nav_menu` term is no menu. */
function wpmcp_menu_get($id) {
    $id = (int) $id;
    if ($id <= 0) { return null; }

    $term = get_term($id, 'nav_menu');

    return ($term instanceof WP_Term) ? $term : null;
}

/**
 * A menu item and the menu it belongs to, or null - for an id that is not a nav_menu_item,
 * an item in no menu (core's draft orphans), and an item in the trash alike.
 *
 * @return array{item: WP_Post, menu: WP_Term}|null
 */
function wpmcp_menu_item_get($id) {
    $id   = (int) $id;
    $post = $id > 0 ? get_post($id) : null;

    if (!$post || $post->post_type !== 'nav_menu_item') { return null; }
    if (!in_array($post->post_status, array('publish', 'draft'), true)) { return null; }

    $menus = wp_get_object_terms($id, 'nav_menu', array('fields' => 'ids'));
    if (is_wp_error($menus) || empty($menus)) { return null; }

    $menu = wpmcp_menu_get((int) $menus[0]);

    return $menu ? array('item' => $post, 'menu' => $menu) : null;
}

/**
 * The items of one menu, in the order it holds them: menu_order, then ID.
 *
 * ITS OWN QUERY, not wp_get_nav_menu_items(), which is wrong for this twice over. On any
 * request that is not wp-admin - a REST request is not - it DROPS an item whose linked page
 * is trashed or gone (nav-menu.php:749-751), so a renumbering built on it would skip that
 * item and leave it holding a number another item now has. And it rewrites menu_order to
 * 1..N on the way out (:753-766), so it cannot show what the database holds.
 *
 * DRAFTS ONLY FOR THOSE WHO MAY SEE THEM. The writers take the default, draft and publish both,
 * as wp-admin's own save reads them: a renumbering has to number every row. The readers pass
 * current_user_can('edit_theme_options'), which is core's REST rule. The menu-items collection
 * defaults to `publish` (class-wp-rest-posts-controller.php:3126-3127) and refuses any other
 * status without the type's edit_posts (:3207), which for nav_menu_item is edit_theme_options
 * (post.php:170); a single draft needs read_post (:1791). Measured on both sites: an Editor and
 * an Author get the published item only, 400 for status=draft, 403 for the draft item itself.
 *
 * TIES: menu_order, then ID - which is what visitors see. Core's own query orders by menu_order
 * alone, and MySQL 8.4 returned tied rows in ID order in every case measured on both sites:
 * four tied fixture menus of 12 and 80 items, post dates reversed against ids included, through
 * wp_get_nav_menu_items() and through its raw SQL alike.
 *
 * @return list<WP_Post>
 */
function wpmcp_menu_rows($menu, $withDrafts = true) {
    return array_values(get_posts(array(
        'post_type'        => 'nav_menu_item',
        'post_status'      => $withDrafts ? array('publish', 'draft') : array('publish'),
        'numberposts'      => -1,
        'orderby'          => array('menu_order' => 'ASC', 'ID' => 'ASC'),
        'suppress_filters' => true,
        'tax_query'        => array(array(
            'taxonomy' => 'nav_menu',
            'field'    => 'term_taxonomy_id',
            'terms'    => (int) $menu->term_taxonomy_id,
        )),
    )));
}

/**
 * The stored parent of one menu-item row, with the ONE correction core also makes.
 *
 * `_menu_item_menu_item_parent` is what the database holds. An item that is its own parent
 * is read as top level, which is exactly what core does with it
 * (nav-menu.php:584-586, and _wp_reset_invalid_menu_item_parent since 6.2) - a value the
 * wp-admin form can produce and that nothing downstream could otherwise terminate on.
 *
 * A parent that names no item of this menu is left ALONE, deliberately: it is the definition
 * of an orphan, and core's walker is what decides where an orphan appears. See
 * wpmcp_menu_walk().
 */
function wpmcp_menu_stored_parent($id) {
    $parent = (int) get_post_meta((int) $id, '_menu_item_menu_item_parent', true);

    return $parent === (int) $id ? 0 : $parent;
}

/**
 * Every item of $rows in the order CORE shows them: id, the parent it is shown under, depth.
 *
 * THIS IS core's `Walker::walk()` AND NOT OUR OWN TRAVERSAL (1.1.1). ~110 lines of
 * parent-map building, depth-first recursion and orphan handling used to live here
 * (wpmcp_menu_shape + wpmcp_menu_flatten + the closure inside wpmcp_menu_tree). `Walker` is
 * the engine every classic theme's `wp_nav_menu()` renders through
 * (wp-includes/class-wp-walker.php:194, since 2.1), so ordering the same rows through it
 * makes drift between get-menu's `position` and what a visitor sees impossible by
 * construction - our own update-menu-item docblock already conceded the two disagreed.
 *
 * THE BASE CLASS, NEVER `Walker_Nav_Menu`. `Walker` itself runs no filter at all: `walk()`
 * only buckets elements by parent and calls the subclass's start_el/end_el.
 * `Walker_Nav_Menu` emits HTML and runs `nav_menu_item_title`, `nav_menu_css_class` and
 * more - the display-versus-storage rule refuses it, as it refuses `get_the_title()`.
 *
 * AND OUR OWN ROWS, NEVER `wp_get_nav_menu_items()`. That maps every row through
 * `wp_setup_nav_menu_item()`, which runs `the_title` on a linked post, DROPS an item whose
 * linked object is trashed or gone, and rewrites menu_order to 1..N in memory - so a
 * renumbering built on it would skip rows and leave two items on one number. The decorated
 * objects handed to the walker here carry two fields and nothing else.
 *
 * WHAT CHANGED FOR A CALLER, and it is the one thing: AN ORPHAN MOVES. An item whose stored
 * parent is not an item of this menu used to be shown interleaved at the top level by
 * menu_order; `Walker::walk()` shows every orphan after every top-level tree, flat
 * (class-wp-walker.php:253-264), which is where `wp_nav_menu()` puts it. MEASURED 2026-09-21
 * on both real sites - jaygroup (81 items in 5 menus) and seosemia (44 items) - ZERO items
 * have a missing parent, so nothing observable changes on either; the CHANGELOG names it for
 * anybody else's site, because the next write persists the new order through the renumber.
 *
 * Two smaller differences of core's, recorded because they are core's and not ours: an
 * orphan's own children are shown flat rather than nested under it (the walker passes an
 * empty children array for an orphan, :258-263), and a menu where NO item has parent 0 takes
 * the first row as the root of the rest (:232-247) where our traversal showed all of them at
 * the top level. Neither can occur on a menu wp-admin built.
 *
 * THE PARENT REPORTED IS THE ONE THE ITEM IS SHOWN UNDER, read off the walker's depth stack
 * rather than off the row, so an orphan reports parent 0 - as it did before, and as its
 * position in the output now agrees with.
 *
 * @param list<WP_Post> $rows
 * @return list<array{id: int, parent: int, depth: int}>
 */
function wpmcp_menu_walk($rows) {
    $elements = array();

    foreach ($rows as $row) {
        $element                   = new stdClass();
        $element->db_id            = (int) $row->ID;
        $element->menu_item_parent = wpmcp_menu_stored_parent($row->ID);
        $elements[]                = $element;
    }

    if ($elements === array()) { return array(); }

    $walker = new WpMcp_Menu_Collector();
    // max_depth 0 is "every level", which is also the only value whose orphan block runs.
    $walker->walk($elements, 0);

    return $walker->flat;
}

/**
 * The shape of a menu, as core's walker shows it, plus the shape the DATABASE holds.
 *
 * `parents` and `children` are the SHOWN structure - the walker's, so an orphan is a child of
 * 0 and the children lists are in the order get-menu prints and the renumber writes.
 *
 * `stored` is keyed by the parent each row actually names, orphans included, and exists for
 * exactly one job: the cycle refusal in update-menu-item. A "would this parent put the item
 * inside itself" question has to be asked of the data, not of the rendering - in the shown
 * structure an orphan's children hang from 0, so a cycle among unreachable rows would be
 * invisible and update-menu-item would happily complete it.
 *
 * @return array{parents: array<int, int>, children: array<int, list<int>>, stored: array<int, list<int>>}
 */
function wpmcp_menu_shape($rows) {
    $parents  = array();
    $children = array(0 => array());

    foreach (wpmcp_menu_walk($rows) as $item) {
        $parents[$item['id']]        = $item['parent'];
        $children[$item['parent']][] = $item['id'];
    }

    $stored = array(0 => array());

    foreach ($rows as $row) {
        $stored[wpmcp_menu_stored_parent($row->ID)][] = (int) $row->ID;
    }

    return array('parents' => $parents, 'children' => $children, 'stored' => $stored);
}

/**
 * The ids of $rows in walk order - what menu_order 1..N stands for.
 *
 * @param list<WP_Post> $rows
 * @return list<int>
 */
function wpmcp_menu_order($rows) {
    $order = array();

    foreach (wpmcp_menu_walk($rows) as $item) { $order[] = $item['id']; }

    return $order;
}

/**
 * The item ids inside $id, at any depth, as a set.
 *
 * FED THE STORED SHAPE, not the shown one - see wpmcp_menu_shape(). The `$found` set is also
 * the cycle guard: a menu whose data already contains a loop terminates here rather than
 * recursing, which is the state this function exists to refuse a caller ADDING to.
 */
function wpmcp_menu_descendants($children, $id) {
    $found = array();
    $stack = isset($children[$id]) ? $children[$id] : array();

    while ($stack) {
        $child = array_pop($stack);
        if (isset($found[$child])) { continue; }
        $found[$child] = true;

        if (!empty($children[$child])) {
            foreach ($children[$child] as $grandchild) { $stack[] = $grandchild; }
        }
    }

    return $found;
}

/**
 * Number a menu's items 1..N in $order, writing only rows whose menu_order differs.
 *
 * WHY IT EXISTS: wp_update_nav_menu_item() stores the position it is given and shifts
 * nothing (nav-menu.php:458-474). MEASURED on both sites: position 2 on a menu of
 * A(0) B(2) C(3) stores a second 2, and a new menu's first item gets 0. wp-admin renumbers
 * the whole list in JavaScript before it saves; without this, two items claim one place and
 * the order between them is whatever the database returns.
 *
 * wp_update_post() with the id and the number: it reads the rest of the row back and
 * slashes that itself (post.php:5345), so there is no caller string here to slash.
 *
 * @return true|WP_Error
 */
function wpmcp_menu_renumber($rows, $order) {
    $current = array();
    foreach ($rows as $row) { $current[(int) $row->ID] = (int) $row->menu_order; }

    $n = 0;
    foreach ($order as $id) {
        $n++;
        if (!isset($current[$id]) || $current[$id] === $n) { continue; }

        $updated = wp_update_post(array('ID' => (int) $id, 'menu_order' => $n), true);
        if (is_wp_error($updated)) { return $updated; }
    }

    return true;
}

/**
 * Put item $id among $parent's children at $position (from 1; null or past the end means
 * last), then renumber the whole menu. The item's own parent meta must already say $parent.
 *
 * @return true|WP_Error
 */
function wpmcp_menu_place($menu, $id, $parent, $position) {
    $rows = wpmcp_menu_rows($menu);
    $id   = (int) $id;

    // THE ROW LIST IS WHAT IS REORDERED, and then core's walker decides the rest (1.1.1).
    // `Walker::walk()` buckets each element into its parent's child list IN THE ORDER THE
    // ELEMENTS ARRIVE, so "put this item third among its new siblings" is expressible as one
    // splice in a flat array - and the depth-first order, the orphan placement and the
    // nesting all come from core rather than from a traversal of ours.
    $moved = null;
    $rest  = array();

    foreach ($rows as $row) {
        if ((int) $row->ID === $id) { $moved = $row; continue; }
        $rest[] = $row;
    }

    if ($moved === null) { return new WP_Error('wpmcp_not_found', 'No menu item with that ID.'); }

    // Where this item's new siblings sit in the row list. The item's own parent meta has
    // already been written by the caller, so the walker will agree with this reading.
    $siblings = array();

    foreach ($rest as $index => $row) {
        if (wpmcp_menu_stored_parent($row->ID) === (int) $parent) { $siblings[] = $index; }
    }

    $slot = ($position === null)
        ? count($siblings)
        : max(0, min(count($siblings), (int) $position - 1));

    // Past the last sibling means "last among them", and appending to the row list says that
    // whatever else is in between: only the order WITHIN one parent's child list matters.
    array_splice($rest, $slot < count($siblings) ? $siblings[$slot] : count($rest), 0, array($moved));

    return wpmcp_menu_renumber($rows, wpmcp_menu_order($rest));
}

/**
 * May the caller see what this item links to?
 *
 * wp_setup_nav_menu_item() resolves a linked item's label and link with NO capability check:
 * measured, it hands a draft page's title and its ?page_id= link to anybody who asks. So:
 *
 *   post_type          the linked post exists, is not trashed, its type is viewable, and the
 *                      caller holds read_post on it - which a draft or private page of
 *                      somebody else's fails for an Author
 *   taxonomy           the term exists and its taxonomy is viewable
 *   post_type_archive  the post type is viewable
 *   custom             always - its url is the item's own
 */
function wpmcp_menu_item_visible($setup) {
    switch ((string) $setup->type) {
        case 'post_type':
            $post = get_post((int) $setup->object_id);

            return $post && $post->post_status !== 'trash'
                && is_post_type_viewable($post->post_type)
                && current_user_can('read_post', $post->ID);

        case 'taxonomy':
            $term = get_term((int) $setup->object_id, (string) $setup->object);

            return ($term instanceof WP_Term) && is_taxonomy_viewable($term->taxonomy);

        case 'post_type_archive':
            return post_type_exists((string) $setup->object) && is_post_type_viewable((string) $setup->object);

        case 'custom':
            return true;
    }

    return false;
}

/**
 * The label a menu item shows, as the database holds it.
 *
 * An item with no label of its own shows the linked object's title, and core's
 * wp_setup_nav_menu_item() runs a POST's title through `the_title` on the way
 * (nav-menu.php:897) - so a page titled with a straight quote reached the caller texturized
 * and no longer matched the page. A term's name is already taken raw (`:939`, get_term_field
 * with the 'raw' context) and a post-type archive's label is a registered string rather than
 * stored text, so both keep core's value. A linked post with an empty title gives an empty
 * label here, where core shows "#123 (no title)".
 */
function wpmcp_menu_linked_title($setup) {
    if ((string) $setup->type === 'post_type') {
        $linked = get_post((int) $setup->object_id);

        return $linked ? wpmcp_raw_title($linked) : (string) $setup->title;
    }

    // A TERM'S NAME, DECODED (sprint 14d round 2). Core takes it with get_term_field(...,
    // 'raw'), which is the stored `A &amp; B`, while every term tool now returns `A & B` and
    // the description promised labels "as typed". update-menu-item compares a sent title
    // with THIS value and keeps the item label-less on a match, because core's own check
    // (nav-menu.php:513-516) compares with the raw name and would otherwise store an own label.
    if ((string) $setup->type === 'taxonomy') {
        return wpmcp_decode_specialchars((string) $setup->title);
    }

    return (string) $setup->title;
}

/** One item as get-menu shows it, children not included. */
function wpmcp_menu_item_out($row, $parent, $position) {
    $setup   = wp_setup_nav_menu_item(clone $row);
    $visible = wpmcp_menu_item_visible($setup);
    $classes = array();

    foreach ((array) $setup->classes as $class) {
        if ((string) $class !== '') { $classes[] = (string) $class; }
    }

    return array(
        'id'         => (int) $row->ID,
        // The item's own label is its post_title column; only the fallback needs core. The
        // OWN label comes back decoded (sprint 14d): labels wp-admin saved can carry
        // `&#038;` for `&` - jaygroup has eight - and wp-admin's own field, an HTML
        // attribute, shows that as `&`. update-menu-item keeps the
        // stored bytes when it is sent this decoded text back. The fallback is a post
        // title, the raw column, as get-post returns it.
        'title'      => $visible
            ? ((string) $row->post_title !== '' ? wpmcp_decode_specialchars($row->post_title) : wpmcp_menu_linked_title($setup))
            : null,
        'type'       => (string) $setup->type,
        'object'     => (string) $setup->object,
        'object_id'  => ($visible && in_array($setup->type, array('post_type', 'taxonomy'), true)) ? (int) $setup->object_id : null,
        'url'        => $visible ? (string) $setup->url : null,
        'target'     => (string) $setup->target,
        'classes'    => $classes,
        'parent'     => (int) $parent,
        'position'   => (int) $position,
        'menu_order' => (int) $row->menu_order,
        'status'     => (string) $row->post_status,
        'withheld'   => !$visible,
    );
}

/**
 * A menu's items as a tree, each level in order.
 *
 * NESTING IS RE-ASSEMBLED FROM THE WALKER'S OWN OUTPUT, not walked again: every item appears
 * in wpmcp_menu_walk() exactly once, under the parent core shows it under, so the shown
 * parent map IS the tree and no cycle guard is needed here - an unreachable item is already
 * a child of 0 by the time this runs. That is what removed the `$seen` bookkeeping and the
 * second traversal for unreached rows.
 */
function wpmcp_menu_tree($rows) {
    $shape = wpmcp_menu_shape($rows);
    $byId  = array();
    foreach ($rows as $row) { $byId[(int) $row->ID] = $row; }

    $build = function ($parent) use (&$build, $shape, $byId) {
        $out      = array();
        $position = 0;

        foreach (isset($shape['children'][$parent]) ? $shape['children'][$parent] : array() as $id) {
            $item             = wpmcp_menu_item_out($byId[$id], $parent, ++$position);
            $item['children'] = $build($id);
            $out[]            = $item;
        }

        return $out;
    };

    return $build(0);
}

/** id, name, slug, count and the theme locations assigned to this menu. */
function wpmcp_menu_summary($menu, $count, $registered, $assigned) {
    $locations = array();

    foreach ($registered as $location => $description) {
        if (!empty($assigned[$location]) && (int) $assigned[$location] === (int) $menu->term_id) {
            $locations[] = array('location' => (string) $location, 'description' => (string) $description);
        }
    }

    return array(
        'id'        => (int) $menu->term_id,
        // A menu is a term, and its name is stored escaped like any term's.
        'name'      => wpmcp_decode_specialchars($menu->name),
        'slug'      => (string) $menu->slug,
        'count'     => (int) $count,
        'locations' => $locations,
    );
}

/** One item, re-read after a write, as get-menu shows it - plus menu_id. */
function wpmcp_menu_item_result($id, $menu) {
    $rows  = wpmcp_menu_rows($menu);
    $shape = wpmcp_menu_shape($rows);

    foreach ($rows as $row) {
        if ((int) $row->ID !== (int) $id) { continue; }

        $parent   = $shape['parents'][(int) $id];
        $position = array_search((int) $id, $shape['children'][$parent], true);

        return wpmcp_menu_item_out($row, $parent, $position === false ? 0 : $position + 1)
            + array('menu_id' => (int) $menu->term_id);
    }

    return new WP_Error('wpmcp_not_found', 'No menu item with that ID.');
}

/**
 * A custom item's url, or a WP_Error: http, https, mailto: and tel:, or a path on this site.
 * //host is allowed, and it is what it looks like - an ordinary link to another host.
 *
 * esc_url_raw() with that protocol list returns '' for javascript:, data:, sms: and any other
 * scheme however it is cased or padded (measured), and turns a bare host into http://. The ''
 * is REFUSED here, because core would not refuse it: its sanitize_url() on the way in stores
 * the '' without a word (nav-menu.php:598), leaving an item that links nowhere.
 *
 * A BACKSLASH IS REFUSED FIRST. Browsers read /\host as //host, another host; esc_url() strips
 * the backslash, so what would be stored is a different link from the one sent - measured on
 * both sites, /\host becomes the path /host and \\host becomes http://host. No url needs a
 * literal backslash; an encoded one (%5C) passes untouched.
 */
function wpmcp_menu_url($url) {
    $url = trim((string) $url);

    if (strpos($url, '\\') !== false) {
        return new WP_Error(
            'wpmcp_bad_url',
            'The url contains a backslash, which browsers read as a slash: /\\host opens another'
            . ' host. Use / instead, or %5C for a literal backslash.'
        );
    }

    $clean = esc_url_raw($url, array('http', 'https', 'mailto', 'tel'));

    if ($clean === '') {
        return new WP_Error(
            'wpmcp_bad_url',
            'The url must be an http, https, mailto: or tel: address, or a path on this site such'
            . ' as /about or #top. Other schemes, javascript: among them, are refused.'
        );
    }

    return $clean;
}

/** classes as core stores them: one space-separated string. */
function wpmcp_menu_classes($value) {
    $list = is_array($value) ? $value : preg_split('/\s+/', (string) $value);

    return implode(' ', array_map('strval', array_filter((array) $list, 'is_scalar')));
}

function wpmcp_menu_bad_parent($parentId, $menu) {
    return new WP_Error(
        'wpmcp_bad_parent',
        'parent_id ' . (int) $parentId . ' is not an item of menu ' . (int) $menu->term_id
        . '. Use 0 for the top level, or the id of an item in the same menu (get-menu lists them).'
    );
}

function wpmcp_menu_tools() {
    $itemFields = 'id, title (the label as typed - &amp;, &#038;, &lt; and &gt; decoded; with'
        . ' no label of its own, the linked page\'s stored title or the term\'s decoded name),'
        . ' type (post_type, taxonomy, post_type_archive or'
        . ' custom), object (such as page, category or custom), object_id, url, target, classes,'
        . ' parent (0 at the top level), position (among its siblings, from 1), menu_order (its'
        . ' place in the whole menu) and status';

    return array(

    'list-menus' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List the site\'s classic navigation menus. Returns block_theme - true'
            . ' when the active theme is a block theme, whose Navigation block is edited in the'
            . ' Site Editor, so changing a classic menu here may not change what visitors see -'
            . ' then locations (each menu location the theme registers: location, description,'
            . ' and menu_id of the menu assigned to it, or null) and menus (id, name, slug,'
            . ' count of items, and the locations it is assigned to). Read one with get-menu.'
            . ' Needs permission to edit posts or theme options: Editors can read menus,'
            . ' Subscribers cannot.',
        'inputSchema' => array('type' => 'object', 'properties' => array()),
        'run' => function ($a) {
            if (!wpmcp_menu_can_read()) { return wpmcp_cannot('read menus'); }

            $registered = get_registered_nav_menus();
            $assigned   = get_nav_menu_locations();
            $locations  = array();

            foreach ($registered as $location => $description) {
                $menu = !empty($assigned[$location]) ? wpmcp_menu_get($assigned[$location]) : null;
                $locations[] = array(
                    'location'    => (string) $location,
                    'description' => (string) $description,
                    'menu_id'     => $menu ? (int) $menu->term_id : null,
                );
            }

            // Draft items are counted only for a caller who may list them - see wpmcp_menu_rows().
            $drafts = current_user_can('edit_theme_options');
            $menus  = array();
            foreach (wp_get_nav_menus() as $menu) {
                $menus[] = wpmcp_menu_summary($menu, count(wpmcp_menu_rows($menu, $drafts)), $registered, $assigned);
            }

            return array(
                // No function_exists guard since 1.1.1: wp_is_block_theme() is @since 5.9 and
                // the declared floor is 6.9, so the guard could only ever answer the same way.
                'block_theme' => wp_is_block_theme(),
                'locations'   => $locations,
                'menus'       => $menus,
            );
        },
    ),

    'get-menu' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Read one classic menu as a tree of items. Args: id (integer, required),'
            . ' from list-menus. Returns id, name, slug, count, locations and items, top level'
            . ' first, each with ' . $itemFields . ', withheld and children. An item that links to'
            . ' content you may not read, such as another user\'s draft or private page, is still'
            . ' listed, with title, url and object_id null and withheld true. Draft items, which'
            . ' visitors do not see, are listed only to callers who can edit theme options. Needs permission'
            . ' to edit posts or theme options; an id that is not a menu answers like a missing'
            . ' one.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer', 'description' => 'Menu ID, from list-menus.'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            if (!wpmcp_menu_can_read()) { return wpmcp_cannot('read menus'); }

            $menu = wpmcp_menu_get(isset($a['id']) ? $a['id'] : 0);
            if (!$menu) { return new WP_Error('wpmcp_not_found', 'No menu with that ID.'); }

            // Draft items only for a caller who can edit theme options, as core's REST endpoint.
            $rows = wpmcp_menu_rows($menu, current_user_can('edit_theme_options'));

            // EVERY LINKED POST AND TERM IN TWO QUERIES, before the tree reads them one at a
            // time (scout 49, candidate 5; taken in 1.1.1 because the 6.9 floor removed the
            // `function_exists` guard that was its only cost). `update_menu_item_cache()` is
            // `@since 6.1` and primes exactly what wpmcp_menu_item_visible() and
            // wpmcp_menu_linked_title() then ask for per item - get_post() and get_term() -
            // so a 66-item menu stops issuing 66 pairs of queries. It changes no value: it is
            // the cache, not the read.
            update_menu_item_cache($rows);

            return wpmcp_menu_summary($menu, count($rows), get_registered_nav_menus(), get_nav_menu_locations())
                + array('items' => wpmcp_menu_tree($rows));
        },
    ),

    'add-menu-item' => array(
        'write' => true,
        // destructiveHint FALSE: a new item comes into being and none is replaced. The
        // renumbering rewrites other items' menu_order, but only to keep the order they
        // already had contiguous around the new one. idempotentHint FALSE: a second call
        // adds a second item.
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ),
        'description' => 'Add an item to a classic navigation menu. Args: menu_id and type'
            . ' (required). type is "custom" for a plain link, a post type such as "page" or'
            . ' "post", or a taxonomy such as "category". object_id: the post or term a linked'
            . ' item points at; it must exist and be readable by you. url: custom items only -'
            . ' http, https, mailto: or tel:, or a path on this site (//host links to another host);'
            . ' javascript:, other schemes and backslashes are refused. title:'
            . ' required for a custom item; omit it on a linked item to show the linked title.'
            . ' parent_id: an item of the same menu (default 0, the top level). position: among'
            . ' those siblings, from 1 (default last). target: "" or "_blank". classes: a list'
            . ' of CSS classes. The other items are renumbered so the order stays contiguous.'
            . ' There is no draft step: the change is live.'
            . ' Returns the item as get-menu shows it, plus menu_id. Needs permission to edit'
            . ' theme options (Administrators, not Editors).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'menu_id'   => array('type' => 'integer', 'description' => 'Menu ID, from list-menus.'),
            'type'      => array('type' => 'string', 'description' => '"custom", a post type (page, post, ...) or a taxonomy (category, ...).'),
            'object_id' => array('type' => 'integer', 'description' => 'The post or term a linked item points at.'),
            'url'       => array('type' => 'string', 'description' => 'Custom items only: http, https, mailto: or tel:, or a path on this site.'),
            'title'     => array('type' => 'string', 'description' => 'The label. Required for a custom item.'),
            'parent_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'An item of the same menu, or 0 for the top level. Default 0.'),
            'position'  => array('type' => 'integer', 'minimum' => 1, 'description' => 'Place among its siblings, from 1. Default last.'),
            'target'    => array('type' => 'string', 'enum' => array('', '_blank'), 'description' => '"_blank" to open in a new tab.'),
            'classes'   => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'CSS classes for the item.'),
        ), 'required' => array('menu_id', 'type')),
        'run' => function ($a) {
            if (!wpmcp_menu_can_write()) { return wpmcp_cannot('edit menus'); }

            $menu = wpmcp_menu_get(isset($a['menu_id']) ? $a['menu_id'] : 0);
            if (!$menu) { return new WP_Error('wpmcp_not_found', 'No menu with that ID.'); }

            $type = isset($a['type']) ? sanitize_key((string) $a['type']) : '';
            $data = array('menu-item-status' => 'publish');

            if ($type !== 'custom' && isset($a['url'])) {
                return new WP_Error('wpmcp_bad_argument', 'url is only for a custom item; a linked item takes its link from what it links to.');
            }

            if ($type === 'custom') {
                $url = wpmcp_menu_url(isset($a['url']) ? $a['url'] : '');
                if (is_wp_error($url)) { return $url; }

                $title = isset($a['title']) ? (string) $a['title'] : '';
                if (trim($title) === '') {
                    return new WP_Error('wpmcp_title_required', 'A custom item needs a title.');
                }

                $data += array('menu-item-type' => 'custom', 'menu-item-url' => $url, 'menu-item-title' => $title);
            } elseif ($type !== '' && post_type_exists($type) && wpmcp_post_type_ok($type)) {
                // The same not_found for a missing post, a post of another type, a trashed
                // one and one the caller may not read.
                $post = !empty($a['object_id']) ? get_post((int) $a['object_id']) : null;
                if (!$post || $post->post_type !== $type || $post->post_status === 'trash'
                    || !current_user_can('read_post', $post->ID)) {
                    return new WP_Error('wpmcp_not_found', 'No post with that ID.');
                }

                $data += array(
                    'menu-item-type'      => 'post_type',
                    'menu-item-object'    => $type,
                    'menu-item-object-id' => (int) $post->ID,
                    // The linked post's stored title means "follow the post", as core decides
                    // for itself; spelled out so add and update agree (sprint 14d round 3).
                    'menu-item-title'     => (isset($a['title']) && (string) $a['title'] !== wpmcp_raw_title($post)) ? (string) $a['title'] : '',
                );
            } elseif ($type !== '' && taxonomy_exists($type) && is_taxonomy_viewable($type)) {
                $term = !empty($a['object_id']) ? get_term((int) $a['object_id'], $type) : null;
                if (!($term instanceof WP_Term)) {
                    return new WP_Error('wpmcp_not_found', 'No term with that ID.');
                }

                $data += array(
                    'menu-item-type'      => 'taxonomy',
                    'menu-item-object'    => $type,
                    'menu-item-object-id' => (int) $term->term_id,
                    // The term's name as the term tools and get-menu give it, decoded, means
                    // "follow the term" - the same comparison update-menu-item makes (round 3).
                    'menu-item-title'     => (isset($a['title']) && (string) $a['title'] !== wpmcp_decode_specialchars($term->name)) ? (string) $a['title'] : '',
                );
            } else {
                return new WP_Error(
                    'wpmcp_bad_type',
                    'type must be "custom", a viewable post type such as "page" or "post", or a'
                    . ' viewable taxonomy such as "category".'
                );
            }

            // THE PARENT MUST BE AN ITEM OF THIS MENU. Core would store any id it is given,
            // an item of another menu included (measured), and the item would then show at
            // the top level of one menu while claiming a parent in another.
            $shape  = wpmcp_menu_shape(wpmcp_menu_rows($menu));
            $parent = isset($a['parent_id']) ? (int) $a['parent_id'] : 0;
            if ($parent !== 0 && !isset($shape['parents'][$parent])) {
                return wpmcp_menu_bad_parent($parent, $menu);
            }

            $data['menu-item-parent-id'] = $parent;
            if (isset($a['target']))  { $data['menu-item-target'] = ((string) $a['target'] === '_blank') ? '_blank' : ''; }
            if (isset($a['classes'])) { $data['menu-item-classes'] = wpmcp_menu_classes($a['classes']); }

            // SLASHED, as core's REST controller does (menu-items controller :139): the title
            // reaches wp_insert_post(), which unslashes, and core compares wp_unslash() of it
            // with the linked title (nav-menu.php:514).
            $id = wp_update_nav_menu_item($menu->term_id, 0, wp_slash($data));
            if (is_wp_error($id)) { return $id; }

            $placed = wpmcp_menu_place($menu, (int) $id, $parent, isset($a['position']) ? (int) $a['position'] : null);
            if (is_wp_error($placed)) { return $placed; }

            return wpmcp_menu_item_result((int) $id, $menu);
        },
    ),

    'update-menu-item' => array(
        'write' => true,
        // destructiveHint TRUE: the label, link, target and classes sent REPLACE what the
        // item had - update-post's judgement for update-post's reason. idempotentHint TRUE:
        // the same fields and the same place twice leave the same menu.
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Change one item of a classic menu. Args: id (required), then any of'
            . ' title, url (custom items only: http, https, mailto:, tel: or a path), target ("" or'
            . ' "_blank"), classes (a list), parent_id (0 for the top level, or an item of the'
            . ' same menu that is not the item itself or inside it) and position (among its'
            . ' siblings, from 1). An empty title on a linked item shows the linked title again.'
            . ' Moving to a new parent without a position puts the item last there. Fields not'
            . ' sent stay as they are, a title or url sent back exactly as get-menu gave it keeps'
            . ' the stored bytes, and the menu is renumbered so its order stays contiguous.'
            . ' There is no draft step: the change is live.'
            . ' Returns the item as get-menu shows it, plus menu_id. Needs permission to edit'
            . ' theme options; an id that is not a menu item answers like a missing one.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id'        => array('type' => 'integer', 'description' => 'Menu item ID, from get-menu.'),
            'title'     => array('type' => 'string', 'description' => 'The label.'),
            'url'       => array('type' => 'string', 'description' => 'Custom items only: http, https, mailto: or tel:, or a path on this site.'),
            'target'    => array('type' => 'string', 'enum' => array('', '_blank'), 'description' => '"_blank" to open in a new tab, "" not to.'),
            'classes'   => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'CSS classes; replaces the list.'),
            'parent_id' => array('type' => 'integer', 'minimum' => 0, 'description' => 'An item of the same menu, or 0 for the top level.'),
            'position'  => array('type' => 'integer', 'minimum' => 1, 'description' => 'Place among its siblings, from 1.'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            if (!wpmcp_menu_can_write()) { return wpmcp_cannot('edit menus'); }

            $found = wpmcp_menu_item_get(isset($a['id']) ? $a['id'] : 0);
            if (!$found) { return new WP_Error('wpmcp_not_found', 'No menu item with that ID.'); }

            $item  = $found['item'];
            $menu  = $found['menu'];
            $id    = (int) $item->ID;
            $shape = wpmcp_menu_shape(wpmcp_menu_rows($menu));
            $type  = (string) get_post_meta($id, '_menu_item_type', true);

            // EVERY FIELD CORE WILL WRITE, read back from the row first. wp_update_nav_menu_item()
            // fills anything missing with its DEFAULTS - an empty label, url and classes, type
            // `custom` (nav-menu.php:437-454) - so an update that sent only what changed would
            // wipe the rest. The REST controller reads the item back the same way (menu-items
            // controller :343-368). RAW, not through wp_setup_nav_menu_item(), which would hand
            // back a linked page's title as the label and a trimmed, filtered description.
            //
            // A FIELD NOT SENT IS CARRIED UNCHANGED - THE PARENT TOO, even a stored parent that
            // names no item of this menu. The shape counts such an item as top level, and that
            // decides where it is NUMBERED; storing that 0 would move it on screen, because a
            // theme's walker shows an item whose parent is gone after every top-level tree
            // (class-wp-walker.php:258-264) and an item with parent 0 at its own place (:224-225).
            // Found by review. What core itself does to each carried value is tabled in the
            // round-2 commit; the one it changes is a stored parent equal to the item itself,
            // which it stores as 0 (nav-menu.php:584-586).
            $data = array(
                'menu-item-object-id'   => (int) get_post_meta($id, '_menu_item_object_id', true),
                'menu-item-object'      => (string) get_post_meta($id, '_menu_item_object', true),
                'menu-item-parent-id'   => (int) get_post_meta($id, '_menu_item_menu_item_parent', true),
                'menu-item-position'    => max(1, (int) $item->menu_order),
                'menu-item-type'        => $type,
                'menu-item-title'       => $item->post_title,
                'menu-item-url'         => (string) get_post_meta($id, '_menu_item_url', true),
                'menu-item-description' => $item->post_content,
                'menu-item-attr-title'  => $item->post_excerpt,
                'menu-item-target'      => (string) get_post_meta($id, '_menu_item_target', true),
                'menu-item-classes'     => wpmcp_menu_classes((array) get_post_meta($id, '_menu_item_classes', true)),
                'menu-item-xfn'         => (string) get_post_meta($id, '_menu_item_xfn', true),
                'menu-item-status'      => $item->post_status,
            );

            // THE ROUND-TRIP RULE (sprint 14d): a title or url sent back exactly as get-menu
            // returned it is not a change, and the STORED bytes stay. get-menu decodes an own
            // label (`&#038;` -> `&`), so without this, writing a read label back would
            // replace wp-admin's `FDA &#038; GMP` with `FDA & GMP` - the same text on screen,
            // different bytes in the row. And a stored url that this tool would now refuse
            // (a backslash wp-admin let in) must not make the whole update fail when the
            // caller only sent it back.
            if (array_key_exists('title', $a)) {
                $title  = (string) $a['title'];
                $stored = (string) $item->post_title;
                $asRead = $stored !== '' ? wpmcp_decode_specialchars($stored) : null;

                if ($asRead !== null && ($title === $asRead || $title === $stored)) {
                    $title = $stored;
                } elseif ($stored === '' && $title === wpmcp_menu_linked_title(wp_setup_nav_menu_item(clone $item))) {
                    // The fallback label sent back as get-menu gave it: the item stays
                    // label-less, whatever form core's own comparison expects.
                    $title = '';
                } elseif ($type === 'custom' && trim($title) === '') {
                    return new WP_Error('wpmcp_title_required', 'A custom item needs a title.');
                }
                $data['menu-item-title'] = $title;
            }

            if (array_key_exists('url', $a)) {
                if ($type !== 'custom') {
                    return new WP_Error('wpmcp_bad_argument', 'url is only for a custom item; a linked item takes its link from what it links to.');
                }
                if ((string) $a['url'] !== $data['menu-item-url']) {
                    $url = wpmcp_menu_url($a['url']);
                    if (is_wp_error($url)) { return $url; }
                    $data['menu-item-url'] = $url;
                }
            }

            if (array_key_exists('target', $a))  { $data['menu-item-target'] = ((string) $a['target'] === '_blank') ? '_blank' : ''; }
            if (array_key_exists('classes', $a)) { $data['menu-item-classes'] = wpmcp_menu_classes($a['classes']); }

            $parent = $shape['parents'][$id];
            $moved  = false;

            if (array_key_exists('parent_id', $a)) {
                $newParent = (int) $a['parent_id'];

                if ($newParent !== 0 && !isset($shape['parents'][$newParent])) {
                    return wpmcp_menu_bad_parent($newParent, $menu);
                }

                // NO CYCLES. Core catches only the item as its own parent, and silently: it
                // stores 0 (nav-menu.php:583-586). A descendant it stores as given, and the
                // two items then hang from each other with no way back to the top.
                if ($newParent === $id || isset(wpmcp_menu_descendants($shape['stored'], $id)[$newParent])) {
                    return new WP_Error(
                        'wpmcp_bad_parent',
                        'parent_id cannot be the item itself or an item inside it: item ' . $id
                        . ' contains ' . ($newParent === $id ? 'no item ' . $id . ' to hang from' : 'item ' . $newParent) . '.'
                    );
                }

                $moved  = ($newParent !== $parent);
                $parent = $newParent;
                $data['menu-item-parent-id'] = $parent;
            }

            if (array_key_exists('position', $a)) {
                $position = (int) $a['position'];
            } elseif ($moved) {
                $position = null;
            } else {
                $index    = array_search($id, $shape['children'][$parent], true);
                $position = ($index === false) ? null : $index + 1;
            }

            // SLASHED, as core's REST controller does (menu-items controller :232).
            $updated = wp_update_nav_menu_item($menu->term_id, $id, wp_slash($data));
            if (is_wp_error($updated)) { return $updated; }

            $placed = wpmcp_menu_place($menu, $id, $parent, $position);
            if (is_wp_error($placed)) { return $placed; }

            return wpmcp_menu_item_result($id, $menu);
        },
    ),

    'remove-menu-item' => array(
        'write' => true,
        // destructiveHint TRUE: the item is deleted outright - menu items have no trash.
        // idempotentHint TRUE: a second call finds nothing to remove and the menu is as
        // the first call left it.
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Remove one item from a classic menu. Args: id (required). The item is'
            . ' deleted permanently - menu items have no trash. Its children move up one level to'
            . ' its parent, in its place, as in wp-admin; every other item keeps its order and'
            . ' the menu is renumbered. Returns id, removed, menu_id, parent and reparented (the'
            . ' ids that moved up). Needs permission to edit theme options; an id that is not a'
            . ' menu item answers like a missing one.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer', 'description' => 'Menu item ID, from get-menu.'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            if (!wpmcp_menu_can_write()) { return wpmcp_cannot('edit menus'); }

            $found = wpmcp_menu_item_get(isset($a['id']) ? $a['id'] : 0);
            if (!$found) { return new WP_Error('wpmcp_not_found', 'No menu item with that ID.'); }

            $id     = (int) $found['item']->ID;
            $menu   = $found['menu'];
            $shape  = wpmcp_menu_shape(wpmcp_menu_rows($menu));
            $parent = $shape['parents'][$id];
            // THE ROWS THAT ACTUALLY POINT AT THIS ITEM, which is the stored shape and not
            // the shown one: an orphan's children hang from 0 in the rendering, and it is
            // their stored parent that is about to name a row that no longer exists.
            $kids   = isset($shape['stored'][$id]) ? $shape['stored'][$id] : array();

            // wp_delete_post() with force, as wp-admin and the REST controller delete a menu
            // item (nav-menus.php:283, menu-items controller :306). It does not touch the
            // children: measured, they keep pointing at the id that is now gone.
            if (!wp_delete_post($id, true)) {
                return new WP_Error('wpmcp_delete_failed', 'The menu item could not be removed.');
            }

            // THE CHILDREN MOVE UP ONE LEVEL, what wp-admin's removeMenuItem does
            // (nav-menu.js:1844). An integer as a string, the form core stores it in
            // (nav-menu.php:589); no caller string, nothing to slash.
            foreach ($kids as $kid) {
                update_post_meta($kid, '_menu_item_menu_item_parent', (string) $parent);
            }

            // ...AND TAKE THE REMOVED ITEM'S PLACE among its siblings, which now costs nothing
            // to arrange: the rows are re-read AFTER the delete and the re-parenting, and the
            // children kept the menu_order numbers that sat just after the item that is gone,
            // so core's walker buckets them into the sibling list exactly where it was. The
            // old code spliced a children map by hand to reach the same answer.
            $remaining = wpmcp_menu_rows($menu);
            $done      = wpmcp_menu_renumber($remaining, wpmcp_menu_order($remaining));
            if (is_wp_error($done)) { return $done; }

            return array(
                'id'         => $id,
                'removed'    => true,
                'menu_id'    => (int) $menu->term_id,
                'parent'     => $parent,
                'reparented' => array_map('intval', $kids),
            );
        },
    ),

    );
}

wpmcp_register_module('menus', 'wpmcp_menu_tools');
