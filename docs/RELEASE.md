# Cutting a release

Before tagging, check that `wp-mcp.php`'s `Version:`, `WPMCP_VER` and the top
`## x.y.z` heading in `CHANGELOG.md` are the same string. A unit test enforces it, so
`composer test` is the check.

Then tag and push:

```bash
git tag v1.0.0 && git push origin v1 --tags
```

The tag push triggers `.github/workflows/release.yml`. It lints every PHP file, runs the
unit suite on PHP 8.1 through 8.4, runs the integration suite against a `wp-env`
container, and checks that every test in it ran rather than skipped. Only then does the
`release` job build `wp-mcp.zip`, unzip it, compare every file against the source, lint
the extracted copies, and publish a GitHub Release with the zip attached.

What blocks it: any lint failure, any failing or skipped test in any job, a zipped file
that differs from its source, and a `tools.php` whose `wpmcp_php_parse_ok` marker is not
in all three of its places - the definition, `code-write` and `code-restore`. The
`release` job has `needs: [lint, phpunit-unit, integration]`, so a red suite makes
publishing impossible rather than inadvisable. Nothing here is run by hand, and the tag is
the only trigger.
