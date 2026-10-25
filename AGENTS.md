# Project rules

These rules always apply. Boost tools might add rules below, everything outside their blocks is ours and has priority. Never write boost block's opening tag anywhere else in this file — Boost matches the first occurrence and would overwrite from there.

**Read [DEVELOPERS.md](DEVELOPERS.md) first.** It holds the conventions that are specific to this repository and that Boost's generic guidance does not cover. The essentials:

- **Format with same formatting tools as the user** or don't try to format, in any case only the project's own copy, on changed files only — never across the whole project.
- **Framework first.** Never build custom HTML, PHP or any code for what the include libaries or well-known stable libraries already do.
- **Every user-facing string goes through localization when applicable**.
- **An empty field stays empty** — no `-` or `–` standing in for nothing.
- **Every change is covered by a test.** Reproduce a reported bug before fixing it, and let the test fail first.
- **Git**: unless told otherwise, work on `dev`, commit `(scope) short subject` with `-` bullets, prefix `(untested)` until verified,
- never claim co-authorship
- **never push commits**

## Commit message format

```
type (scope): short subject

- detail
- detail

Optional additional context.
```

- **type**: lowercase, no trailing period, type of change (e.g. `feature`, `fix`, `update`, `refactor`, `test`, `doc`)
- **scope**: area of the change — e.g. `api`, `lsl`, `build`, `tests`, `config`
- **subject**: imperative, lowercase, no trailing period
- **details**: bullet list with `-`, one item per logical change
- Omit details for trivial single-change commits
- Prefix with `(untested)` when the change has not been verified yet; reword after a successful test

## Version releases

Tag: 1.2.3 (no v prefix).

Changelog:

```
### 1.2.3 Main change if applicable
- new ...
- new ...
- fix ...
- update ...
```

Commit message and tag message strictly identical to changelog, with the version prefixed with "v" (needed by some parsing tools)

```
v1.2.3 Main change if applicable
- new ...
- new ...
- fix ...
- update ...
```

- **subject** first line begins exactly with "v" + the version number to allow automated workflows and maintenance scripts. An option description of the main change might be added if relevant
- **details** a list of the main changes since the previous version release commit
- create a version release only when the version is fully tested and approved: bumping the version number in files does not mean the version must be released yet
- Be concise, full explanation can be found in git history
- Omit small patches and fixes, focus on essential features
- Make sure to update all relevant files (.version, README.md, composer.json... ) and update CHANGELOG.md with the exact same description
- Replace `dev-dev` requires with released constraints (e.g. `^1.0`) and run `composer update` so that `composer.lock` no longer points to local paths, otherwise the release cannot be installed on another machine. Switch back to `dev-dev` after the release
- after commit, add a tag named with the bare version number ("1.2.3"), with the exact same message as the commit (starting with "v1.2.3")
