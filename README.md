# CVG AI Chatbot: the snippet build

A floating AI chat widget for WordPress, as four files you paste in. No plugin to install
beyond [WPCode](https://wordpress.org/plugins/insert-headers-and-footers/), and no CDN
requests.

Works with OpenAI, Anthropic, or any OpenAI-compatible endpoint, which means you can run it
free on your own machine with [Ollama](https://ollama.com/) before you pay anyone.

Created by Dimu Harshana, <https://ceeveeglobal.com/>
Full guide, including the nine ways this breaks and what each one looks like:
<https://ceeveeglobal.com/add-ai-chatbot-to-wordpress/>

## What makes this different from the usual tutorial

Most WordPress chatbot tutorials ship an endpoint anyone on the internet can call, which
means anyone can spend your API credit. This build was taken apart in a Docker lab and then
rebuilt. The measured differences:

- Both REST routes declare a `permission_callback`. A missing one is not untidy, it is open.
- The chat route is public by design (visitors are not logged in), so it is paired with a
  nonce **and** a server-side rate limit.
- The conversation lives on the server. The browser never sends history back, so nobody can
  put words in the model's mouth.
- `max_tokens` is always capped, so one visitor cannot run up a bill.
- The provider's error text goes to the log, never to the visitor.
- No CDN. Every icon is inline SVG, so it cannot collide with a theme that already loads
  FontAwesome.

## Install

Four files, in this order. Each one goes in as its own WPCode snippet.

| # | File | WPCode snippet type | Location |
|---|---|---|---|
| 1 | `01-paste-into-wpcode.php` | PHP Snippet | **Run Everywhere** |
| 2 | `02-html-block.html` | HTML Snippet | Site Wide Footer |
| 3 | `03-styles.css` | CSS Snippet | Site Wide Header |
| 4 | `04-script.js` | JavaScript Snippet | Site Wide Footer |

Then add your key to `wp-config.php`, above the line that says
`/* That's all, stop editing! */`:

```php
define( 'CVG_CHATBOT_API_KEY', 'sk-your-real-key' );
```

Reload any page on the front end. The launcher appears in the bottom corner.

### Two things that fail silently

Both of these leave nothing in `debug.log` and show no error on screen. The chat box simply
never answers.

1. **Do not add `<?php` at the top of file 1.** It deliberately starts with a comment.
2. **File 1's location must be *Run Everywhere*.** Anything front-end-only runs on
   WordPress's `wp` hook, which a REST request never reaches, so the routes never register
   and every chat request 404s.

## Running it free

Set `provider` to `compatible` and point `base_url` at a local model:

```php
'provider' => 'compatible',
'base_url' => 'http://localhost:11434/v1',
'model'    => 'qwen2.5:3b-instruct',
```

No key needed. A small local model answers in about 1.7 seconds on CPU once it is warm, and
roughly 6.9 seconds on the first request while it loads. It follows instructions less
reliably than a paid model, so test your plumbing locally and judge answer quality on a real
key.

## Customising

Everything you can change lives in the `CONFIG` block at the top of
`01-paste-into-wpcode.php`: the greeting, the title, the quick-reply buttons, the accent
colours, the model, the rate limit, how many turns of history it keeps, and the longest
message a visitor may send. Nothing below that block needs editing.

The bot knows only what is in `system_prompt`. It does not read your pages, posts or
products, so site-specific answers mean putting your own facts in that string.

## Prefer not to assemble it?

The same build is packaged as a normal WordPress plugin with a settings screen instead of a
CONFIG block, plus install and customising docs. It is on
[my Patreon](https://www.patreon.com/cw/ceeveeglobal).

Questions, or stuck on a step: [my Discord](https://discord.gg/W7vvnVSX2G).

## License

GPL-2.0-or-later. See `LICENSE`.
