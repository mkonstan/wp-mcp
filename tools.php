<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - tool catalog.
 *
 * wpmcp_core_tools():     site-info / list-posts / get-post.
 * wpmcp_content_tools():  create-post / update-post / delete-post.
 * wpmcp_taxonomy_tools(): list-terms / create-term / delete-term.
 * wpmcp_media_tools():    list-media / get-media / upload-media / delete-media.
 * wpmcp_comment_tools():  list-comments / moderate-comment / reply-comment.
 * wpmcp_code_tools():     the four jailed code-edit tools (active theme only).
 * Each tool = array('write'=>bool, 'description'=>str, 'inputSchema'=>array, 'run'=>callable).
 * Merged into the registry by endpoint.php's wpmcp_tools().
 *
 * EVERY WP_Error CONSTRUCTED IN THIS FILE CARRIES A `wpmcp_` CODE, and it is load-bearing.
 * endpoint.php's wpmcp_tool_error_response() uses that prefix as the allow-list that
 * decides what a client is told: a `wpmcp_` code is a sentence an author wrote for the
 * caller and reaches the wire as `isError: true` with its message, while any other code -
 * a wpdb error, wp_insert_post's, wp_handle_upload's, anything from core - is treated
 * exactly as a thrown throwable: generic -32603 plus a trace id, detail to the private log.
 * Drop the prefix from a new error here and its message stops reaching the client.
 */
if (!defined('ABSPATH')) { exit; }

/* ============================================================
 * Code-editing config + jail helpers
 * ========================================================== */
function wpmcp_code_enabled() {
    return (bool) get_option('wpmcp_code_enabled', false);
}

/**
 * May the current user touch theme files at all? Null when yes, a WP_Error when no.
 *
 * Every one of the four code tools begins with this, READ INCLUDED: code-read and
 * code-list hand back theme PHP and the shape of the theme directory, which is source
 * code, not content. wp-admin's theme editor is gated on exactly this trio, and
 * before this the tools were gated on nothing but admin scope plus the
 * wpmcp_code_enabled option - so an admin-scope token minted "Runs as:
 * some-subscriber" on a site with code editing on could read, rewrite and delete
 * files in the active theme.
 *
 * DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS are honoured the way core honours them:
 * they turn the capability off for everyone, administrators included, and an operator
 * who sets them means it. (Core does this in map_meta_cap, so current_user_can alone
 * would already answer false - the explicit check is here so the refusal says which
 * of the three it was, and so it holds if that mapping ever moves.)
 */
function wpmcp_code_forbidden() {
    if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
        return new WP_Error('wpmcp_forbidden', 'File modification is disabled on this site (DISALLOW_FILE_MODS).');
    }
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        return new WP_Error('wpmcp_forbidden', 'Theme file editing is disabled on this site (DISALLOW_FILE_EDIT).');
    }
    if (!current_user_can('edit_themes')) {
        return wpmcp_cannot('edit theme files');
    }
    return null;
}

function wpmcp_code_denylist() {
    $d = get_option('wpmcp_code_denylist', null);
    if (!is_array($d)) {
        $d = array('functions.php', 'index.php', 'inc/', 'includes/', 'lib/');
    }
    return $d;
}

function wpmcp_code_root() {
    $root = realpath(get_stylesheet_directory());
    return $root ? $root : '';
}

function wpmcp_code_allowed_ext() {
    return array('php', 'css', 'js', 'html', 'json', 'txt', 'md', 'svg');
}

/** True if $abs is the root or sits inside it (both already realpath'd). */
function wpmcp_path_within($abs, $root) {
    $abs  = str_replace('\\', '/', (string) $abs);
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    return ($abs === $root) || (strpos($abs, $root . '/') === 0);
}

/**
 * Resolve a user path inside the theme jail.
 * Returns array('ok'=>true,'abs'=>..,'rel'=>..) or array('ok'=>false,'error'=>..).
 * $mustExist true = file must already exist; false = only parent must be in jail.
 */
function wpmcp_code_resolve($path, $mustExist) {
    $root = wpmcp_code_root();
    if ($root === '') { return array('ok' => false, 'error' => 'Active theme directory not found.'); }
    $path = (string) $path;
    if ($path === '') { return array('ok' => false, 'error' => 'Path required.'); }
    if (strpos($path, "\0") !== false || strpos($path, '..') !== false) {
        return array('ok' => false, 'error' => 'Illegal path.');
    }
    if ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:#', $path)) {
        return array('ok' => false, 'error' => 'Path must be relative to the theme root.');
    }
    $rel  = ltrim(str_replace('\\', '/', $path), '/');
    $cand = $root . '/' . $rel;

    if ($mustExist) {
        $abs = realpath($cand);
        if ($abs === false) { return array('ok' => false, 'error' => 'No such file.'); }
    } else {
        $parent = realpath(dirname($cand));
        if ($parent === false) { return array('ok' => false, 'error' => 'Parent directory does not exist.'); }
        if (!wpmcp_path_within($parent, $root)) {
            return array('ok' => false, 'error' => 'Path escapes the theme directory.');
        }
        $abs = $parent . '/' . basename($cand);
        // If the target already exists, never follow a symlink out of the jail:
        // realpath the real target and re-check it sits inside the theme root.
        if (is_link($abs)) {
            return array('ok' => false, 'error' => 'Refusing to write through a symlink.');
        }
        if (file_exists($abs)) {
            $real = realpath($abs);
            if ($real === false || !wpmcp_path_within($real, $root)) {
                return array('ok' => false, 'error' => 'Path escapes the theme directory.');
            }
            $abs = $real;
        }
    }
    if (!wpmcp_path_within($abs, $root)) {
        return array('ok' => false, 'error' => 'Path escapes the theme directory.');
    }
    return array('ok' => true, 'abs' => $abs, 'rel' => $rel);
}

/** True if a relative path is blocked by the denylist. */
function wpmcp_code_denied($rel) {
    $rel  = ltrim(str_replace('\\', '/', (string) $rel), '/');
    $base = strtolower(basename($rel));
    $low  = strtolower($rel);
    foreach (wpmcp_code_denylist() as $entry) {
        $e = strtolower(trim((string) $entry));
        if ($e === '') { continue; }
        $e = ltrim(str_replace('\\', '/', $e), '/');
        if (substr($e, -1) === '/') {                 // directory prefix
            $dir = rtrim($e, '/');
            if (strpos($low . '/', $dir . '/') === 0) { return true; }
        } elseif (strpos($e, '/') !== false) {        // explicit relative path
            if ($low === $e) { return true; }
        } else {                                       // bare filename anywhere
            if ($base === $e) { return true; }
        }
    }
    return false;
}

