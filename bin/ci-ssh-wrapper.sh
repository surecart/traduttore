#!/usr/bin/env bash
#
# Forced SSH command for the CI key. Runs a fixed set of WP-CLI calls and nothing else.
#
# authorized_keys:
#   command="TRADUTTORE_CI_WP_PATH=/var/www/html /path/to/ci-ssh-wrapper.sh",restrict ssh-ed25519 AAAA... surecart-ci
#
# Commands (read from $SSH_ORIGINAL_COMMAND):
#   originals                                POT on stdin
#   sets
#   export <locale> <set>                    PO on stdout
#   import <locale> <set> [current|waiting]  PO on stdin
#   packs
#
# See docs/ci-sync.md.

set -euo pipefail

wp_path="${TRADUTTORE_CI_WP_PATH:?TRADUTTORE_CI_WP_PATH is not set}"
project="${TRADUTTORE_CI_PROJECT:-surecart}"
cli_user="${TRADUTTORE_CI_USER:-ai-bot}"

# Importing a 5k string POT or PO goes well past the default 128M.
export WP_CLI_PHP_ARGS='-d memory_limit=1024M'

tmp=''

cleanup() {
	if [[ -n "$tmp" ]]; then
		rm -f "$tmp"
	fi
}
trap cleanup EXIT

die() {
	printf 'ci-ssh-wrapper: %s\n' "$*" >&2
	exit 1
}

wp() {
	command wp --path="$wp_path" --user="$cli_user" "$@"
}

is_slug() {
	[[ "$1" =~ ^[a-z0-9_-]+$ ]]
}

stdin_to_tmp() {
	tmp="$(mktemp)"
	cat > "$tmp"
}

# Named variables instead of an array: surplus words land in $extra, and this
# stays portable to bash 3 where "${#array[@]}" trips over set -u.
read -r cmd arg1 arg2 arg3 extra <<< "${SSH_ORIGINAL_COMMAND:-}"

case "$cmd" in
	originals)
		[[ -z "$arg1" ]] || die 'usage: originals < file.pot'
		stdin_to_tmp
		wp traduttore ci originals "$project" "$tmp"
		;;
	sets)
		[[ -z "$arg1" ]] || die 'usage: sets'
		wp traduttore ci sets "$project"
		;;
	export)
		[[ -n "$arg2" && -z "$arg3" ]] || die 'usage: export <locale> <set>'
		is_slug "$arg1" && is_slug "$arg2" || die 'locale and set must match ^[a-z0-9_-]+$'
		# GlotPress CLI defaults to current only; the AI job needs untranslated originals too.
		wp glotpress translation-set export "$project" "$arg1" --set="$arg2" --status=current_or_untranslated
		;;
	import)
		[[ -n "$arg2" && -z "$extra" ]] || die 'usage: import <locale> <set> [current|waiting] < file.po'
		is_slug "$arg1" && is_slug "$arg2" || die 'locale and set must match ^[a-z0-9_-]+$'
		status="${arg3:-current}"
		[[ "$status" == current || "$status" == waiting ]] || die 'status must be current or waiting'
		stdin_to_tmp
		wp traduttore ci import "$project" "$arg1" "$arg2" "$tmp" --status="$status"
		;;
	packs)
		[[ -z "$arg1" ]] || die 'usage: packs'
		wp traduttore language-pack build "$project"
		;;
	*)
		die "unknown command: $cmd"
		;;
esac
