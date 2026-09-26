<?php
/**
 * ONE TOKENISER, FOR EVERY TEST THAT ASKS "WHAT DOES THIS FILE REACH FOR?"
 *
 * Two gates need the same answer about the same source. `ModuleBoundaryTest` asks which of
 * THIS PLUGIN's symbols a module reaches; `ModuleApiFaceTest` asks which of ANOTHER PLUGIN's
 * it reaches. Both decisions are the same decision - what POSITION is this name in - and
 * before 1.2.0 only the first existed, in that test's own private methods. A second copy would
 * have been two token scanners to keep in step, and the one thing the module-boundary sprint
 * measured about token scanning is that its holes are in the positions nobody enumerated
 * (`analysis/69`, round 4: a leading backslash, a lower-cased class name). Two scanners means
 * two sets of those.
 *
 * SO POSITION IS DECIDED EXACTLY ONCE, HERE, and the two gates differ only in which names they
 * care about and what they then require.
 *
 * WHY POSITION AND NOT THE NAME'S SHAPE. This plugin's flat class convention is
 * `WpMcp_Something` and its function convention is `wpmcp_something`, and PHP resolves both
 * case-insensitively - so those are THE SAME STRING and no shape test can tell a class from a
 * function. Measured on PHP 8.2.29 with token_get_all(), the three token shapes a name arrives
 * in are:
 *
 *     wpmcp_cannot            T_STRING
 *     WpMcp\SchemaValidator   T_NAME_QUALIFIED
 *     \wpmcp_cannot           T_NAME_FULLY_QUALIFIED   <- one token, no T_STRING anywhere
 *
 * THE KINDS, AND THE LAST TWO ARE THE POINT OF THIS CLASS EXISTING:
 *
 *     call                 a name followed by `(`
 *     method               a name after `->` or `?->` and followed by `(`
 *     static_call          a name after `::` and followed by `(`
 *     class                a name after new/instanceof/extends/implements/use, or before `::`
 *     type                 a name in a type-hint, return-type or catch position
 *     constant             a name in value position that is not a call
 *     function_declaration / class_declaration / const_declaration
 *
 * `type` and `constant` are the two positions the module-boundary detector had NO bucket for
 * at all, so a name in either fell between it and silence - a `WPMCP_*` constant read from a
 * module, or a flat `WpMcp_*` class in a type-hint, a return type or a `catch`
 * (`analysis/BACKLOG.md`, "The module-boundary detector has one position that falls between it
 * and silence"). Naming them is what lets a gate assert that the set of names NEITHER detector
 * claimed is empty, instead of reporting nothing when it sees nothing.
 *
 * A NAME IN A COMMENT OR A STRING IS NOT A REFERENCE, which is the whole reason this is the
 * tokeniser and not a grep: a module docblock may legitimately write `wpmcp_trace()` in prose,
 * and a regex over the raw text would have to be weakened the first time somebody did.
 *
 * WHAT IT CANNOT SEE, said here because a mechanism that does not state its limit invites the
 * trust it has not earned.
 *
 * 1. An INDIRECT reference. It resolves names that are WRITTEN, so `$fn('x')`,
 *    `call_user_func('wpmcp_trace', ...)`, `add_action('x', 'wpmcp_trace')` and
 *    `['WpMcp_Thing', 'make']()` each reach a symbol that is not a token here. See
 *    ModuleBoundaryTest's header for the one hand check that covers them.
 * 2. A CLASS CONSTANT's own name. `Foo::BAR` reports `Foo` as a class - which is the dependency
 *    that matters and is checked - and DROPS `BAR` as a member, so a face cannot be made to
 *    verify it and a missing one fatals with the class present. No module has one. If ever
 *    needed, `defined('Foo::BAR')` is true for a class constant in PHP, so a face's `constants`
 *    list can hold the qualified name without any new mechanism.
 * 3. A METHOD OF AN ENUM. `methodDeclarations()` counts brace depth from `class`, `trait` and
 *    `interface`, not `enum`, so `WpMcp\ProtocolVersion::latest()` is not in the excuse set. That
 *    errs LOUD - a module calling `->latest()` on a foreign object is reported - so it is a limit
 *    and not a hole.
 * 4. It does NOT skip attributes. A name inside `#[Attr(...)]` lands in the `constant` bucket,
 *    which is reported rather than excused, so an attribute on a module's function errs loud too.
 *    (An earlier draft of significant() claimed to skip them and did not; the claim is gone
 *    rather than the behaviour, because the behaviour is the safe one.)
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class PhpSymbols
{
    /**
     * Names PHP itself owns in the positions below, so no gate has to reason about them.
     *
     * `true`, `false` and `null` arrive as T_STRING in VALUE position, which is exactly where a
     * constant read arrives, and `defined('true')` is false - so without this list every
     * `return true;` would be reported as an undeclared constant. The rest are type names, in
     * the position a class type-hint arrives in.
     */
    public const RESERVED = [
        'true', 'false', 'null', 'self', 'static', 'parent',
        'int', 'float', 'string', 'bool', 'boolean', 'array', 'object', 'mixed',
        'void', 'never', 'callable', 'iterable', 'resource', 'double', 'integer',
    ];

    /** The token ids that put the FOLLOWING name in class position. */
    private const CLASS_BEFORE = [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_USE];

    /** The token ids that make the FOLLOWING name a declaration of its own. */
    private const DECLARES = [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_CONST];

    /**
     * Every name token in $source with the position it appears in.
     *
     * RESERVED names are dropped, and so is a name after `::` or `->` that is NOT a call - a
     * class constant (`Foo::BAR`) or a property (`$foo->bar`) is reached THROUGH a class, so
     * the class is the reference that matters and the member name is not a symbol of its own.
     *
     * @return list<array{name: string, lower: string, kind: string, line: int}>
     */
    public static function scan(string $source): array
    {
        $tokens = token_get_all($source);
        $count  = count($tokens);
        $found  = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            if (in_array(strtolower($name), self::RESERVED, true)) {
                continue;
            }

            $before = self::significant($tokens, $i, -1);
            $after  = self::significant($tokens, $i, +1, $count);
            $kind   = self::kind($name, $before, $after);

            if ($kind === '') {
                continue;
            }

            $found[] = [
                'name'  => $name,
                'lower' => strtolower($name),
                'kind'  => $kind,
                'line'  => (int) $token[2],
            ];
        }

        return $found;
    }

    /**
     * The position one name is in, from the significant token on each side of it.
     *
     * ORDER MATTERS AND IS NOT ARBITRARY. A declaration is decided first, because
     * `function wpmcp_x(` is followed by `(` and would otherwise read as a call to itself. A
     * member access is decided next, because `$acf->get_disabled_layouts(` is also followed by
     * `(`. Only then does `(` mean a call.
     *
     * A NAMESPACED NAME IS ALWAYS A CLASS HERE unless it is called: this plugin declares no
     * function inside a namespace, and a namespaced call would be written with its leading
     * backslash or imported.
     *
     * @param array{0:int,1:string,2:int}|string|null $before
     * @param array{0:int,1:string,2:int}|string|null $after
     */
    private static function kind(string $name, $before, $after): string
    {
        $beforeId = is_array($before) ? $before[0] : null;
        $isCall   = ($after === '(');

        if (in_array($beforeId, self::DECLARES, true)) {
            if ($beforeId === T_FUNCTION) { return 'function_declaration'; }
            if ($beforeId === T_CONST)    { return 'const_declaration'; }

            return 'class_declaration';
        }

        if ($beforeId === T_OBJECT_OPERATOR
            || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $beforeId === T_NULLSAFE_OBJECT_OPERATOR)) {
            return $isCall ? 'method' : '';
        }

        if ($beforeId === T_DOUBLE_COLON) {
            return $isCall ? 'static_call' : '';
        }

        if (in_array($beforeId, self::CLASS_BEFORE, true)) {
            return 'class';
        }

        if (is_array($after) && $after[0] === T_DOUBLE_COLON) {
            return 'class';
        }

        if ($isCall) {
            return 'call';
        }

        // A NAMED ARGUMENT IS NOT A CONSTANT, and it would otherwise be reported as one - a
        // module writing `str_contains(haystack: $a, needle: $b)` would be told it reads two
        // undeclared constants. `name:` after a `(` or a `,` is the only place that shape occurs,
        // and a ternary's `:` is covered separately below because its colon comes BEFORE the name.
        if (($before === '(' || $before === ',') && $after === ':') {
            return '';
        }

        // NEITHER A CALL NOR A CLASS, which is the position that used to fall through
        // silently. A TYPE is recognisable from what follows it - a variable
        // (`function f(WpMcp_Thing $x)`, `catch (WpMcp_Thing $e)`), a union or intersection
        // bar, a spread, or the `{` that opens a body after a return type - and from a `?`
        // or `|` in front of it. Everything else in value position is a constant read.
        if (self::looksLikeType($before, $after)) {
            return 'type';
        }

        return strpos($name, '\\') !== false ? 'class' : 'constant';
    }

    /**
     * @param array{0:int,1:string,2:int}|string|null $before
     * @param array{0:int,1:string,2:int}|string|null $after
     */
    private static function looksLikeType($before, $after): bool
    {
        // `?Foo`, `Foo|Bar`, `Foo&Bar` and `: Foo` are all type positions. A TERNARY's else branch
        // is also preceded by `:` - `$x = $c ? 1 : WPMCP_PAGE_CAP;` - so a constant read there is
        // reported as a type rather than as a constant. That is a MISATTRIBUTION and it is left
        // deliberately: it errs LOUD, because the rider assertion reports any name in type
        // position that no detector claims, so the author is told rather than passed.
        if ($before === '?' || $before === '|' || $before === '&' || $before === ':') {
            return true;
        }

        if (is_array($after) && in_array($after[0], [T_VARIABLE, T_ELLIPSIS], true)) {
            return true;
        }

        return $after === '|' || $after === '&' || $after === '{';
    }

    /**
     * The nearest token in $direction that is not whitespace or a comment.
     *
     * NOT attributes: `#[` arrives as T_ATTRIBUTE and is left in place, so a name inside one is
     * classified by its own position. See limit 4 in the header.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function significant(array $tokens, int $from, int $direction, int $count = 0)
    {
        $count = $count > 0 ? $count : count($tokens);

        for ($i = $from + $direction; $i >= 0 && $i < $count; $i += $direction) {
            if (is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * The names of one kind in $source, lower-cased key => name as written.
     *
     * The name AS WRITTEN is kept beside the key so a failure can quote what the author really
     * typed rather than a normalised form they will not recognise.
     *
     * @return array<string, string>
     */
    public static function of(string $source, string $kind): array
    {
        $found = [];

        foreach (self::scan($source) as $symbol) {
            if ($symbol['kind'] === $kind) {
                $found[$symbol['lower']] = $symbol['name'];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Every method DECLARED inside a class, trait or interface in $source, lower-cased.
     *
     * BRACE DEPTH AND NOT A MODIFIER. `function x()` inside a class body is a method whether or
     * not it carries `public`, so "has a visibility modifier" is not the rule; "is inside a
     * class body" is. The depth is counted from the `{` that opens the body, which also skips
     * the parameter list's own parentheses and any closure declared inside a method - a closure
     * has no name, so it contributes nothing either way.
     *
     * @return list<string>
     */
    public static function methodDeclarations(string $source): array
    {
        $tokens = token_get_all($source);
        $count  = count($tokens);
        $found  = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)) {
                continue;
            }

            // Walk to the `{` that opens the body, then to its match.
            for (; $i < $count && $tokens[$i] !== '{'; $i++) {
                // An anonymous class used as a value still opens a body, so nothing special.
            }

            $depth = 0;

            for (; $i < $count; $i++) {
                if ($tokens[$i] === '{') { $depth++; }
                if ($tokens[$i] === '}') {
                    $depth--;
                    if ($depth === 0) { break; }
                }

                if (is_array($tokens[$i]) && $tokens[$i][0] === T_CURLY_OPEN) { $depth++; }
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }

                if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                    $next = self::significant($tokens, $i, +1, $count);

                    if (is_array($next) && $next[0] === T_STRING) {
                        $found[strtolower($next[1])] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Every `define('NAME', ...)` in $source, lower-cased.
     *
     * A CONSTANT DEFINED BY `define()` IS NOT A TOKEN, because its name is a STRING literal -
     * so `const_declaration` above finds `const X = 1` and finds nothing at all in this
     * plugin, which defines all forty-odd of its constants the other way. A gate that resolves
     * a module's constant READ to the file that defines it therefore has to read the string.
     *
     * @return list<string>
     */
    public static function defineNames(string $source): array
    {
        $found = [];

        if (preg_match_all('/\bdefine\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1/', $source, $matches) > 0) {
            foreach ($matches[2] as $name) {
                $found[strtolower($name)] = true;
            }
        }

        return array_keys($found);
    }
}