function wpmcp_code_ext_ok($rel) {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, wpmcp_code_allowed_ext(), true);
}

/**
 * A .bak path is safe to copy/rename onto only if it is not itself a symlink
 * (copy() follows the symlink target) and, if it exists, resolves inside the
 * theme root. Same jail guarantee as the main target.
 */
function wpmcp_bak_ok($bak) {
    if (is_link($bak)) { return false; }
    if (file_exists($bak)) {
        $real = realpath($bak);
        if ($real === false || !wpmcp_path_within($real, wpmcp_code_root())) { return false; }
    }
    return true;
}

/**
 * Syntax-check PHP source. Returns true, or a short description of where it broke.
 *
 * THE PARSER'S OWN MESSAGE IS NOT RETURNED, and that is the disclosure boundary rather
 * than taste: the ParseError's own message carries an absolute filesystem path,
 * and this string is put on the wire by code-write. The LINE is the part the caller can
 * act on - it is a line of source the caller just sent - and it leaks nothing. A unit
 * test greps this file for that call; trace.php is the only place it is allowed.
 */
function wpmcp_php_parse_ok($code) {
    if (!defined('TOKEN_PARSE')) { return true; } // can't check on this runtime
    try {
        // Intentional: TOKEN_PARSE makes the tokenizer THROW on invalid PHP; the catch below drives code-write's auto-revert. Do not "simplify" — the return value is unused on purpose.
        token_get_all($code, TOKEN_PARSE); // @phpstan-ignore-line
        return true;
    } catch (ParseError $e) {
        return 'syntax error on line ' . (int) $e->getLine();
    } catch (Throwable $e) {
        return 'the source could not be parsed';
    }
}

/**
 * Limit the post tools to real, viewable content types. Excludes internal types
 * (revisions, nav_menu_item, wp_template, wp_global_styles, etc.) and attachments
 * (those have their own media tools). Public CPTs are allowed automatically.
 */
function wpmcp_post_type_ok($type) {
    $type = sanitize_key((string) $type);
    if ($type === '' || $type === 'attachment') { return false; }
    if (!post_type_exists($type)) { return false; }
    return is_post_type_viewable($type);
}

/**
 * The post statuses of $post_type the current user is allowed to see listed.
 *
 * WHY THIS EXISTS RATHER THAN 'any'. WP_Query's `perm => 'readable'` is not the guard
 * it looks like: it scopes exactly one bucket - the `private` status - and only when
 * `post_status` is an explicit list. The 'any' keyword takes a different branch that
 * merely excludes `exclude_from_search` statuses, so `perm` never applies at all, and
 * `draft` sits in the bucket only `perm => 'editable'` scopes. Passing 'any' therefore
 * listed every author's private and draft posts - id, title, status, slug, link - to
 * any token. Deciding the statuses here, from capabilities, is what actually closes it.
 *
 * Narrower than 'any' by one deliberate margin: a site with CUSTOM post statuses will
 * not see them listed, because this returns only the five core ones.
 */
function wpmcp_listable_statuses($post_type) {
    $pto      = get_post_type_object($post_type);
    $statuses = array('publish');
    if (!$pto) { return $statuses; }

    if (current_user_can($pto->cap->read_private_posts)) {
        $statuses[] = 'private';
    }
    // Unpublished work is other people's drafts. Seeing it is an editorial
    // capability, and edit_others_posts is the one WordPress uses for that.
    if (current_user_can($pto->cap->edit_others_posts)) {
        $statuses[] = 'draft';
        $statuses[] = 'pending';
        $statuses[] = 'future';
    }
    return $statuses;
}

/**
 * The statuses of $post_type the current user may see on their OWN posts but not on
 * other people's - the complement of wpmcp_listable_statuses().
 *
 * An Author holds neither read_private_posts nor edit_others_posts, so the list above
 * gives them `publish` alone and their own drafts vanish from their own token's
 * listing. WP_Query cannot express "everybody's publish OR only my drafts" in one
 * query: the post_status buckets are OR'd, but `perm` scopes them by author
 * all-or-nothing, so `perm => 'editable'` would hide other people's PUBLISHED posts
 * too. Hence a second, author-scoped query, and hence this list.
 *
 * Empty for anyone who cannot author posts at all, and empty for an Editor or an
 * Administrator - they already see every status, so they run one query exactly as
 * before.
 */
function wpmcp_own_listable_statuses($post_type) {
    $pto = get_post_type_object($post_type);
    if (!$pto || !current_user_can($pto->cap->edit_posts)) { return array(); }

    return array_values(array_diff(
        array('private', 'draft', 'pending', 'future'),
        wpmcp_listable_statuses($post_type)
    ));
}

/**
 * Statuses that count as publishing, so they need publish_posts rather than merely
 * edit_posts. `private` is one of them - core's wp-admin/includes/post.php requires
 * publish_posts for it, because publishing privately is still publishing, and a
 * Contributor who could set it would be putting live content on the site.
 */
function wpmcp_publishing_statuses() {
    return array('publish', 'future', 'private');
}

/**
 * The refusal every write tool returns when the token's user lacks a capability.
 *
 * WHY EVERY WRITE TOOL NEEDS ONE. The low-level WordPress functions these tools call -
 * wp_insert_post, wp_update_post, wp_delete_post, wp_insert_term, wp_delete_term,
 * media_handle_sideload, wp_delete_attachment, wp_set_comment_status,
 * wp_insert_comment - perform NO capability checks. They are the storage layer;
 * wp-admin and the REST controllers do the checking before calling them. So before
 * this, an admin-scope token minted with "Runs as: some-subscriber" could publish,
 * rewrite and delete any post on the site, while the mint form told the operator that
 * the user's capabilities were the ceiling. They now are.
 *
 * Unlike the read tools, a write refusal is explicit rather than disguised as
 * not-found: the caller already named the thing it wants to change, so there is
 * nothing left to disclose, and an agent needs to know the difference between "gone"
 * and "not allowed" to stop retrying.
 */
function wpmcp_cannot($what) {
    return new WP_Error(
        'wpmcp_forbidden',
        "This token's user is not allowed to " . $what . '.'
    );
}

