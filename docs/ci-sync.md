---
layout: default
title: CI Sync
nav_order: 14
---

# CI Sync

Traduttore normally clones the repository and runs `wp i18n make-pot` itself. That does not work for SureCart: the repository is private and 3.5 GB, and the Stencil `dist/` output is gitignored, so a POT built from a clone misses about 2,900 strings.

Instead, GitHub Actions builds `surecart.pot` on every merge to `main` and pushes it here over SSH. A second job exports each locale, fills in the untranslated strings and imports them back. Traduttore keeps building the language packs and serving the translations API.

## WP-CLI commands

All three live under `wp traduttore ci`. `<project>` is a project path or ID.

### `wp traduttore ci originals <project> <file> [--force]`

Imports a POT through `Updater::import_pot_file()`, the same code path the webhook update uses, so the text domain and version are read from the headers, `traduttore.updated` fires and language pack builds get scheduled as before. The update lock is honoured.

The POT may omit `X-Domain` (ours is built with `--ignore-domain`); the stored text domain is kept in that case.

```bash
wp traduttore ci originals surecart /tmp/surecart.pot
# Success: Imported originals for project (ID: 1): 12 added, 3 changed, 0 fuzzied, 1 obsoleted, 0 errors.
```

### `wp traduttore ci sets <project>`

Prints one JSON array with every translation set of the project.

```bash
wp traduttore ci sets surecart
# [{"locale":"de","wp_locale":"de_DE","set":"german","current":4100,"untranslated":120,"waiting":3}, ...]
```

`locale` is the GlotPress slug (`de`, not `de_DE`). Set slugs on translate.surecart.com are language names (`german`, `arabic`); only Albanian uses `default`. Pass both exactly as listed to `import` and `export`.

### `wp traduttore ci import <project> <locale> <set> <file> [--status=<current|waiting>]`

A guarded import. Every entry whose original already has a `current` or `waiting` translation in the set is dropped before GlotPress sees it, because GlotPress' own import would replace a differing approved translation and bypass a waiting community suggestion. Only untranslated and fuzzy-only originals get filled. `gp_translation_set_import_over_existing` is forced to `false` for the call as well.

Entries GlotPress flags with warnings are held as `waiting`, as usual. Pass `--user` so the translations are attributed; the SSH wrapper does this for you.

```bash
wp traduttore ci import surecart de german /tmp/de.po --user=ai-bot
# Success: Imported 118 translations into de/german: 3980 skipped (already translated), 2 unknown originals, 4 held as waiting.
```

## SSH wrapper

`bin/ci-ssh-wrapper.sh` is the forced command for the CI key. It reads `$SSH_ORIGINAL_COMMAND` and runs one of five fixed WP-CLI calls; anything else exits with an error.

### authorized_keys

```
command="TRADUTTORE_CI_WP_PATH=/var/www/translate.surecart.com/public /var/www/translate.surecart.com/public/wp-content/plugins/traduttore/bin/ci-ssh-wrapper.sh",restrict ssh-ed25519 AAAA... surecart-ci
```

`restrict` disables port forwarding, agent forwarding, X11 and PTY allocation. Environment variables go in the command string as shown, since `restrict` does not forward the client environment. `wp` must be on `PATH` for the SSH user.

### Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `TRADUTTORE_CI_WP_PATH` | (required) | WordPress root, passed as `--path`. |
| `TRADUTTORE_CI_PROJECT` | `surecart` | Project path. Must be a path, not an ID, because `export` goes through `wp glotpress`. |
| `TRADUTTORE_CI_USER` | `ai-bot` | Passed as `--user` to every call. Imported translations are attributed to this user. |

The wrapper sets `WP_CLI_PHP_ARGS='-d memory_limit=1024M'`; importing a 5k string POT or PO needs it. If `wp` is the bare phar rather than the launcher script this variable is ignored, so check once with `ssh ... sets` and `wp eval 'echo ini_get( "memory_limit" );'`.

### Commands

Commands that take a file read it from stdin into a temporary file that is removed on exit. `locale` and `set` must match `^[a-z0-9_-]+$`.

```bash
ssh ci@translate.surecart.com originals < surecart.pot
ssh ci@translate.surecart.com sets
ssh ci@translate.surecart.com export de german > de.po
ssh ci@translate.surecart.com import de german < de.po
ssh ci@translate.surecart.com import de german waiting < de.po
ssh ci@translate.surecart.com packs
```

| Command | Runs |
| --- | --- |
| `originals` | `wp traduttore ci originals <project> <tmp>` |
| `sets` | `wp traduttore ci sets <project>` |
| `export <locale> <set>` | `wp glotpress translation-set export <project> <locale> --set=<set> --status=current_or_untranslated` |
| `import <locale> <set> [current\|waiting]` | `wp traduttore ci import <project> <locale> <set> <tmp> --status=<status>` |
| `packs` | `wp traduttore language-pack build <project>` |

## Safety rails

* `originals` refuses a POT with more than 10% fewer strings than the project's active originals. A broken CI build must not mark thousands of strings obsolete. `--force` overrides this, and the wrapper deliberately does not expose it: forcing is a manual step on the server.
* `originals` honours the update lock, so it cannot overlap with a webhook update or another CI push. If a run dies mid-import the lock stays; clear the `_traduttore_update_lock` project meta before retrying.
* `import` never creates a translation for an original that already has a `current` or `waiting` one, and GlotPress' import-over-existing behaviour is switched off for the call.
* The wrapper validates every argument, never uses `eval`, and only runs the five commands above.

## Deploying

The copy running on translate.surecart.com is fork master plus debugging edits made directly on the server between January and August 2024: `error_log( print_r( ... ) )` calls in `Export`, `Loader/Git`, `Plugin`, `Runner`, `Updater`, `WebhookHandler/GitHub` and `ZipProvider`, and `Export::export_strings()` dumps every translation entry on each pack build. Apart from that it is functionally the same as fork master; the live `build_json_files()` just calls `build_single_json_file()`, so the output is identical. The live `vendor/` also contains the composer dev dependencies (64M).

Deploy by replacing the whole plugin directory with this branch rather than patching individual files, then run `composer install --no-dev`. This removes the debug logging along with the dev dependencies.

Once the CI push is live, disable the GitHub webhook for the repository. A webhook-triggered update would import a clone-based POT and obsolete the strings the CI build adds.
