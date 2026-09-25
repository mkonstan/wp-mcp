<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP MODULE: content-type discovery. list-content-types.
 *
 * THE MEASUREMENT THAT ASKED FOR IT, and it is the only reason this tool exists. A cold
 * client - an instance forbidden from reading this source - was pointed at a real customer
 * site and ranked ONE thing as the surface's worst mislead (analysis/66): `list-posts`
 * defaults to `post_type: "post"` and answered with ONE item, while the site's actual
 * content sat in five custom post types (`area`, `service`, `industrie`, `team`, `resource`)
 * that NOTHING in the tool surface named. It found them by reverse-engineering the `object`
 * field of `get-menu` results. A migration cannot copy post types it cannot discover, and
 * that migration is the use case driving the product.
 *
 * A TOOL, NOT A FIELD IN site-info, and the reason is the failure itself: a cold client
 * reads the tool LIST. An inventory buried inside another tool's result is exactly as
 * invisible as the custom types are now, because nothing tells the model to go and look
 * there. A name in the list is the only part of this server a model reads unprompted.
 *
 * TAXONOMIES IN THE SAME TOOL, for the same reason and one more: `list-terms` defaults to
 * `category`, and on the site above that taxonomy holds a single term because none of the
 * five custom types registers one. Two tools would mean two chances to not be called; and
 * the question a caller actually has is "what kinds of content are here", which does not
 * split along the post-type/taxonomy line.
 *
 * WHAT IS LEFT OUT, DELIBERATELY. Only post types WordPress itself treats as VIEWABLE are
 * listed, which is exactly the set wpmcp_post_type_ok() lets list-posts and get-post accept,
 * so every name here is a name those tools take. Only taxonomies is_taxonomy_viewable()
 * accepts are listed - IN BOTH PLACES A TAXONOMY NAME APPEARS, which the first version got
 * right at the top level and wrong inside each post type's `taxonomies` field; one unfiltered
 * get_object_taxonomies() call undid the whole paragraph, because a name withheld from one
 * list and published in the other is not withheld. A plugin's internal, non-public types and taxonomies - ACF's field
 * groups, a cache plugin's log type - are therefore absent, and that is a disclosure
 * decision rather than a tidiness one: `list-plugins` is an admin-scope tool on purpose, and
 * an unfiltered type list is a plugin inventory by another route. `attachment` is absent
 * because wpmcp_post_type_ok() excludes it; list-media is the tool for media.
 *
 * THE COUNTS ARE CAPABILITY-SCOPED BY CORE'S OWN RULE. Each post type's `counts` carries
 * only the statuses wpmcp_listable_statuses() says this caller may see listed - the same
 * function list-posts uses to decide which statuses it queries - so an Author sees `publish`
 * alone and a token bound to a Subscriber sees what the front end already shows. It does NOT
 * add the caller's own unpublished posts, which list-posts finds through a second
 * author-scoped query; a count is not a listing, and one number that silently mixes two
 * visibility rules would be worse than a number that states its rule.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * One post type, as this tool reports it.
 *
 * `rest_base` is null when the type is not in REST at all, rather than a string a caller
 * could send to a route that does not exist. When it IS in REST and registered no explicit
 * base, core uses the type's own name, so that is what this returns - the same fallback
 * class-wp-rest-server.php applies.
 */
function wpmcp_discovery_post_type($name, $pto) {
    $counts = array();
    $all    = wp_count_posts($name);

    foreach (wpmcp_listable_statuses($name) as $status) {
        if (is_object($all) && isset($all->$status)) { $counts[$status] = (int) $all->$status; }
    }

    $inRest = !empty($pto->show_in_rest);

    return array(
        'name'            => $name,
        'label'           => (string) $pto->label,
        'singular_label'  => isset($pto->labels->singular_name) ? (string) $pto->labels->singular_name : (string) $pto->label,
        'description'     => (string) $pto->description,
        'hierarchical'    => (bool) $pto->hierarchical,
        'public'          => (bool) $pto->public,
        'show_in_rest'    => $inRest,
        'rest_base'       => $inRest ? (string) (!empty($pto->rest_base) ? $pto->rest_base : $name) : null,
        // FILTERED BY THE SAME GATE THE TOP-LEVEL `taxonomies` LIST USES, and the first version
        // was not - found by review. get_object_taxonomies() answers with EVERY taxonomy
        // attached to the type, `public => false` ones included, so on a WooCommerce site a
        // read-scope token was handed `product_visibility`, `product_type` and
        // `product_shipping_class` here while the tool's own description promised it withheld
        // exactly those. One field, and it undid the paragraph above it.
        'taxonomies'      => wpmcp_discovery_viewable_taxonomies($name),
        'counts'          => $counts,
    );
}