/**
 * Apply {taxonomy:[id|name,...]} to a post, creating missing terms by name.
 *
 * TWO CAPABILITIES, NOT ONE, and this helper is where the term gates were being
 * walked around: create-term requires edit_terms, but `create-post {terms: {category:
 * ["something new"]}}` reached wp_insert_term through here with no check at all, so an
 * Author could create categories. wp_set_object_terms checks nothing either, while
 * core's own wp_insert_post requires assign_terms for its tax_input.
 *
 *   assign_terms  to attach a term that already exists
 *   edit_terms    to bring a new one into being by name
 *
 * A caller with assign_terms but not edit_terms gets the existing terms attached and
 * the unknown names REFUSED - reported back, never silently created and never
 * silently dropped, because "I asked for three categories and got two" has to be
 * visible to whatever asked.
 *
 * @return array{assigned: array<string, list<int>>, refused: array<string, list<string>>, failed: array<string, string>}
 *   refused lists, per taxonomy, the names that would have needed edit_terms, and the
 *   marker '*' for a taxonomy the caller cannot assign in at all.
 */
function wpmcp_apply_terms($post_id, $terms) {
    $assigned = array();
    $refused  = array();
    $failed   = array();

    foreach ((array) $terms as $tax => $vals) {
        $tax = sanitize_key($tax);
        if (!taxonomy_exists($tax)) { continue; }

        $tax_obj = get_taxonomy($tax);
        if (!current_user_can($tax_obj->cap->assign_terms)) {
            // '*' rather than the names: the whole taxonomy is out of reach, which is
            // a different answer from "these particular names are new".
            $refused[$tax] = array('*');
            continue;
        }
        $may_create = current_user_can($tax_obj->cap->edit_terms);

        $ids = array();
        foreach ((array) $vals as $v) {
            if (is_numeric($v)) {
                // term_exists IN THIS TAXONOMY, because wp_set_object_terms does not
                // complain about an id that is not: core's loop does `if ( ! $term_info
                // ) { if ( is_int( $term ) ) continue; }`, so a numeric id belonging to
                // another taxonomy - or to nothing - is silently skipped and the caller
                // is told the assignment succeeded. Refuse it here instead, where it
                // can be named.
                if (term_exists((int) $v, $tax)) {
                    $ids[] = (int) $v;
                } else {
                    $refused[$tax][] = (string) $v;
                }
                continue;
            }
            $t = get_term_by('name', (string) $v, $tax);
            if ($t) {
                $ids[] = (int) $t->term_id;
                continue;
            }
            if (!$may_create) {
                $refused[$tax][] = (string) $v;
                continue;
            }
            $new = wp_insert_term((string) $v, $tax);
            if (!is_wp_error($new)) { $ids[] = (int) $new['term_id']; }
        }

        if ($ids) {
            // The return was ignored. wp_set_object_terms answers with a WP_Error on a
            // failed insert or a broken taxonomy, and swallowing it reported terms as
            // assigned that are not on the post. A failure is not a refusal - the
            // caller was allowed to do this and it did not happen - so it gets its own
            // key rather than being folded into `refused`.
            $set = wp_set_object_terms($post_id, $ids, $tax, false);

            if (is_wp_error($set)) {
                $failed[$tax] = $set->get_error_message();
            } else {
                $assigned[$tax] = $ids;
            }
        }
    }

    return array('assigned' => $assigned, 'refused' => $refused, 'failed' => $failed);
}

/** Resolve an editable post by id, or a WP_Error. $badTypeMsg is the bad_type message. */
function wpmcp_get_editable_post($id, $badTypeMsg) {
    $id = (int) $id;
    $p0 = $id ? get_post($id) : null;
    if (!$p0) { return new WP_Error('wpmcp_not_found', 'No post with that ID.'); }
    if (!wpmcp_post_type_ok($p0->post_type)) { return new WP_Error('wpmcp_bad_type', $badTypeMsg); }
    return $p0;
}

/** Resolve an attachment by id, or a WP_Error. */
function wpmcp_get_attachment($id) {
    $id = (int) $id;
    $p = $id ? get_post($id) : null;
    if (!$p || $p->post_type !== 'attachment') { return new WP_Error('wpmcp_not_found', 'No attachment with that ID.'); }
    return $p;
}

/** Resolve+denylist a code target. Returns array('abs'=>..,'rel'=>..) or a WP_Error. */
function wpmcp_code_target($a, $mustExist) {
    $r = wpmcp_code_resolve(isset($a['path']) ? $a['path'] : '', $mustExist);
    if (!$r['ok']) { return new WP_Error('wpmcp_path', $r['error']); }
    if (wpmcp_code_denied($r['rel'])) { return new WP_Error('wpmcp_denied', 'That file is on the denylist.'); }
    return array('abs' => $r['abs'], 'rel' => $r['rel']);
}

/* ============================================================
 * Core read tools (site-info / list-posts / get-post)
 * ========================================================== */
