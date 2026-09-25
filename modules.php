<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * THE MODULE SEAM. Core is what is left when every module is gone; a module is a file that
 * adds tools and can be deleted without the core noticing.
 *
 * WHY IT EXISTS, and it is NOT tidiness (analysis/53-open-decisions.md, D23). tools.php was
 * 6,987 lines of a 12,266-line plugin - one file with a small core beside it - and the next
 * feature in the queue writes ACF values, which is the highest blast radius this plugin has
 * proposed. A module in its own file behind its own guard costs a site without ACF nothing
 * and can break nothing there, and a diff that touches one module file is a diff a reviewer
 * can read to the end.
 *
 * WHAT IT DOES NOT BUY, and the correction is recorded here because it is the part a future
 * instance will be tempted to drop: a locked core buys a CHEAPER review, not a LIGHTER one.
 * Five of six reviewed sprints in this project failed their first review with a green suite,
 * and none of those defects were in the dispatch path. Scrutiny follows blast radius, not
 * file boundaries. Nothing in this file makes new code safer. It makes new diffs smaller.
 *
 * ------------------------------------------------------------------------------
 * THE CONTRACT, both halves.
 *
 * A MODULE'S SIDE is one statement:
 *
 *     wpmcp_register_module('<slug>', '<callable that returns the tool array>');
 *
 * and three rules. (1) It may call WordPress and the helpers in tools.php, and nothing in
 * endpoint.php, admin.php or trace.php: a module does not reach into the request path, the
 * settings screen or the log. That is a GATE, not a convention - tests/unit/ModuleBoundaryTest.php
 * tokenises every module file and resolves each wpmcp_* call to the file that declares it - and
 * the tools.php helpers the modules call today are five: wpmcp_cannot(),
 * wpmcp_decode_specialchars(), wpmcp_listable_statuses(), wpmcp_post_type_ok() and
 * wpmcp_raw_title(). A sixth is a change to this contract and that test says so. (2) Its own availability guard - function_exists('acf') and the
 * like - goes at the top of its own file, before the registration call, so a module that
 * cannot work does not register and its tools do not exist. That is the bare-site rule, and
 * it is the module's to apply because only the module knows what it needs. (3) It may not
 * re-declare a name the core, or an earlier module, already uses.
 *
 * THE SEAM'S SIDE is the only half with a security property: NOTHING A MODULE RETURNS IS
 * TRUSTED. Registration records a callable and checks nothing. The gate is on the way OUT,
 * in wpmcp_module_tools(), and it is the SAME gate a third-party wpmcp_tools filter entry has
 * always faced - wpmcp_registry_reject_reason() in endpoint.php, one function, called from
 * both paths - plus the name check rule (3) needs. An entry that fails it is DROPPED: absent
 * from tools/list, uncallable, and named in a module_reject event with the reason, so the
 * module's author learns it from the log rather than from an incident.
 *
 * WHY THE GATE IS OUTWARD AND NOT A PROMISE, in one sentence, because ACF's own code is the
 * cautionary tale D23 cites: permission_callback is mandatory in the Abilities API only when
 * the registered class is WP_Ability itself, so a subclass skips it, and a seam that assumes
 * the module checked has no security property at all. In particular a module CANNOT publish a
 * write tool to a read-scope token by forgetting to say `write`, because `write` is not read
 * from the module's word for it - the absence of an explicit boolean is a rejection, exactly
 * as it is for a filter entry.
 *
 * WHAT THE SEAM DOES NOT CLAIM. wpmcp_register_module() is a public function and anything
 * loaded on this site can call it. That is deliberate and it costs nothing: the gate is
 * identical to the filter's, so the worst a third party gains by coming through this door
 * instead of the wpmcp_tools filter is the right to be checked the same way. It is also what
 * makes the gate TESTABLE from outside the plugin - tests/integration/ModuleSeamTest.php
 * registers a deliberately broken module and proves its tools do not exist. The trust
 * boundary is the gate, never the door.
 *
 * ------------------------------------------------------------------------------
 * THE MANIFEST IS CORE'S AND IT IS NOT FILTERABLE. wpmcp_module_manifest() is a literal list
 * of files this plugin ships. There is no hook that adds one, because a hook that loaded an
 * arbitrary PHP file would be a remote-code-execution surface dressed as extensibility;
 * third-party tools arrive through the wpmcp_tools filter, which adds arrays rather than
 * files.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * The modules this plugin ships, slug => path relative to the plugin directory.
 *
 * ADDING A MODULE IS TWO LINES: the file, and a line here. Removing one is the same two in
 * reverse, and tests/unit/BareCoreTest.php is what proves the removal leaves a working core.
 */
function wpmcp_module_manifest() {
    return array(
        'menus'     => 'modules/menus.php',
        'discovery' => 'modules/discovery.php',
    );
}