/**
 * The taxonomies attached to $postType that this tool may name - `is_taxonomy_viewable()`, the
 * same gate the top-level `taxonomies` list applies, asked once per type.
 *
 * AND WHAT THIS DOES NOT FIX, said out loud so nobody reads the gate as complete: `list-terms`
 * accepts any taxonomy that `taxonomy_exists()`, viewable or not, so a caller who ALREADY KNOWS
 * a private taxonomy's name can still list its terms. That is behaviour this tool did not
 * introduce and does not change; what it must not do is hand over the name. Leaving `list-terms`
 * as it is was a decision rather than an oversight - a viewability gate there would also refuse a
 * `show_in_rest => true, public => false` taxonomy that a site deliberately exposes through
 * core's own REST API, and that needs measuring on a real plugin-heavy site before it is
 * narrowed. See analysis/68, round 2.
 *
 * @return list<string>
 */
function wpmcp_discovery_viewable_taxonomies($postType) {
    $names = array();

    foreach ((array) get_object_taxonomies($postType) as $name) {
        if (is_taxonomy_viewable($name)) { $names[] = (string) $name; }
    }

    return $names;
}

/**
 * One taxonomy, as this tool reports it.
 *
 * `terms` counts EVERY term including the empty ones, which is the opposite of list-terms'
 * `hide_empty` default and is the number a caller asking "is anything here" needs: a
 * taxonomy with fifty unused terms is a taxonomy somebody set up and never filled, and
 * hiding that is how the one-term `category` above looked like the whole story.
 */
function wpmcp_discovery_taxonomy($name, $tax) {
    $terms  = wp_count_terms(array('taxonomy' => $name, 'hide_empty' => false));
    $inRest = !empty($tax->show_in_rest);

    return array(
        'name'           => $name,
        'label'          => (string) $tax->label,
        'singular_label' => isset($tax->labels->singular_name) ? (string) $tax->labels->singular_name : (string) $tax->label,
        'description'    => (string) $tax->description,
        'hierarchical'   => (bool) $tax->hierarchical,
        'public'         => (bool) $tax->public,
        'show_in_rest'   => $inRest,
        'rest_base'      => $inRest ? (string) (!empty($tax->rest_base) ? $tax->rest_base : $name) : null,
        'post_types'     => array_values(array_map('strval', (array) $tax->object_type)),
        'terms'          => is_wp_error($terms) ? null : (int) $terms,
    );
}

/** The module's tools. Registered through the seam at the foot of this file. */
function wpmcp_discovery_tools() {
    return array(

    'list-content-types' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List the post types and taxonomies this site has. Every post type'
            . ' named here is a value list-posts and get-post accept for post_type, and their'
            . ' default is "post" - so a site whose content lives in custom types looks empty'
            . ' until you read this. Returns post_types and taxonomies. Each post type: name,'
            . ' label, singular_label, description, hierarchical, public, show_in_rest,'
            . ' rest_base (null when it is not in REST), taxonomies (its VIEWABLE taxonomies)'
            . ' and counts (status => how many posts, carrying only the'
            . ' statuses your capabilities let you see listed, so an Author sees publish'
            . ' alone; your own unpublished posts are not added in). Each taxonomy: the same'
            . ' first eight fields, plus post_types (the types it covers) and terms'
            . ' (how many terms it holds, empty ones included). Only types and taxonomies'
            . ' WordPress treats as viewable are listed, and never attachment; list-terms'
            . ' still accepts a private taxonomy\'s name if you know it. Needs no capability'
            . ' beyond the token.',
        'inputSchema' => array('type' => 'object', 'properties' => array()),
        'run' => function ($a) {
            $types = array();

            foreach (get_post_types(array(), 'objects') as $name => $pto) {
                $name = (string) $name;
                // The set list-posts and get-post accept, asked of the same function they
                // ask, so this tool cannot advertise a type those two would refuse.
                if (!wpmcp_post_type_ok($name)) { continue; }

                $types[] = wpmcp_discovery_post_type($name, $pto);
            }

            $taxonomies = array();

            foreach (get_taxonomies(array(), 'objects') as $name => $tax) {
                if (!is_taxonomy_viewable($tax)) { continue; }

                $taxonomies[] = wpmcp_discovery_taxonomy((string) $name, $tax);
            }

            return array('post_types' => $types, 'taxonomies' => $taxonomies);
        },
    ),

    );
}

wpmcp_register_module('discovery', 'wpmcp_discovery_tools');