function wpmcp_core_tools() {
    return array(
        'site-info' => array(
            'write' => false,
            'description' => 'Site name, URL, WordPress version, active theme, active plugin count.',
            'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
            'run' => function ($args) {
                $theme = wp_get_theme();
                return array(
                    'name'           => get_bloginfo('name'),
                    'url'            => home_url(),
                    'wp_version'     => get_bloginfo('version'),
                    'active_theme'   => $theme ? ($theme->get('Name') . ' ' . $theme->get('Version')) : null,
                    'active_plugins' => count((array) get_option('active_plugins', array())),
                );
            },
        ),
        'list-posts' => array(
            'write' => false,
            'description' => 'List recent content the caller is allowed to see. Args: post_type (default "post"), status (default: every status the caller may see), limit (default 20, max 100).',
            'inputSchema' => array('type' => 'object', 'properties' => array(
                'post_type' => array('type' => 'string'),
                'status'    => array('type' => 'string'),
                'limit'     => array('type' => 'integer'),
            )),
            'run' => function ($args) {
                $type = isset($args['post_type']) ? sanitize_key($args['post_type']) : 'post';
                // Same allow-list the write tools use: no revisions, no nav_menu_item,
                // no wp_template, no attachments (media has its own tools).
                if (!wpmcp_post_type_ok($type)) {
                    return new WP_Error('wpmcp_bad_type', 'Not a listable post type: ' . $type);
                }
                $limit = isset($args['limit']) ? min(100, max(1, (int) $args['limit'])) : 20;

                // 'any' is never handed to WP_Query: it takes a branch where `perm` is
                // not consulted at all, so it lists every author's private and draft
                // posts. The permitted set is decided from capabilities instead.
                $permitted = wpmcp_listable_statuses($type);
                $own       = wpmcp_own_listable_statuses($type);
                $asked     = isset($args['status']) ? sanitize_key($args['status']) : '';

                if ($asked !== '' && $asked !== 'any') {
                    // Intersection, not a refusal: asking for a status you may not see
                    // is answered with an empty list, the same answer as a status with
                    // nothing in it. An error here would say "that status exists and is
                    // being withheld", which is the disclosure get-post avoids too.
                    $permitted = array_values(array_intersect($permitted, array($asked)));
                    $own       = array_values(array_intersect($own, array($asked)));
                }

                $posts = array();
                if ($permitted) {
                    $q = new WP_Query(array(
                        'post_type'      => $type,
                        'post_status'    => $permitted,
                        'posts_per_page' => $limit,
                        'no_found_rows'  => true,
                        // Belt and braces over the list above: on an explicit status
                        // list this also scopes `private` to the user's own posts when
                        // they lack read_private_posts.
                        'perm'           => 'readable',
                    ));
                    $posts = $q->posts;
                }
                // Own unpublished work, which the first query cannot reach - see
                // wpmcp_own_listable_statuses(). Disjoint status sets, so no duplicates.
                if ($own) {
                    $q2 = new WP_Query(array(
                        'post_type'      => $type,
                        'post_status'    => $own,
                        'author'         => get_current_user_id(),
                        'posts_per_page' => $limit,
                        'no_found_rows'  => true,
                    ));
                    $posts = array_merge($posts, $q2->posts);
                    // Re-impose WP_Query's own ordering across the merge, then the
                    // limit, so `limit` still means what it says.
                    //
                    // post_date, NOT post_date_gmt. Every status registered with
                    // date_floating - draft, pending, auto-draft - is stored by
                    // wp_insert_post with post_date_gmt AND post_modified_gmt set to
                    // '0000-00-00 00:00:00' (measured on WP 7.1; only the non-GMT
                    // columns are populated). Sorting on either GMT column therefore
                    // puts every own draft behind every dated post, and the slice
                    // below drops them first - so on any site with `limit` published
                    // posts or more, the own-draft case this merge exists for failed.
                    // post_date is also the column WP_Query's own `orderby => date`
                    // uses, so both halves stay in the order they arrived in.
                    usort($posts, function ($a, $b) {
                        $cmp = strcmp((string) $b->post_date, (string) $a->post_date);
                        return $cmp !== 0 ? $cmp : ((int) $b->ID - (int) $a->ID);
                    });
                    $posts = array_slice($posts, 0, $limit);
                }

                $items = array();
                foreach ($posts as $p) {
                    $items[] = array(
                        'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                        'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink($p),
                    );
                }
                return array('count' => count($items), 'items' => $items);
            },
        ),
        'get-post' => array(
            'write' => false,
            'description' => 'Get title/status/raw content for a post or page. Args: id (integer, required).',
            'inputSchema' => array('type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id')),
            'run' => function ($args) {
                $id = isset($args['id']) ? (int) $args['id'] : 0;
                $p = $id ? get_post($id) : null;
                if (!$p) { return new WP_Error('wpmcp_not_found', 'No post with that ID.'); }
                // Deliberately the SAME error as a missing post, not a distinct
                // "forbidden": a caller who cannot read the post must not be able to
                // learn that it exists by probing IDs.
                if (!current_user_can('read_post', $id)) {
                    return new WP_Error('wpmcp_not_found', 'No post with that ID.');
                }
                // Also not_found, not a third message. `bad_type` here named the type
                // it had refused, so probing ids told a Subscriber "exists, and is a
                // revision / attachment / wp_block / nav_menu_item" - read_post on
                // those maps to the parent's or the published item's `read`, so the
                // cap check above does not stop the probe. No content was returned
                // either way; the shape was the leak.
                if (!wpmcp_post_type_ok($p->post_type)) {
                    return new WP_Error('wpmcp_not_found', 'No post with that ID.');
                }
                return array(
                    'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                    'status' => $p->post_status, 'slug' => $p->post_name, 'content' => $p->post_content,
                );
            },
        ),
    );
}

/* ============================================================
 * Content tools (create-post / update-post / delete-post)
 * ========================================================== */
function wpmcp_content_tools() {
    return array(

    'create-post' => array(
        'write' => true,
        'description' => 'Create a post or page. Args: title, content, post_type (default post), status (default draft), excerpt, slug, terms {taxonomy:[id or name]}.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'title' => array('type' => 'string'), 'content' => array('type' => 'string'),
            'post_type' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
        )),
        'run' => function ($a) {
            $postarr = array(
                'post_title'   => isset($a['title']) ? wp_strip_all_tags((string) $a['title']) : '',
                'post_content' => isset($a['content']) ? (string) $a['content'] : '',
                'post_type'    => isset($a['post_type']) ? sanitize_key($a['post_type']) : 'post',
                'post_status'  => isset($a['status']) ? sanitize_key($a['status']) : 'draft',
            );
            if (!wpmcp_post_type_ok($postarr['post_type'])) {
                return new WP_Error('wpmcp_bad_type', 'Unsupported post_type (use a public content type; attachments use the media tools).');
            }
            // wp_insert_post checks nothing. create_posts is the cap wp-admin gates
            // the "Add New" screen on; publishing is a second, separate capability,
            // which is the whole difference between a Contributor and an Author.
            $pto = get_post_type_object($postarr['post_type']);
            if (!current_user_can($pto->cap->create_posts)) {
                return wpmcp_cannot('create ' . $postarr['post_type'] . ' content');
            }
            // `private` belongs with publish/future: core's own post.php requires
            // publish_posts for it, because publishing privately is still publishing.
            if (in_array($postarr['post_status'], wpmcp_publishing_statuses(), true)
                && !current_user_can($pto->cap->publish_posts)) {
                return wpmcp_cannot('publish ' . $postarr['post_type'] . ' content');
            }
            if (isset($a['excerpt'])) { $postarr['post_excerpt'] = (string) $a['excerpt']; }
            if (isset($a['slug']))    { $postarr['post_name'] = sanitize_title((string) $a['slug']); }
            $id = wp_insert_post($postarr, true);
            if (is_wp_error($id)) { return $id; }
            $out = array('id' => (int) $id, 'link' => get_permalink($id));
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                // Reported, not swallowed: a caller that asked for three categories
                // and got two has to be able to see which one did not happen.
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }
            $p = get_post($id);
            $out['status'] = $p ? $p->post_status : null;
            return $out;
        },
    ),

    'update-post' => array(
        'write' => true,
        'description' => 'Update a post/page. Args: id (required) plus any of title, content, status, excerpt, slug, terms. Set status=publish to publish.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'title' => array('type' => 'string'),
            'content' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = wpmcp_get_editable_post($id, 'That item is not an editable content type.');
            if (is_wp_error($p0)) { return $p0; }
            // wp_update_post checks nothing. edit_post is the meta cap, so it resolves
            // per post: own vs others', published vs not, via map_meta_cap.
            if (!current_user_can('edit_post', $id)) {
                return wpmcp_cannot('edit post ' . $id);
            }
            $upd = array('ID' => $id); $changed = array();
            if (isset($a['title']))   { $upd['post_title'] = wp_strip_all_tags((string) $a['title']); $changed[] = 'title'; }
            if (isset($a['content'])) { $upd['post_content'] = (string) $a['content']; $changed[] = 'content'; }
            if (isset($a['status'])) {
                $upd['post_status'] = sanitize_key($a['status']);
                $changed[] = 'status';
                // Publishing somebody else's draft is a capability of its own, and
                // edit_post does not imply it (a Contributor may edit, never publish).
                // `private` is in that set too - see wpmcp_publishing_statuses().
                if (in_array($upd['post_status'], wpmcp_publishing_statuses(), true)) {
                    $pto = get_post_type_object($p0->post_type);
                    if (!current_user_can($pto->cap->publish_posts)) {
                        return wpmcp_cannot('publish ' . $p0->post_type . ' content');
                    }
                }
                // Trashing through update-post is a delete by another name, so it
                // answers to delete_post, not edit_post - otherwise delete-post's
                // gate is one argument away from being bypassed.
                if ($upd['post_status'] === 'trash' && !current_user_can('delete_post', $id)) {
                    return wpmcp_cannot('trash post ' . $id);
                }
            }
            if (isset($a['excerpt'])) { $upd['post_excerpt'] = (string) $a['excerpt']; $changed[] = 'excerpt'; }
            if (isset($a['slug']))    { $upd['post_name'] = sanitize_title((string) $a['slug']); $changed[] = 'slug'; }
            $r = wp_update_post($upd, true);
            if (is_wp_error($r)) { return $r; }
            $out = array('id' => $id, 'link' => get_permalink($id));
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                $changed[] = 'terms';
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }
            $p = get_post($id);
            $out['status']  = $p->post_status;
            $out['changed'] = $changed;
            return $out;
        },
    ),

    'delete-post' => array(
        'write' => true,
        'description' => 'Delete a post/page. Args: id (required), force (default false). force=false trashes; force=true permanently deletes.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'force' => array('type' => 'boolean'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = wpmcp_get_editable_post($id, 'That item is not a deletable content type (attachments use delete-media).');
            if (is_wp_error($p0)) { return $p0; }
            // wp_delete_post checks nothing. delete_post is a meta cap, so it resolves
            // per post - own vs others', published vs not.
            if (!current_user_can('delete_post', $id)) {
                return wpmcp_cannot('delete post ' . $id);
            }
            $force = !empty($a['force']);
            $r = wp_delete_post($id, $force);
            if (!$r) { return new WP_Error('wpmcp_delete_failed', 'Could not delete.'); }
            return array('id' => $id, 'deleted' => $force, 'trashed' => !$force);
        },
    ),

    );
}