/**
 * Load every module file in the manifest. Called once, from wpmcp_bootstrap().
 *
 * A MISSING FILE IS SKIPPED, NOT FATAL, and the reason is which failure each choice makes
 * loud. A manifest entry with no file on disk is a PACKAGING bug - the release zip dropped a
 * file - and tests/unit/BareCoreTest.php fails on it in milliseconds, on every run, naming
 * the path. A require here would instead turn that bug into a white screen on somebody's
 * production site, which is the same information delivered at the worst possible moment.
 */
function wpmcp_module_load() {
    foreach (wpmcp_module_manifest() as $relative) {
        $path = plugin_dir_path(__FILE__) . $relative;
        if (is_file($path)) { require_once $path; }
    }
}

/**
 * THE DOOR. A module hands over a slug and a callable that returns its tool array.
 *
 * It records and it checks NOTHING, on purpose: every check this plugin makes about a tool
 * needs the core's own tool names to compare against, and those are only assembled when a
 * request asks for them. So the checking happens in wpmcp_module_tools(), on the way out.
 * Registration is a note to self, not a promise kept.
 *
 * @param string   $slug     a short name for the module, used in module_reject events
 * @param callable $provider returns array<string, array> - the tool shape tools.php uses
 */
function wpmcp_register_module($slug, $provider) {
    wpmcp_module_providers((string) $slug, $provider);
}

/**
 * The registered providers, and the one place they are stored. Two arguments records; none
 * reads.
 *
 * FIRST REGISTRATION OF A SLUG WINS. The manifest is loaded by wpmcp_bootstrap() at plugin
 * load time, and the earliest hook anything else can call this function from is
 * `plugins_loaded` - mu-plugins load before regular plugins, so at mu-plugin time this
 * function does not exist yet. Our own modules therefore always take their slugs first, and
 * a later caller cannot displace one by re-registering its name.
 *
 * @param string|null   $slug
 * @param callable|null $provider
 * @return array<string, callable>
 */
function wpmcp_module_providers($slug = null, $provider = null) {
    static $providers = array();

    if ($slug !== null && !array_key_exists($slug, $providers)) {
        $providers[$slug] = $provider;
    }

    return $providers;
}

/**
 * Every registered module's tools, CHECKED - the gate on the way out of registration.
 *
 * Four refusals a module can hit that a filter entry cannot, then the shared one:
 *
 *   provider_not_callable   the slug was registered with something that cannot be called.
 *   provider_not_an_array   it was called and did not return an array. The whole module is
 *                           dropped rather than half of it.
 *   name_not_a_string       a tool keyed by an integer, which is what array('foo' => ...)
 *                           becomes when a => is lost. It would reach the wire as a tool
 *                           named "0".
 *   name_reserved           the name is already the core's, or an earlier module's.
 *                           array_merge would silently let the later entry win, so a module
 *                           could replace delete-post with its own closure - a built-in's
 *                           `write` flag is the scope gate, and this is the one way a module
 *                           could move it.
 *
 * Then wpmcp_registry_reject_reason(), which is the filter path's whole check list, applied
 * to a module in the same order and reporting the same reasons.
 *
 * @param array<string, array> $reserved the names already taken - the core's own registry
 * @return array<string, array>
 */
function wpmcp_module_tools($reserved = array()) {
    $kept = array();

    foreach (wpmcp_module_providers() as $slug => $provider) {
        if (!is_callable($provider)) {
            wpmcp_module_reject($slug, '', 'provider_not_callable');
            continue;
        }

        $tools = call_user_func($provider);

        if (!is_array($tools)) {
            wpmcp_module_reject($slug, '', 'provider_not_an_array');
            continue;
        }

        foreach ($tools as $name => $tool) {
            $reason = '';

            if (!is_string($name) || $name === '') {
                $reason = 'name_not_a_string';
            } elseif (array_key_exists($name, $reserved) || array_key_exists($name, $kept)) {
                $reason = 'name_reserved';
            } else {
                $reason = wpmcp_registry_reject_reason($tool);
            }

            if ($reason !== '') {
                wpmcp_module_reject($slug, (string) $name, $reason);
                continue;
            }

            $kept[$name] = $tool;
        }
    }

    return $kept;
}

/**
 * One dropped module entry, in the log. The same shape as registry_reject plus the module
 * slug, and a DIFFERENT event name on purpose: "which module did this" is the first question
 * asked about one of these, and registry_reject already means "the wpmcp_tools filter did it".
 */
function wpmcp_module_reject($slug, $name, $reason) {
    wpmcp_auth_event('module_reject', array(
        'module' => (string) $slug,
        'tool'   => (string) $name,
        'reason' => (string) $reason,
    ));
}
