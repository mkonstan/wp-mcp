# How WP MCP works

This is the plain-language tour: what the plugin is, what happens when a request comes in, and why it was built the way it was. If you want to change or extend it, start here.

## The one-sentence idea

You want an AI assistant to help run your WordPress site, but you do not want to hand it your password. So instead you hand it a **temporary pass**: easy to create, expires on its own, and only works from one machine. The plugin is the doorman that checks that pass on every knock.

Think of a hotel keycard. It opens your room for a few days, not forever. It only opens your door, not the whole building. And the front desk never keeps a copy of your actual key, just a coded record that can verify it. That is the whole security idea in one picture.

## The four files

The plugin is small and old-fashioned on purpose: plain functions, no classes, no framework. Four files, one job each.

| File | Its job |
|------|---------|
| `wp-mcp.php` | Turns the plugin on and runs the "pass" system: create a pass, check a pass, revoke one, clean out expired ones. |
| `endpoint.php` | The front door. Takes the incoming request, checks the pass, and routes it to the right handler. Defines no tools itself. |
| `tools.php` | The list of things the assistant can actually do (the "tools"), plus a few shared helpers. |
| `admin.php` | The Settings > WP MCP screen where you create and revoke passes. |

## What a "tool" is

A tool is one thing the assistant can do, like "list recent posts" or "create a draft." In the code it is just a small bundle: a name, a one-line description, what inputs it takes, whether it changes anything, and the actual code to run.

Here is one, start to finish:

```php
'list-posts' => array(
    'write'       => false,                       // false = safe, read-only
    'description' => 'List recent posts. Args: post_type, status, limit.',
    'inputSchema' => array( /* what arguments are allowed */ ),
    'run'         => function ($args) {           // the code that does the work
        // ... fetch posts, return them ...
    },
),
```

That is all a tool is: a label plus a little function. The assistant reads the label and description to know what is on offer, and calls the `run` part when it wants the thing done.

## What happens on a request (the important part)

This is the piece most people get wrong when they first read the code, so it is worth being clear.

The tool list is **not** built when the plugin starts up. It is built fresh every single time a request comes in. Like a restaurant that prints a new menu each time you ask, rather than keeping one laminated copy that slowly goes out of date.

Here is the whole trip, from knock to answer:

```
WHEN WORDPRESS STARTS UP (once)
  wp-mcp.php runs wpmcp_bootstrap():
     loads the other three files, so all the functions now exist
     sets up the web address (route) the assistant will call
  No tool has run yet. Nothing has happened. The door is just open for business.

WHEN THE ASSISTANT MAKES A REQUEST
  It POSTs to  /wp-json/wpmcp/mcp   asking either
     "what can you do?"   (tools/list)   or
     "do this one thing"  (tools/call)

  1. Check the pass (wpmcp_authorize)
       - find the token (in a header, or in the web address)
       - is it real? not expired? coming from the allowed machine?
       - if not, the door says 401/403 and nothing else happens

  2. Build today's menu (wpmcp_tools), fresh, right now:
       core tools     (site-info, list-posts, get-post)
       + content      (create / update / delete post)
       + taxonomy     (categories and tags)
       + media        (images and files)
       + comments
       + code editing (only if you turned it on)

  3. Answer:
       "what can you do?"  -> hand back the menu (only the parts this pass is allowed to see)
       "do this one thing" -> find that tool, run its code, return the result
```

That is the entire lifecycle. Read a pass, build the menu, either show it or act on it.

### Why rebuild the menu every time?

The obvious alternative is to build the list once at startup and keep it in memory (a "registry"). We chose not to. Building a few small arrays per request costs almost nothing, and in exchange we get no stored state that can drift, go stale, or get corrupted. There is no "the registry says X but the code does Y" bug class, because there is no registry. For a plugin this size, simple and stateless wins.

This choice also explains the naming. The functions that hand back the lists, `wpmcp_content_tools()`, `wpmcp_media_tools()`, and so on, are named like nouns ("the content tools") because that is what they do: you ask, they return the list. They do not "register" or "initialize" anything. If they did register tools into a stored list at startup, the names would say `register` or `init`. They do not do that, so they do not say that. The name tells you the truth about the behavior.