/* ============================================================
 * Taxonomy tools (list-terms / create-term / delete-term)
 * ========================================================== */
function wpmcp_taxonomy_tools() {
    return array(

    'list-terms' => array(
        'write' => false,
        'description' => 'List taxonomy terms. Args: taxonomy (default category), search, hide_empty (default false).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'search' => array('type' => 'string'),
            'hide_empty' => array('type' => 'boolean'),
        )),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : 'category';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            $terms = get_terms(array(
                'taxonomy' => $tax, 'hide_empty' => !empty($a['hide_empty']),
                'search' => isset($a['search']) ? (string) $a['search'] : '',
            ));
            if (is_wp_error($terms)) { return $terms; }
            $out = array();
            foreach ($terms as $t) {
                $out[] = array('id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug,
                    'taxonomy' => $t->taxonomy, 'count' => $t->count, 'parent' => $t->parent);
            }
            return array('count' => count($out), 'terms' => $out);
        },
    ),

    'create-term' => array(
        'write' => true,
        'description' => 'Create a taxonomy term. Args: taxonomy (required), name (required), slug, parent, description.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'name' => array('type' => 'string'),
            'slug' => array('type' => 'string'), 'parent' => array('type' => 'integer'),
            'description' => array('type' => 'string'),
        ), 'required' => array('taxonomy', 'name')),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : '';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            // wp_insert_term checks nothing. edit_terms is the cap WordPress maps for
            // creating and editing a term (manage_terms gates the admin LIST screen);
            // for the core taxonomies all three resolve to manage_categories anyway.
            $tax_obj = get_taxonomy($tax);
            if (!current_user_can($tax_obj->cap->edit_terms)) {
                return wpmcp_cannot('create terms in ' . $tax);
            }
            $args = array();
            if (isset($a['slug']))        { $args['slug'] = sanitize_title((string) $a['slug']); }
            if (isset($a['parent']))      { $args['parent'] = (int) $a['parent']; }
            if (isset($a['description'])) { $args['description'] = (string) $a['description']; }
            $r = wp_insert_term((string) $a['name'], $tax, $args);
            if (is_wp_error($r)) { return $r; }
            $t = get_term($r['term_id'], $tax);
            return array('id' => (int) $r['term_id'], 'name' => $t->name, 'slug' => $t->slug);
        },
    ),

    'delete-term' => array(
        'write' => true,
        'description' => 'Delete a taxonomy term. Args: taxonomy (required), id (required).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'id' => array('type' => 'integer'),
        ), 'required' => array('taxonomy', 'id')),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : '';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            // wp_delete_term checks nothing.
            $tax_obj = get_taxonomy($tax);
            if (!current_user_can($tax_obj->cap->delete_terms)) {
                return wpmcp_cannot('delete terms in ' . $tax);
            }
            $r = wp_delete_term((int) $a['id'], $tax);
            if (is_wp_error($r)) { return $r; }
            if (!$r) { return new WP_Error('wpmcp_not_found', 'Term not found.'); }
            return array('id' => (int) $a['id'], 'deleted' => true);
        },
    ),

    );
}

/* ============================================================
 * Media tools (list-media / get-media / upload-media / delete-media)
 * ========================================================== */
