# How this was built

WP MCP was designed and built collaboratively with AI, end to end, and it is a fair example of what that workflow looks like when you treat the models as working peers rather than autocomplete.

- **Design by pressure-testing.** The auth model (no passwords, short-lived admin-minted tokens, hashed at rest) started as a rough idea and was argued into shape: every choice had to survive "what breaks this?" The address lock, for instance, survived two rounds of that and was still wrong: it was removed in 1.1.0 once a real hosted connector was measured calling from four egress addresses inside a minute. Pressure-testing an idea is not the same as measuring it.
- **Implementation, then independent review.** The plugin was written, then put through more than one independent code review by a separate model. That caught real issues a single author misses, a symlink that could escape the code-edit jail, post tools that would accept internal post types, an unbounded media download. All fixed before release.
- **A verification gate, learned the hard way.** Packaging is now gated: every file is syntax-checked, and the contents of the shipped zip are hash-compared against the source so a truncated or stale build can't go out. That gate exists because an early build shipped a half-written file and failed on activation. The fix was process, not just code.

The result is small (four PHP files), but the care behind it is the point: an AI assistant can drive a real WordPress site through it, and the security model is deliberate about exactly how far that reach goes.

Built by Max Konstantinovski, with Claude.