## Adding your own tool

Because the menu is assembled in one place, you can add to it from your own plugin or theme with a single hook. No need to edit this plugin:

```php
add_filter('wpmcp_tools', function ($tools) {
    $tools['say-hello'] = array(
        'write'       => false,   // leave off or false = anyone with a pass can use it
        'description' => 'Returns a greeting.',
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'run'         => function ($args) { return array('message' => 'hello'); },
    );
    return $tools;
});
```

Your tool goes through the same permission check as the built-in ones. Mark it `'write' => true` and it becomes admin-only.

## Who is allowed to do what

A pass is one of two kinds: **read** or **admin**.

- A **read** pass can only see and use the safe, look-but-do-not-touch tools. The tools that change things are not even shown to it, and if it tries to call one anyway, it is refused.
- An **admin** pass can do everything, and, only if you switch it on, can also edit theme files.

Why two levels: most of the time you want the assistant to read and draft, not to publish or delete on its own. Read is the safe default you reach for; admin is a deliberate choice you make when you actually want changes. The permission check lives in the front door (`endpoint.php`), not in the tools themselves, so the tools stay simple and there is one place to reason about access.

## The pass system, and why each piece exists

The token (the "pass") is the heart of the plugin. Every part of its design answers a specific worry.

**It is random and long (256 bits).**
Why: so nobody can guess it. Guessing is simply off the table.

**Only a fingerprint of it is stored, never the token itself.**
Why: if someone ever steals a copy of your database, they get a pile of fingerprints, not working passes. The real token was shown to you once, at creation, and never written down anywhere it could leak.

**It expires on its own, twelve hours at most.**
Why: a key that works forever is a problem waiting to happen. A key that is dead by tomorrow morning is a much smaller problem. You pick the lifetime; the plugin will not let you exceed twelve hours.

**It locks to one machine, but only after the first real action.**
This is the subtle one, and it came from a real bug. An AI client, when you first connect it, does a little handshake to ask "what can you do?" That handshake can come from a different place on the internet than the machine that later does the actual work. Our first version locked the pass to whoever asked first, which was the handshake, and then the real client got locked out of its own pass.

So we changed it: the handshake does not lock anything. The lock is set by the first request that actually *does* something. From that point on, every request, handshake included, has to come from that same machine or it is refused. The lock waits for the moment that proves who the real user is.

**The whole door is closed when there are no passes.**
Why: turning the plugin on does not expose anything by itself. With no live pass in the system, every request gets turned away. Delete all the passes and the door is shut again.

## Editing theme code, carefully

There is an optional, off-by-default mode where an admin pass can read and write your theme's files. Letting an assistant edit live code is powerful and genuinely risky, so it is fenced in:

- **It can only touch the active theme.** Nothing else on the server is reachable. Attempts to climb out of that folder (with `..` or symlinks) are refused.
- **A denylist protects the dangerous files.** By default `functions.php` and a few others are off limits, because those load on every page and a bad edit there takes the whole site down. Template files are fine; the site-killers are not.
- **Every write is backed up first, and checked.** Before saving, the old version is copied aside. If the new code has a syntax error, the change is thrown away and the good version is put back automatically. A clumsy edit cannot break the site, because a broken edit never sticks.

Why all this: the failure we were most afraid of is "the assistant edited a file and now the site is a white screen." The backup-and-revert step makes that specific disaster nearly impossible.

## What we deliberately left out

Some things were considered and intentionally not built: installing plugins from a URL, deleting users, changing site-wide settings, and any "run whatever command you want" tool. These are the high-damage actions, and a tool list that includes them is not much safer than just handing over a shell. Leaving them out is a feature, not a gap. (More on this in [SECURITY.md](SECURITY.md).)

## Why it looks so plain

No classes, no framework, four files of straightforward functions. That is on purpose. The whole plugin is small enough to read in one sitting, which for something that guards your site is worth more than cleverness. If you can read it, you can trust it.