function wpmcp_media_tools() {
    return array(

    'list-media' => array(
        'write' => false,
        'description' => 'List media attachments. Args: search, mime_type, page (default 1), per_page (default 20, max 100).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'search' => array('type' => 'string'), 'mime_type' => array('type' => 'string'),
            'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'),
        )),
        'run' => function ($a) {
            $q = new WP_Query(array(
                'post_type' => 'attachment', 'post_status' => 'inherit',
                's' => isset($a['search']) ? (string) $a['search'] : '',
                'post_mime_type' => isset($a['mime_type']) ? (string) $a['mime_type'] : '',
                'paged' => isset($a['page']) ? max(1, (int) $a['page']) : 1,
                'posts_per_page' => isset($a['per_page']) ? min(100, max(1, (int) $a['per_page'])) : 20,
            ));
            $out = array();
            foreach ($q->posts as $p) {
                // An attachment's read_post resolves through its parent post's status,
                // so this is what keeps the media of a private or draft post - titles
                // and, worse, direct file URLs - out of a token that cannot read the
                // post it belongs to. WP_Query has no perm handling for
                // post_status=inherit, so the filter has to be here.
                if (!current_user_can('read_post', (int) $p->ID)) { continue; }

                $out[] = array('id' => $p->ID, 'title' => get_the_title($p), 'mime' => $p->post_mime_type,
                    'url' => wp_get_attachment_url($p->ID), 'date' => $p->post_date_gmt);
            }
            return array('count' => count($out), 'items' => $out);
        },
    ),

    'get-media' => array(
        'write' => false,
        'description' => 'Get one media item. Args: id (required).',
        'inputSchema' => array('type' => 'object',
            'properties' => array('id' => array('type' => 'integer')), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = wpmcp_get_attachment($id);
            if (is_wp_error($p)) { return $p; }
            // Same non-disclosing refusal get-post uses: identical to the message a
            // missing id returns, so ids cannot be probed for existence.
            if (!current_user_can('read_post', $id)) {
                return new WP_Error('wpmcp_not_found', 'No attachment with that ID.');
            }
            $meta = wp_get_attachment_metadata($id);
            $file = get_attached_file($id);
            return array(
                'id' => $id, 'title' => get_the_title($p), 'mime' => $p->post_mime_type,
                'url' => wp_get_attachment_url($id),
                'alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
                'caption' => $p->post_excerpt,
                'filesize' => ($file && file_exists($file)) ? filesize($file) : null,
                'width' => isset($meta['width']) ? $meta['width'] : null,
                'height' => isset($meta['height']) ? $meta['height'] : null,
            );
        },
    ),

    'upload-media' => array(
        'write' => true,
        'description' => 'Upload media by sideloading a URL. Args: source_url (required, http/https), filename, title, alt, post (attach to post id).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'source_url' => array('type' => 'string'), 'filename' => array('type' => 'string'),
            'title' => array('type' => 'string'), 'alt' => array('type' => 'string'),
            'post' => array('type' => 'integer'),
        ), 'required' => array('source_url')),
        'run' => function ($a) {
            // media_handle_sideload gates file TYPES, never the user. Checked before
            // the download, so a refused caller cannot use this tool to make the site
            // fetch arbitrary URLs.
            if (!current_user_can('upload_files')) {
                return wpmcp_cannot('upload files');
            }
            // Attaching to a post is a change to THAT post, which is why core's own
            // wp_ajax_upload_attachment requires edit_post on the parent. Checked here
            // too, and before the download, for the same reason as upload_files.
            $post = isset($a['post']) ? (int) $a['post'] : 0;
            if ($post > 0 && !current_user_can('edit_post', $post)) {
                return wpmcp_cannot('attach media to post ' . $post);
            }
            $url = isset($a['source_url']) ? esc_url_raw((string) $a['source_url']) : '';
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true)) {
                return new WP_Error('wpmcp_bad_url', 'source_url must be http or https.');
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $tmp = download_url($url, 20); // 20s timeout, not the 300s default
            if (is_wp_error($tmp)) { return $tmp; }
            $max = (int) wp_max_upload_size();
            $sz  = @filesize($tmp);
            if ($max > 0 && ($sz === false || $sz > $max)) {
                @unlink($tmp);
                return new WP_Error('wpmcp_too_big', 'Downloaded file exceeds the upload size limit.');
            }
            $name = isset($a['filename']) ? sanitize_file_name((string) $a['filename'])
                : sanitize_file_name(basename((string) wp_parse_url($url, PHP_URL_PATH)));
            if ($name === '') { $name = 'upload'; }
            $file = array('name' => $name, 'tmp_name' => $tmp);
            // $post was read and cap-checked above, before the download.
            $id = media_handle_sideload($file, $post, isset($a['title']) ? (string) $a['title'] : null);
            if (is_wp_error($id)) { @unlink($tmp); return $id; }
            if (isset($a['alt'])) { update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $a['alt'])); }
            return array('id' => (int) $id, 'url' => wp_get_attachment_url($id), 'mime' => get_post_mime_type($id));
        },
    ),

    'delete-media' => array(
        'write' => true,
        'description' => 'Delete a media attachment. Args: id (required), force (default false).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'force' => array('type' => 'boolean'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = wpmcp_get_attachment($id);
            if (is_wp_error($p)) { return $p; }
            // wp_delete_attachment checks nothing. delete_post on an attachment maps
            // through its own post type's caps (delete_posts / delete_others_posts).
            if (!current_user_can('delete_post', $id)) {
                return wpmcp_cannot('delete attachment ' . $id);
            }
            $r = wp_delete_attachment($id, !empty($a['force']));
            if (!$r) { return new WP_Error('wpmcp_delete_failed', 'Could not delete.'); }
            return array('id' => $id, 'deleted' => true);
        },
    ),

    );
}

/* ============================================================
 * Comment tools (list-comments / moderate-comment / reply-comment)
 * ========================================================== */
