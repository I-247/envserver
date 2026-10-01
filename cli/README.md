# Envserver CLI

Syncs environment variables between an Envserver server and a working directory.

The same documentation is in the portal at `/docs/cli` on your Envserver
server, with your server's URL already filled in. Set
`ENVSERVER_PUBLIC_CLI_DOCS=false` on the server to show that page to signed in
users only.

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

Once it's installed, `envclient update` keeps it current — see
[Updating](#updating) below.

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

`push` works with a personal login (`envclient login`) or a deploy token that
was explicitly granted push access when it was created — most are read only,
and get a 403 for it. `--publish` always needs a personal login, even then:
there is no deploy-scoped route for publishing a release.

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
It's read only unless the portal's "also allow this token to push variables"
checkbox was ticked when it was created — off by default, so a leaked token
can only be read from, never used to overwrite what's stored in Envserver.

Nothing to export on a server you don't script, like one you SSH into by
hand: drop the same three lines into a `.envclientrc` file next to
`envclient.json` instead, and every command picks them up automatically. A
real export always wins; `.envclientrc` wins over `.env` for the same key.
Never commit either file with these values in it.

They can also live directly in the `.env` file `pull` writes, for the same
effect with one file fewer — `pull --prune` is aware of them and never
deletes an `ENVCLIENT_*` key even though the release doesn't mention it, so
that can't lock out the very credential that fetched it.

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

## Updating

```shell
envclient update          # show and confirm before installing
envclient update --check  # only report whether a newer release exists
envclient update --force  # skip the confirmation (or reinstall the current version)
```

It first checks the Sigstore signature on the release's `checksums.txt`,
then downloads the archive for your OS and architecture, checks it against
those checksums, and replaces this binary in place — the same one you'd get
from re-running `install.sh`, without leaving the terminal. A release that
is unsigned, or signed by anything other than this repository's release
workflow for that exact tag, is refused. It never installs an older version.
On a machine with no terminal to confirm at, `--force` is required, the same
rule `pull` follows.

### Verifying a release

Every release has `checksums.txt.sigstore.json`: a keyless Sigstore
signature over `checksums.txt`, made by the release workflow. `install.sh`
checks it when `cosign` is installed (set `ENVCLIENT_REQUIRE_SIGNATURE=1`
to refuse to install without it). By hand:

```shell
cosign verify-blob \
  --bundle checksums.txt.sigstore.json \
  --certificate-identity https://github.com/I-247/envserver/.github/workflows/release.yml@refs/tags/vX.Y.Z \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  checksums.txt
sha256sum --check --ignore-missing checksums.txt
```

## Commands

Every command also takes `--no-color` and `--help`.

| Command | What it does | Flags |
| --- | --- | --- |
| `init` | Link this directory to an environment by writing `envclient.json` | `--server`, `--team`, `--project`, `--environment` (default `development`) |
| `login` | Log in from the terminal; approve the code in the browser | `--server` (default: the one in `envclient.json`) |
| `logout` | Remove the stored token for a server | `--server` |
| `whoami` | Show which server you are logged in to and what you can reach | |
| `list` | List the projects and environments you can reach | |
| `pull` | Write the published variables into your .env, after asking | `--constructive`, `--prune`, `--dry-run`, `--force`, `-o/--out`, `--release` |
| `push` | Send the values in your .env to the server | `-m/--message`, `--publish` (personal login only), `-f/--file` |
| `diff` | Compare your .env with the latest release | `-f/--file` |
| `check` | Like `diff`, but exits 2 when your file is missing something | `-f/--file`, `--strict` |
| `history` | Show the release history of this environment | |
| `run -- <command>` | Run a command with the variables injected, writing no file | `--vault`, `--remote`, `-f/--file`, `--release` |
| `seal` | Store the release locally, encrypted, as `.env.envclient` | `-f/--file`, `--release` |
| `unseal` | Decrypt the local vault and show what is in it | `-f/--file`, `-o/--out` |
| `update` | Update envclient to the latest signed release | `--check`, `--force` |

`envclient.json`, written by `init` and safe to commit:

```json
{
    "server": "https://envserver.example.com",
    "team": "acme",
    "project": "webshop",
    "environment": "development"
}
```

`envclient login` keeps its token in `credentials.json` in your user config
directory (`~/.config/envclient` on Linux, `~/Library/Application
Support/envclient` on macOS), or in `ENVCLIENT_CONFIG_DIR` when set.

## Environment variables

| Variable | Meaning |
| --- | --- |
| `ENVCLIENT_SERVER` | The server a deploy token belongs to. Required next to a deploy token; the secret is never sent anywhere else. |
| `ENVCLIENT_CLIENT_ID` | The deploy token's client ID. Takes priority over a stored personal login. |
| `ENVCLIENT_CLIENT_SECRET` | The deploy token's secret. Also the key a sealed file is locked with. |
| `ENVCLIENT_SCOPES` | The scopes asked for with a deploy token (default `env:read env:write`). The token itself decides what it may do, so leave this alone. |
| `ENVCLIENT_VAULT_KEY` | Your own key for `seal` and `run`, for a machine without a deploy token. At least 16 bytes. |
| `ENVCLIENT_CONFIG_DIR` | Where `credentials.json` is kept. |
| `NO_COLOR` | Turns colour off, like `--no-color`. |
| `CLICOLOR_FORCE` | Turns colour on when the output is piped. |

`ENVCLIENT_SERVER`, `ENVCLIENT_CLIENT_ID`, `ENVCLIENT_CLIENT_SECRET` and
`ENVCLIENT_SCOPES` can also come from `.envclientrc` or `.env` in the current
directory. The others must be exported. For the installer, `INSTALL_DIR` sets
where the binary goes and `ENVCLIENT_REQUIRE_SIGNATURE=1` refuses an install
that cannot be verified.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | The command succeeded. |
| `1` | The command could not run: no network, no token, no release, or another error. |
| `2` | `envclient check` only: your file does not match the release. |

## Troubleshooting

### `this pull needs confirmation and there is no terminal to ask at`

The pull ran without a terminal, as on a deploy server or in CI. Add
`--force` to apply it, or `--dry-run` to only look.

### A deploy does not show up in the portal

Only a pull with a deploy token counts as a deploy. A pull with
`envclient login` does not. Check that `ENVCLIENT_CLIENT_ID` and
`ENVCLIENT_CLIENT_SECRET` are set on the server. The deploy tokens page shows
when each token was last used.

### `This deploy token may not be used from this address.`

The server's IP address is not on an allow list. Both the environment's list
and the token's own list must allow it. Change the token's list on the deploy
tokens page, or the environment's list under the environment's settings.
Every refusal is in the audit trail.

### 403 when pushing with a deploy token

Deploy tokens are read only unless "also allow this token to push variables"
was ticked when it was created. Create a new token with push access, or push
with a personal login.

### `--publish needs a personal login`

A deploy token can push but never publish a release. Push without
`--publish` and publish from the portal, or run `envclient login`.

### `Invalid scope(s) provided.`

`ENVCLIENT_SCOPES` asks for less than the command needs. Unset it so the
default applies.

### `a deploy token needs ENVCLIENT_SERVER set next to it`

Set `ENVCLIENT_SERVER` in the same place as the client ID and secret. With a
deploy token the server always comes from there, and envclient refuses when
`envclient.json` names a different one.

### Not logged in, or your session expired

Run `envclient login`. A login lasts a limited number of days, set by the
server.

### `no envclient.json found`

Run the command inside a linked project, or run `envclient init` first.
Deploy tokens do not need this file.

### `this vault was sealed with a different key`

The deploy token was rotated, or `ENVCLIENT_VAULT_KEY` changed. Run
`envclient seal` again with the current key.

## Development

```shell
go test ./...
go build ./cmd/envclient
```
