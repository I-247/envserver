# Kluis CLI

Syncs environment variables between a Kluis server and a working directory.

## Install

```shell
go install github.com/sebastiaankloos/kluis/cli/cmd/kluis@latest
```

Or grab a binary from the releases page.

## Link a project

Run this once per repository and commit the result. `kluis.json` names the
project; it holds no secrets.

```shell
kluis init --server https://kluis.example.com \
           --team acme --project webshop --environment development
```

## Day to day

```shell
kluis login          # device flow: a code in the terminal, approval in the browser
kluis pull           # show what would change, then ask before writing
kluis pull --constructive   # also add keys you do not have yet
kluis pull --prune          # also delete keys the release no longer has
kluis pull --dry-run        # only ever look
kluis pull --force          # apply without asking
kluis diff           # compare your .env with the latest release
kluis check          # the same comparison, but exits 2 when they disagree
kluis push -m "..."  # send your local values back
kluis history        # release history for this environment
```

`pull` shows you what it would do and waits for a yes before it touches the
file. It never removes a key that exists only in your file, and by default
never adds one you did not ask for: your machine specific entries stay yours,
unless you ask for `--prune`.

## Output

```
Release 12  acme/webshop/development
  ~ APP_KEY      updated
  + MAIL_MAILER  added
  - OLD_ITEM     removed, not in the release
✓ .env  1 updated · 1 added · 1 removed · 8 unchanged
```

Colour is used on a terminal and dropped everywhere else, so piping or
redirecting gives you plain text. `NO_COLOR=1` or `--no-color` turns it off by
hand, `CLICOLOR_FORCE=1` turns it back on for a CI runner that pipes stdout.

## In CI

`kluis check` is `kluis diff` with an opinion. It exits 0 when your file holds
everything the release does, and 2 when it does not, so a pipeline can stop
before a deploy that was going to be missing a key.

Keys that exist only in your file are printed and then ignored, the same
promise `pull` makes by never pruning unless asked. `--strict` counts those
too.

Exit code 1 stays what it always is: the check could not run at all — no
network, no token, no release. That is a different conversation from "your
file is out of date", so it is a different code.

```yaml
# .github/workflows/env.yml
- name: Check the environment file
  env:
    KLUIS_SERVER: https://kluis.example.com
    KLUIS_CLIENT_ID: ${{ secrets.KLUIS_CLIENT_ID }}
    KLUIS_CLIENT_SECRET: ${{ secrets.KLUIS_CLIENT_SECRET }}
  run: kluis check --file .env.example
```

```yaml
# .gitlab-ci.yml
check:env:
  script:
    - kluis check --file .env.example
```

## On a deploy server

Create a deploy token in the portal for one environment, then set:

```shell
export KLUIS_SERVER=https://kluis.example.com
export KLUIS_CLIENT_ID=...
export KLUIS_CLIENT_SECRET=...
```

The token is bound to a single environment server side, so nothing in the
deploy script has to name one, and nothing in it can point somewhere else.

A deploy server has no terminal to answer the confirmation at, so `pull` there
needs `--force`. Without it the pull stops with an error rather than guessing.

```shell
kluis pull --constructive --force --out .env   # write a fresh .env
kluis run -- php artisan migrate --force  # or skip the file entirely
```

`kluis run` hands the variables straight to the child process and writes
nothing to disk, which is the safer option for deploy steps.

## Keeping the variables locally, encrypted

`kluis seal` fetches the release once and stores it as `.env.kluis`, encrypted
with AES-256-GCM. Everything is inside the ciphertext, including which project
and environment it came from.

```shell
kluis seal                                # writes .env.kluis
kluis run -- php artisan migrate --force  # reads it, injects, execs
```

`kluis run` prefers a sealed file over the server, so once it exists the
variables reach your command with no network call and no live token. Force
either side with `--vault` or `--remote`.

The key is derived from your deploy token, so there is no second secret to
keep somewhere:

```
KLUIS_CLIENT_SECRET ──HKDF-SHA256(salt, info = client id)──> AES-256-GCM key
```

Whoever may pull the environment may open the file, and nobody else. Rotating
the token therefore locks the old file; run `kluis seal` again with the new
one. On a laptop that logs in with `kluis login` there is no client secret, so
name your own key instead:

```shell
export KLUIS_VAULT_KEY=$(openssl rand -hex 32)
```

Keep `.env.kluis` out of version control. It is encrypted, so committing it
is not a disaster, but the key is your deploy token: anyone who has that
token, now or after they leave, could read every commit it ever appeared in.

`kluis unseal` prints the contents, or writes them to a plaintext file with
`--out`. Prefer `kluis run`: it keeps the values out of your disk and your
scrollback.

## Development

```shell
go test ./...
go build ./cmd/kluis
```