function wpmcp_comment_tools() {
    return array(

    'list-comments' => array(
        'write' => false,
        'description' => 'List comments the caller is allowed to read (emails and IPs never returned). Args: post (id), status (default "approve"; hold|spam|trash|all need moderate_comments and are otherwise treated as "approve"), search (matches comment text and author name), page, per_page.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'post' => array('type' => 'integer'), 'status' => array('type' => 'string'),
            'search' => array('type' => 'string'), 'page' => array('type' => 'integer'),
            'per_page' => array('type' => 'integer'),
        )),
        'run' => function ($a) {
            // WP_Comment_Query performs no capability checks of any kind (zero
            // current_user_can calls in the class), so every restriction here is this
            // tool's own. wp-admin gates unapproved comments on moderate_comments and
            // core's REST controller checks read_post per comment; match both.
            $status = isset($a['status']) ? sanitize_key($a['status']) : 'approve';
            if ($status !== 'approve' && !current_user_can('moderate_comments')) {
                // Fall back rather than refuse: an error would confirm that held or
                // spam comments exist and are being withheld. Same stance as
                // list-posts on an unpermitted status, and get-post on read_post.
                $status = 'approve';
            }

            $args = array(
                // Approved only unless asked otherwise. The old default of 'all' handed
                // spam and held-for-moderation text - unreviewed, attacker-supplied
                // content - to every read token without anyone asking for it.
                'status' => $status,
                'number' => isset($a['per_page']) ? min(100, max(1, (int) $a['per_page'])) : 20,
                'paged'  => isset($a['page']) ? max(1, (int) $a['page']) : 1,
            );
            if (isset($a['post'])) { $args['post_id'] = (int) $a['post']; }

            // `search` is NOT passed to WP_Comment_Query: it hard-codes the columns
            // comment_author, comment_author_email, comment_author_url,
            // comment_author_IP and comment_content, with no filter to narrow them
            // (verified in class-wp-comment-query.php). A tool that says "emails
            // omitted" while letting a caller prefix-probe them by search does not
            // omit them. The clause is built here over the two safe columns instead,
            // which keeps the filtering - and therefore the pagination - in SQL.
            $search = isset($a['search']) ? trim((string) $a['search']) : '';
            $filter = null;
            if ($search !== '') {
                // WP_Comment_Query keys its cache on the query vars it RECOGNISES, and
                // a clause injected through comments_clauses is not one of them - so
                // with a persistent object cache an unfiltered call and a search with
                // the same status/number/paged would share a cached id list. No leak
                // (the read_post filter runs after the fetch) but the search would be
                // wrong. cache_domain is a recognised var that exists for exactly this.
                $args['cache_domain'] = 'wpmcp-search-' . md5($search);

                $filter = function ($clauses) use ($search) {
                    global $wpdb;
                    $like = '%' . $wpdb->esc_like($search) . '%';
                    $clauses['where'] .= $wpdb->prepare(
                        " AND ({$wpdb->comments}.comment_content LIKE %s"
                        . " OR {$wpdb->comments}.comment_author LIKE %s)",
                        $like,
                        $like
                    );
                    return $clauses;
                };
                add_filter('comments_clauses', $filter);
            }

            try {
                $cs = get_comments($args);
            } finally {
                if ($filter) { remove_filter('comments_clauses', $filter); }
            }

            $out = array();
            foreach ($cs as $c) {
                // A comment on a post the caller cannot read is a read of that post:
                // it leaks the post's existence plus author names, text and dates.
                // This is the same leak get-post closes, one indirection away.
                if (!current_user_can('read_post', (int) $c->comment_post_ID)) { continue; }

                $out[] = array('id' => (int) $c->comment_ID, 'post' => (int) $c->comment_post_ID,
                    'author_name' => $c->comment_author, 'content' => $c->comment_content,
                    'status' => wp_get_comment_status((int) $c->comment_ID), 'date' => $c->comment_date_gmt);
            }
            // count is what the caller may see, so it is smaller than per_page when
            // the page held comments on unreadable posts. Paging is still by per_page.
            return array('count' => count($out), 'items' => $out);
        },
    ),

    'moderate-comment' => array(
        'write' => true,
        'description' => 'Moderate a comment. Args: id (required), action (approve|unapprove|spam|trash|untrash).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'action' => array('type' => 'string'),
        ), 'required' => array('id', 'action')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            if (!$id || !get_comment($id)) { return new WP_Error('wpmcp_not_found', 'No comment with that ID.'); }
            // wp_set_comment_status and friends check nothing. moderate_comments is
            // the cap wp-admin requires for the whole moderation queue, and
            // edit_comment maps to it on the post's editors.
            if (!current_user_can('moderate_comments') && !current_user_can('edit_comment', $id)) {
                return wpmcp_cannot('moderate comment ' . $id);
            }
            $action = isset($a['action']) ? sanitize_key($a['action']) : '';
            $valid = array('approve', 'unapprove', 'spam', 'trash', 'untrash');
            if (!in_array($action, $valid, true)) { return new WP_Error('wpmcp_bad_action', 'Unknown action.'); }
            if ($action === 'trash')        { $ok = wp_trash_comment($id); }
            elseif ($action === 'untrash')  { $ok = wp_untrash_comment($id); }
            elseif ($action === 'spam')     { $ok = wp_spam_comment($id); }
            elseif ($action === 'unapprove'){ $ok = wp_set_comment_status($id, 'hold'); }
            else                            { $ok = wp_set_comment_status($id, 'approve'); }
            if (!$ok) { return new WP_Error('wpmcp_failed', 'Action failed.'); }
            return array('id' => $id, 'status' => wp_get_comment_status($id));
        },
    ),

    'reply-comment' => array(
        'write' => true,
        'description' => 'Reply to a comment. Args: id (required, parent comment), content (required).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'content' => array('type' => 'string'),
        ), 'required' => array('id', 'content')),
        'run' => function ($a) {
            $parent = isset($a['id']) ? (int) $a['id'] : 0;
            $pc = $parent ? get_comment($parent) : null;
            if (!$pc) { return new WP_Error('wpmcp_not_found', 'No parent comment.'); }
            $post_id  = (int) $pc->comment_post_ID;
            $moderator = current_user_can('moderate_comments');

            // Neither wp_insert_comment nor wp_new_comment checks a capability, and
            // neither checks comments_open: core's comments_open() guard lives in
            // wp_handle_comment_submission, the front-end path, which this never
            // reaches. read_post was too low a bar - this writes, it does not read.
            // wp-admin's reply requires edit_post on the post being replied on; match
            // that. These three gates stay IN FRONT of core's pipeline below, because
            // core's pipeline decides approval, not authorisation.
            //
            // not_found rather than a forbidden, and checked with read_post first, so a
            // caller who cannot even see the post does not learn the comment exists.
            if (!current_user_can('read_post', $post_id)) {
                return new WP_Error('wpmcp_not_found', 'No parent comment.');
            }
            if (!current_user_can('edit_post', $post_id)) {
                return wpmcp_cannot('reply to comments on post ' . $post_id);
            }

            // A closed thread is a decision someone made. A moderator may still reply
            // (wp-admin lets them); nobody else reopens it by calling a tool.
            if (!comments_open($post_id) && !$moderator) {
                return wpmcp_cannot('reply on post ' . $post_id . ', where comments are closed');
            }

            $u   = wp_get_current_user();
            $now = current_time('mysql');
            // Every key wp_new_comment() and wp_allow_comment() read is supplied,
            // including the ones core would otherwise fill from $_SERVER:
            // comment_author_IP goes through wpmcp_client_ip() so the proxy filter
            // applies, and comment_agent names this plugin rather than whatever
            // User-Agent the MCP client happened to send.
            $comment = array(
                'comment_post_ID'      => $post_id,
                'comment_parent'       => $parent,
                'comment_content'      => (string) $a['content'],
                'user_id'              => $u ? $u->ID : 0,
                'comment_author'       => $u ? $u->display_name : '',
                'comment_author_email' => $u ? $u->user_email : '',
                'comment_author_url'   => $u ? $u->user_url : '',
                'comment_author_IP'    => wpmcp_client_ip(),
                'comment_agent'        => 'wp-mcp/' . WPMCP_VER,
                'comment_date'         => $now,
                'comment_date_gmt'     => current_time('mysql', true),
                'comment_type'         => 'comment',
            );

            // CORE'S PIPELINE, not a hand-rolled one.
            //
            // This used to call wp_allow_comment() and then wp_insert_comment()
            // directly, and claimed in a commit message that "Akismet still applies".
            // It did not. Akismet, and every other spam or moderation plugin, hooks
            // `preprocess_comment` - applied in wp_new_comment()
            // (wp-includes/comment.php, first thing it does) - and `comment_post`,
            // which is where the moderator and post-author notification mails come
            // from. Neither fires from wp_insert_comment(). wp_allow_comment() alone
            // gives the blocklist, the moderation option, the duplicate check and the
            // flood check, and nothing else.
            //
            // comment_approved is deliberately NOT set here. wp_new_comment() ->
            // wp_allow_comment() -> wp_check_comment_data() already answers 1 for a
            // user who holds moderate_comments or who owns the post, and runs
            // everybody else past check_comment and the blocklist - so the two-branch
            // version of this collapsed into core's own decision, which is the one the
            // front end and the REST controller both use.
            //
            // $wp_error = true, so a duplicate (409) or a flood (429) comes back as a
            // WP_Error with its message instead of wp_die()ing inside a REST request.
            $cid = wp_new_comment($comment, true);

            if (is_wp_error($cid)) { return $cid; }
            if (!$cid) { return new WP_Error('wpmcp_failed', 'Could not create reply.'); }

            return array(
                'id'     => (int) $cid,
                'status' => wp_get_comment_status((int) $cid),
            );
        },
    ),

    );
}

