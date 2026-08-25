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
kluis pull           # update the keys your .env already has
kluis pull --constructive   # also add keys you do not have yet
kluis diff           # compare your .env with the latest release
kluis push -m "..."  # send your local values back
kluis history        # release history for this environment
```

`pull` never removes a key that exists only in your file, and by default never
adds one you did not ask for. Your machine specific entries stay yours.

## On a deploy server

Create a deploy token in the portal for one environment, then set:

```shell
export KLUIS_SERVER=https://kluis.example.com
export KLUIS_CLIENT_ID=...
export KLUIS_CLIENT_SECRET=...
```

The token is bound to a single environment server side, so nothing in the
deploy script has to name one, and nothing in it can point somewhere else.

```shell
kluis pull --constructive --out .env      # write a fresh .env
kluis run -- php artisan migrate --force  # or skip the file entirely
```

`kluis run` hands the variables straight to the child process and writes
nothing to disk, which is the safer option for deploy steps.

## Development

```shell
go test ./...
go build ./cmd/kluis
```
