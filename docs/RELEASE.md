# Cutting a release, and cutting a dev build

## The build stamp, in one paragraph

`build.txt` at the root of the plugin carries three placeholders. `.gitattributes` marks
that one file `export-subst`, so **`git archive` replaces them with the commit it is
archiving** and every zip cut that way says which commit it came from. A git checkout
keeps the placeholders literal, and the plugin then reports its build as `source` rather
than inventing a number. Nothing is bumped by hand and nothing fails when the stamp is
absent.

**The stamp sits beside the version, never inside it.** `Version:` in the plugin header,
`WPMCP_VER` and `serverInfo.version` are a clean semantic version on every build,
release or dev. The build is a separate field.

*Why, and what it costs.* Putting the commit in the version (`1.1.0-dev+<sha>`) is the
obvious shape and it breaks three things at once: WordPress shows the header verbatim
and `1.1.0-dev+$Format:%h$` is what a **checkout's** header would then read; the release
tooling and `tests/unit/VersionConsistencyTest.php` compare that line against
`CHANGELOG.md`, which cannot carry a commit hash; and `serverInfo.version` stops being a
version a client can compare. The cost of keeping them apart is real: **the Plugins
screen's own `Version` column still reads `1.1.0` for every build.** That is paid off by
`plugin_row_meta`, which appends `Build: <sha>` to the same row - so the Plugins screen
answers the question without the header lying about itself.

## Where the build shows, once installed

| Where | What it answers |
|---|---|
| Plugins screen, in the row meta beside the version | "Which zip did I just upload?" |
| Settings > WP MCP, the line under the heading | the same, plus the commit date |
| `initialize` -> `serverInfo.build` | what a connected client can report |
| `site-info` -> `wp_mcp.build` | what an agent can read without leaving the tools |

All four print the same string, because all four call `wpmcp_build_label()`.

**`source` is not a build id.** It means the site is running the plugin out of a git
checkout - a junction, a symlink, a `git clone` in `wp-content/plugins` - so there is no
zip and no commit to name. That is the normal case on a development machine and in CI,
and nothing about it is a fault. If a site you *uploaded a zip to* says `source`, the zip
was not built by the recipe below.

## A dev build

From the **closed commit**, never from the working tree - an unstamped or half-edited
tree is exactly what this whole mechanism exists to make impossible to confuse:

```bash
git archive --format=zip --prefix=wp-mcp/ \
  -o ../dist/wp-mcp-1.1.0-dev-<sha>.zip <sha> \
  wp-mcp.php endpoint.php tools.php trace.php admin.php uninstall.php build.txt \
  README.md ARCHITECTURE.md BUILD-NOTES.md CHANGELOG.md LICENSE SECURITY.md \
  'src/*.php' 'docs/*.md'
```

`build.txt` must be in that file list. Leave it out and the zip installs a plugin that
reports `source`, which looks exactly like a checkout and tells nobody anything.

After sending one, say in the message which file it is and that it replaces the earlier
one. After installing it, the check is: open the Plugins screen and read `Build:` in the
wp-mcp row, or ask the site `site-info` and read `wp_mcp.build`. It must **start with**
`<sha>` - `%h` abbreviates to whatever length that repository needs, so a build named
with a 7-character hash can report 8 once the history grows.

**Replace the plugin; do not copy files over it.** Deactivate and delete the old copy,
then install the new zip. Copying new PHP files into an existing `wp-mcp/` folder leaves
the previous `build.txt` in place, and the plugin then reports a build it is not running -
the stamp names the ZIP it came in, not whatever is in the folder now. Git and the two
recipes here cannot produce that; a hurried person can.

## A release

Before tagging, check that `wp-mcp.php`'s `Version:`, `WPMCP_VER` and the top `## x.y.z`
heading in `CHANGELOG.md` are the same string. A unit test enforces it, so
`composer test` is the check.

Then tag and push:

```bash
git tag v1.1.0 && git push origin v1.1 --tags
```

The tag push triggers `.github/workflows/release.yml`. It lints every PHP file, runs the
unit suite on PHP 8.1 through 8.4, runs the integration suite against a `wp-env`
container, and re-runs each closed sprint's gate group on its own, checking that every
test in it ran rather than skipped. Only then does the `release` job build `wp-mcp.zip`,
unzip it, compare every file against the source, lint the extracted copies, and publish a
GitHub Release with the zip attached.

That job stages the install set with `cp`, so it takes `build.txt` from
`git archive HEAD build.txt` instead - the one file in the zip that is deliberately *not*
byte-identical to the source, because the source is the placeholder and the zip is the
answer. The gate checks it separately: the staged copy must carry the tagged commit's
short hash and must not contain a leftover placeholder.

**A release build carries a stamp too, and it is not a "dev stamp".** There is no
`-dev` suffix anywhere in this design, so there is nothing for a release to strip: a
release reads as `Version 1.1.0` with `Build: <the tagged commit>`, and a dev zip reads
the same way with a different commit. What tells them apart is the commit, which is the
only thing that was ever reliable.

What blocks a release: any lint failure, any failing or skipped test in any job, a zipped
file that differs from its source, a `build.txt` that was not substituted, and a
`tools.php` that has lost one of its two guard markers: `wpmcp_php_parse_ok` in all three
of its places (the definition, `code-write`, `code-restore`) and
`wpmcp_code_version_current` in all four (the definition, `code-write`, `code-delete`,
`code-restore`) - the second being "every mutation versions first", which is what the
version table exists for. The `release` job has `needs: [lint, phpunit-unit,
integration]`, so a red suite makes publishing impossible rather than inadvisable.
Nothing here is run by hand, and the tag is the only trigger.
