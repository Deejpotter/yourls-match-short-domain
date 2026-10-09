# YOURLS Match Short Domain

A [YOURLS](https://yourls.org) plugin for installs that answer on more than one short domain. It shows each short link on the short domain that matches where the link points, and can optionally let the same keyword go to a different page on each short domain.

## Why

Email providers check that links in an email use the same domain as the site and the sender. If one YOURLS install serves `go.example.com` and `go.example.com.au`, YOURLS still shows every link on its main address (`YOURLS_SITE`), so links for the other site have to be edited by hand before use. This plugin shows each link on the right domain automatically.

## Requirements

- YOURLS 1.10 or later (tested on 1.10.1), PHP 7.4 or later.
- Every short domain must already serve the same YOURLS install (same document root, DNS pointed at the server). The plugin doesn't set up domains.

## Install

1. Copy `plugin.php` to `user/plugins/yourls-match-short-domain/plugin.php`.
2. In the YOURLS admin, open **Manage Plugins** and activate **Match Short Domain to Destination**.
3. Open **Match Short Domain Settings** and add your rules.

## Settings

Each rule has three fields:

| Field | Example | Meaning |
|---|---|---|
| Destination site | `example.com` | Links pointing here (or to `www.example.com` and other subdomains) use this rule |
| Short domain | `go.example.com` | The short domain those links are shown on |
| Keyword prefix | `us-` (optional) | See below. Leave blank to only change the displayed domain |

Enter domains only: no `https://` and no `/` (they're removed if pasted). The page always shows one empty row for the next rule; a new one appears after each save. Rows that can't be saved are shown with the reason and kept in the form for fixing. To remove a rule, clear all its boxes and save.

Links that match no rule keep the main `YOURLS_SITE` address. The first matching rule wins.

### Keyword prefix (same keyword on each domain)

YOURLS only allows each keyword once. With a prefix of `us-` on the `go.example.com` rule:

- A link to example.com created with the custom keyword `abc` is saved as `us-abc` automatically (typing `us-abc` yourself also works). Random keywords are left alone.
- It's shown as `https://go.example.com/abc`.
- Opening `https://go.example.com/abc` goes to `us-abc` if it exists. If it doesn't, YOURLS opens `abc` as normal.
- `https://go.example.com/us-abc` still works directly.
- Other domains are unaffected: `https://go.example.com.au/abc` opens `abc`.

Watch for: if the prefixed link was never created, `go.example.com/abc` silently opens the unprefixed `abc`.

## How it works

- `yourls_link` filter: changes the domain (and strips the prefix) wherever YOURLS displays or returns a short link: the new link box, the admin table, the API.
- `custom_keyword` filter: adds the prefix to custom keywords for links matching a prefix rule.
- `get_request` filter: on a short domain with a prefix rule, swaps `abc` for `us-abc` before YOURLS looks the keyword up. Only YOURLS's link loader uses this filter, so admin pages are unaffected.
- Every hook is wrapped in a `try`/`catch`, so an error falls back to normal YOURLS behaviour instead of breaking redirects.

## Notes

- The YOURLS admin only works on the `YOURLS_SITE` address. Short links work on every domain.
- After a YOURLS update, test one link per rule.

## Licence

MIT. See [LICENSE](LICENSE).