/* ============================================================
 * Code-edit tools (admin scope; only listed when wpmcp_code_enabled())
 * ========================================================== */
function wpmcp_code_tools() {
    return array(

    'code-list' => array(
        'write' => true,
        'description' => 'List files/dirs in the active theme. Args: path (relative, default ""). Denylisted entries show blocked=true.',
        'inputSchema' => array('type' => 'object', 'properties' => array('path' => array('type' => 'string'))),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $root = wpmcp_code_root();
            if ($root === '') { return new WP_Error('wpmcp_no_theme', 'Active theme directory not found.'); }
            $rel = isset($a['path']) ? ltrim(str_replace('\\', '/', (string) $a['path']), '/') : '';
            if (strpos($rel, '..') !== false) { return new WP_Error('wpmcp_illegal', 'Illegal path.'); }
            $dir = $root . ($rel !== '' ? '/' . $rel : '');
            $abs = realpath($dir);
            if ($abs === false || !wpmcp_path_within($abs, $root) || !is_dir($abs)) {
                return new WP_Error('wpmcp_not_found', 'No such directory.');
            }
            $entries = array();
            foreach (scandir($abs) as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $full = $abs . '/' . $name;
                $erel = ($rel !== '' ? $rel . '/' : '') . $name;
                $entries[] = array(
                    'name' => $name, 'type' => is_dir($full) ? 'dir' : 'file',
                    'size' => is_file($full) ? filesize($full) : 0,
                    'blocked' => wpmcp_code_denied($erel),
                );
            }
            return array('path' => $rel, 'entries' => $entries);
        },
    ),

    'code-read' => array(
        'write' => true,
        'description' => 'Read a text file in the active theme. Args: path (required). Denylisted/binary/oversized files are refused.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $r = wpmcp_code_target($a, true);
            if (is_wp_error($r)) { return $r; }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('wpmcp_ext', 'Only text files may be read.'); }
            if (!is_file($r['abs'])) { return new WP_Error('wpmcp_not_found', 'Not a file.'); }
            if (filesize($r['abs']) > 524288) { return new WP_Error('wpmcp_too_big', 'File exceeds 512KB.'); }
            return array('path' => $r['rel'], 'content' => file_get_contents($r['abs']));
        },
    ),

    'code-write' => array(
        'write' => true,
        'description' => 'Create or overwrite a text file in the active theme. Args: path (required), content (required). Backs up to .bak; PHP is parse-checked and auto-reverted on a syntax error.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'path' => array('type' => 'string'), 'content' => array('type' => 'string'),
        ), 'required' => array('path', 'content')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $r = wpmcp_code_target($a, false);
            if (is_wp_error($r)) { return $r; }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('wpmcp_ext', 'Only text files may be written.'); }
            $content = isset($a['content']) ? (string) $a['content'] : '';
            if (strlen($content) > 524288) { return new WP_Error('wpmcp_too_big', 'Content exceeds 512KB.'); }

            $existed = is_file($r['abs']);
            $bak = $r['abs'] . '.bak';
            if (!wpmcp_bak_ok($bak)) { return new WP_Error('wpmcp_bak_unsafe', 'Backup path is unsafe (symlink or outside theme).'); }
            if ($existed) { @copy($r['abs'], $bak); }
            $bytes = file_put_contents($r['abs'], $content);
            if ($bytes === false) { return new WP_Error('wpmcp_write_failed', 'Could not write file.'); }

            $reverted = false; $perr = null;
            if (strtolower(pathinfo($r['rel'], PATHINFO_EXTENSION)) === 'php') {
                $chk = wpmcp_php_parse_ok($content);
                if ($chk !== true) {
                    $perr = $chk;
                    if ($existed) { @copy($bak, $r['abs']); } else { @unlink($r['abs']); }
                    $reverted = true;
                }
            }
            $out = array(
                'path' => $r['rel'], 'bytes' => $reverted ? 0 : (int) $bytes,
                'created' => ($existed ? false : !$reverted), 'reverted' => $reverted,
            );
            if ($perr !== null) { $out['error'] = 'PHP parse error (reverted): ' . $perr; }
            return $out;
        },
    ),

    'code-delete' => array(
        'write' => true,
        'description' => 'Delete a file in the active theme (moved to .bak, not unlinked). Args: path (required).',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $r = wpmcp_code_target($a, true);
            if (is_wp_error($r)) { return $r; }
            if (!is_file($r['abs'])) { return new WP_Error('wpmcp_not_found', 'Not a file.'); }
            $bak = $r['abs'] . '.bak';
            if (!wpmcp_bak_ok($bak)) { return new WP_Error('wpmcp_bak_unsafe', 'Backup path is unsafe (symlink or outside theme).'); }
            if (!@rename($r['abs'], $bak)) { return new WP_Error('wpmcp_delete_failed', 'Could not move file to .bak.'); }
            return array('path' => $r['rel'], 'deleted' => true, 'backup' => basename($bak));
        },
    ),

    );
}
