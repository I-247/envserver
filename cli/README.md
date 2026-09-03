# Envserver CLI

Syncs environment variables between an Envserver server and a working directory.

## Install

On a server, download the latest binary straight from the [releases
page](https://github.com/I-247/envserver/releases/latest):

```shell
curl -fsSL https://raw.githubusercontent.com/I-247/envserver/main/cli/scripts/install.sh | sh
```

It detects the OS and architecture, installs to `/usr/local/bin` (`sudo` if
needed), and can be redirected elsewhere with `INSTALL_DIR`:

```shell
curl -fsSL https://raw.githubusercontent.com/I-247/envserver/main/cli/scripts/install.sh \
    | INSTALL_DIR=$HOME/.local/bin sh
```

Or build it yourself:

```shell
go install github.com/I-247/envserver/cli/cmd/envclient@latest
```

## Link a project

Run this once per repository and commit the result. `envclient.json` names the
project; it holds no secrets.

```shell
envclient init --server https://envserver.example.com \
           --team acme --project webshop --environment development
```

## Day to day

```shell
envclient login          # device flow: a code in the terminal, approval in the browser
envclient pull           # show what would change, then ask before writing
envclient pull --constructive   # also add keys you do not have yet
envclient pull --prune          # also delete keys the release no longer has
envclient pull --dry-run        # only ever look
envclient pull --force          # apply without asking
envclient diff           # compare your .env with the latest release
envclient check          # the same comparison, but exits 2 when they disagree
envclient push -m "..."  # send your local values back
envclient history        # release history for this environment
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

`envclient check` is `envclient diff` with an opinion. It exits 0 when your file holds
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
    ENVCLIENT_SERVER: https://envserver.example.com
    ENVCLIENT_CLIENT_ID: ${{ secrets.ENVCLIENT_CLIENT_ID }}
    ENVCLIENT_CLIENT_SECRET: ${{ secrets.ENVCLIENT_CLIENT_SECRET }}
  run: envclient check --file .env.example
```

```yaml
# .gitlab-ci.yml
check:env:
  script:
    - envclient check --file .env.example
```

## On a deploy server

Create a deploy token in the portal for one environment, then set:

```shell
export ENVCLIENT_SERVER=https://envserver.example.com
export ENVCLIENT_CLIENT_ID=...
export ENVCLIENT_CLIENT_SECRET=...
```

The token is bound to a single environment server side, so nothing in the
deploy script has to name one, and nothing in it can point somewhere else.

A deploy server has no terminal to answer the confirmation at, so `pull` there
needs `--force`. Without it the pull stops with an error rather than guessing.

```shell
envclient pull --constructive --force --out .env   # write a fresh .env
envclient run -- php artisan migrate --force  # or skip the file entirely
```

`envclient run` hands the variables straight to the child process and writes
nothing to disk, which is the safer option for deploy steps.

## Keeping the variables locally, encrypted

`envclient seal` fetches the release once and stores it as `.env.envclient`, encrypted
with AES-256-GCM. Everything is inside the ciphertext, including which project
and environment it came from.

```shell
envclient seal                                # writes .env.envclient
envclient run -- php artisan migrate --force  # reads it, injects, execs
```

`envclient run` prefers a sealed file over the server, so once it exists the
variables reach your command with no network call and no live token. Force
either side with `--vault` or `--remote`.

The key is derived from your deploy token, so there is no second secret to
keep somewhere:

```
ENVCLIENT_CLIENT_SECRET ──HKDF-SHA256(salt, info = client id)──> AES-256-GCM key
```

Whoever may pull the environment may open the file, and nobody else. Rotating
the token therefore locks the old file; run `envclient seal` again with the new
one. On a laptop that logs in with `envclient login` there is no client secret, so
name your own key instead:

```shell
export ENVCLIENT_VAULT_KEY=$(openssl rand -hex 32)
```

Keep `.env.envclient` out of version control. It is encrypted, so committing it
is not a disaster, but the key is your deploy token: anyone who has that
token, now or after they leave, could read every commit it ever appeared in.

`envclient unseal` prints the contents, or writes them to a plaintext file with
`--out`. Prefer `envclient run`: it keeps the values out of your disk and your
scrollback.

## Development

```shell
go test ./...
go build ./cmd/envclient
```
