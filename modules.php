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
 * wpmcp_raw_title(). A sixth is a change to this contract and that test says so.
 *
 * (2) Its own availability guard goes at the top of its own file, before the registration
 * call, so a module that cannot work does not register and its tools do not exist. That is
 * the bare-site rule, and it is the module's to apply because only the module knows what it
 * needs.
 *
 * (2a) AND SINCE 1.2.0 THAT GUARD IS A GATE RATHER THAN A CONVENTION (D30). Rule (1) has
 * always been enforced; rule (2) was a sentence, and nothing proved a guard was present or
 * complete - which is the same defect as a check that reports nothing when it sees nothing.
 * `function_exists('acf')` is the example this paragraph used to give and it is NOT
 * sufficient: it proves the other plugin is there, not that the FACE the module needs is
 * there. So a module that depends on another plugin DECLARES that face as data -
 *
 *     wpmcp_register_module_face('<slug>', '<callable returning the face>');
 *
 * - and registers only when wpmcp_module_face_missing('<slug>') is empty.
 * tests/unit/ModuleApiFaceTest.php tokenises every module file, collects every symbol it
 * ACTUALLY calls that is neither this plugin's nor WordPress's nor PHP's, and asserts that the
 * set CALLED BUT NOT DECLARED is `[]`. A guard that can fall behind the code it guards is
 * worth nothing, so the test is what keeps the declaration honest.
 *
 * (3) It may not re-declare a name the core, or an earlier module, already uses.
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
        'acf'       => 'modules/acf.php',
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
 * THE OTHER DOOR: a module declares the API FACE it needs from ANOTHER PLUGIN (D30).
 *
 * WHY A DECLARATION AND NOT JUST A GUARD. A guard is code, and code drifts from the thing it
 * guards without anybody noticing - `function_exists('acf')` stays green while the module
 * starts calling a function ACF added two releases later. A DECLARATION is data, so a test can
 * compare it against the symbols the module actually calls and fail when the two disagree
 * (tests/unit/ModuleApiFaceTest.php). That is the difference between rule (2) and rule (1) in
 * this file's contract, and closing it is all D30 asks for.
 *
 * THE FACE'S SHAPE, and both halves are needed for a DIFFERENT reason:
 *
 *     array(
 *         'required' => array(
 *             'functions' => array('acf_format_value_for_rest', ...),
 *             'classes'   => array(),
 *             'constants' => array(),
 *             'methods'   => array(
 *                 array('probe' => '<callable returning the object or null>',
 *                       'names' => array('get_disabled_layouts', ...)),
 *             ),
 *         ),
 *         'optional' => array(
 *             '<capability name>' => array( the same four keys ),
 *         ),
 *     )
 *
 * `required` is the registration gate: one missing symbol and the module does not register, so
 * its tools do not exist. `optional` is a FEATURE the module can do without, detected and
 * reported rather than demanded - because a face that demanded everything would refuse to
 * serve values on an ACF that merely cannot report disabled Flexible Content layouts, and the
 * honest answer there is "values, and this capability is off" rather than nothing at all
 * (D30's closing paragraph; item 7 of the ACF sprint).
 *
 * METHODS NEED A PROBE AND NOTHING ELSE DOES. `function_exists`, `class_exists` and `defined`
 * take a name; `method_exists` takes an OBJECT, and only the module knows how to reach it -
 * ACF's is `acf_get_field_type('flexible_content')`, which is itself a symbol that may be
 * absent. So the module supplies a callable that returns the object or null, and the checking
 * still happens HERE, once, for both call sites.
 *
 * FIRST DECLARATION OF A SLUG WINS, for the reason wpmcp_module_providers() gives.
 *
 * @param string   $slug the module's slug, the same one it registers under
 * @param callable $face returns the face array described above
 */
function wpmcp_register_module_face($slug, $face) {
    wpmcp_module_faces((string) $slug, $face);
}

/**
 * The declared faces, and the one place they are stored. Two arguments records; none reads.
 *
 * @param string|null   $slug
 * @param callable|null $face
 * @return array<string, callable>
 */
function wpmcp_module_faces($slug = null, $face = null) {
    static $faces = array();

    if ($slug !== null && !array_key_exists($slug, $faces)) {
        $faces[$slug] = $face;
    }

    return $faces;
}

/**
 * One module's declared face, normalised - so every reader below can assume the four keys
 * exist and hold lists, whatever the module wrote.
 *
 * A SLUG THAT DECLARED NOTHING GETS AN EMPTY FACE rather than a warning, because most modules
 * depend on nothing but WordPress and have nothing to declare. The test is what decides
 * whether an empty face is honest for a given module; this function only reports.
 *
 * @return array{required: array<string, array>, optional: array<string, array<string, array>>}
 */
function wpmcp_module_face($slug) {
    $faces = wpmcp_module_faces();
    $face  = array();

    if (isset($faces[(string) $slug]) && is_callable($faces[(string) $slug])) {
        $face = call_user_func($faces[(string) $slug]);
    }

    $face = is_array($face) ? $face : array();

    $normal = array('required' => wpmcp_module_face_part($face['required'] ?? array()), 'optional' => array());

    foreach ((array) ($face['optional'] ?? array()) as $capability => $part) {
        $normal['optional'][(string) $capability] = wpmcp_module_face_part($part);
    }

    return $normal;
}

/** One `required`/`optional` block with all four keys present and every one a list. */
function wpmcp_module_face_part($part) {
    $part = is_array($part) ? $part : array();

    return array(
        'functions' => array_values(array_map('strval', (array) ($part['functions'] ?? array()))),
        'classes'   => array_values(array_map('strval', (array) ($part['classes'] ?? array()))),
        'constants' => array_values(array_map('strval', (array) ($part['constants'] ?? array()))),
        'methods'   => array_values((array) ($part['methods'] ?? array())),
    );
}

/**
 * THE CHECK, AND IT IS ONE IMPLEMENTATION FOR BOTH CALL SITES (D30, point 3).
 *
 * Registration time is not enough on its own, and the reason is measured rather than
 * imagined: clients CACHE tool lists at connect time
 * (claude_code_memory/cold-client-reads-cached-tool-descriptions.md). A client that listed
 * tools while the other plugin was active can call one after it has been deactivated or
 * downgraded, and that call must produce a refusal, never a PHP fatal on somebody's site. So
 * the module asks this same function again inside its own `run` - the same names, the same
 * probes, the same answer - and a second copy of the rule cannot drift from the first because
 * there is no second copy.
 *
 * @param string $slug
 * @return list<string> the missing REQUIRED symbols, named as a human would look them up;
 *                      empty when the face is fully present
 */
function wpmcp_module_face_missing($slug) {
    return wpmcp_module_face_part_missing(wpmcp_module_face($slug)['required']);
}

/**
 * Which OPTIONAL capabilities of a module's face this site actually provides.
 *
 * Reported rather than demanded, and reported by NAME, because the answer belongs in the
 * tool's own output: "values, and layout metadata is unavailable on this ACF" is information,
 * where a silently missing half is the vacuous silence this project keeps catching.
 *
 * @return array<string, bool> capability name => every symbol it needs is present
 */
function wpmcp_module_face_capabilities($slug) {
    $capabilities = array();

    foreach (wpmcp_module_face($slug)['optional'] as $name => $part) {
        $capabilities[$name] = (wpmcp_module_face_part_missing($part) === array());
    }

    return $capabilities;
}

/**
 * The missing symbols of ONE face block.
 *
 * A METHOD WHOSE PROBE RETURNS NOTHING IS REPORTED AS THE METHOD, not as the probe, because
 * "get_disabled_layouts is missing" is what an operator can look up and "the probe returned
 * null" is not. `method_exists` accepts an object or a class name and answers false for
 * anything else, so a probe that returns null, false or a string nobody declared all give the
 * same honest answer without a branch per case.
 *
 * @param array{functions: list<string>, classes: list<string>, constants: list<string>, methods: list<array>} $part
 * @return list<string>
 */
function wpmcp_module_face_part_missing($part) {
    $missing = array();

    foreach ($part['functions'] as $name) {
        if (!function_exists($name)) { $missing[] = $name . '()'; }
    }

    foreach ($part['classes'] as $name) {
        if (!class_exists($name)) { $missing[] = 'class ' . $name; }
    }

    foreach ($part['constants'] as $name) {
        if (!defined($name)) { $missing[] = 'constant ' . $name; }
    }

    foreach ($part['methods'] as $group) {
        // A MALFORMED ENTRY IS REPORTED, NOT IGNORED, and that is the difference between a check
        // and a decoration. `'methods' => array('get_disabled_layouts')` - a flat list where the
        // group shape was meant - would otherwise check nothing and the module would register with
        // the methods it needs unverified: absence of a declaration is not a declaration of
        // safety, the same rule the tool registry applies to `write`.
        if (!is_array($group) || !isset($group['names']) || (array) $group['names'] === array()) {
            $missing[] = 'a malformed method declaration in this face';
            continue;
        }

        $probe  = $group['probe'] ?? null;
        $object = is_callable($probe) ? call_user_func($probe) : null;

        foreach ((array) $group['names'] as $name) {
            if (!is_object($object) || !method_exists($object, (string) $name)) {
                $missing[] = '->' . (string) $name . '()';
            }
        }
    }

    return $missing;
}

/**
 * EVERY MODULE THE MANIFEST NAMES, AND WHY IT IS OR IS NOT SERVING TOOLS (D30, point 4).
 *
 * THE ABSENCE HAS TO BE EXPLICABLE. "No ACF tools" and "the plugin is broken" must not look
 * identical to the administrator, and a module that refuses to register is by construction
 * silent about itself - that is what makes it safe and also what makes it unreadable. This is
 * the one place the two states can be told apart, and admin.php prints it on the settings
 * screen the operator already opens to see which surfaces are on.
 *
 * IT ASKS THE SEAM AND NEVER A MODULE. Faces and providers are registered callables held here,
 * exactly as wpmcp_module_providers() holds providers, so the core reads a registry rather than
 * naming a module's function - which is what keeps the plugin working when a module file is
 * deleted.
 *
 * @return array<string, array{file: string, present: bool, declares_face: bool, missing: list<string>, capabilities: array<string, bool>, registered: bool}>
 */
function wpmcp_module_status() {
    $providers = wpmcp_module_providers();
    $faces     = wpmcp_module_faces();
    $status    = array();

    foreach (wpmcp_module_manifest() as $slug => $relative) {
        $status[$slug] = array(
            'file'          => $relative,
            'present'       => is_file(plugin_dir_path(__FILE__) . $relative),
            'declares_face' => array_key_exists($slug, $faces),
            'missing'       => wpmcp_module_face_missing($slug),
            'capabilities'  => wpmcp_module_face_capabilities($slug),
            'registered'    => array_key_exists($slug, $providers),
        );
    }

    return $status;
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
