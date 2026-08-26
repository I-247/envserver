package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/sebastiaankloos/kluis/cli/internal/config"
	"github.com/sebastiaankloos/kluis/cli/internal/envfile"
	"github.com/sebastiaankloos/kluis/cli/internal/vault"
)

func sealCommand() *cobra.Command {
	var (
		file    string
		version int
	)

	cmd := &cobra.Command{
		Use:   "seal",
		Short: "Store the release locally as an encrypted file",
		Long: "Fetches the release once and writes it encrypted to " + vault.FileName + ".\n" +
			"The file is unreadable without the deploy token it was sealed with,\n" +
			"and \"kluis run\" can hand its variables to a command afterwards\n" +
			"without touching the network or writing a plaintext .env.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			secret, err := vaultSecret()
			if err != nil {
				return err
			}

			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			release, err := fetchRelease(cmd, s, version)
			if err != nil {
				return err
			}

			sealed, err := vault.Seal(vault.Payload{
				Release:     release.Version,
				Project:     release.Project,
				Environment: release.Environment,
				SealedAt:    time.Now().UTC().Format(time.RFC3339),
				Variables:   release.Variables,
			}, secret)
			if err != nil {
				return err
			}

			path := vaultPath(file)

			if err := os.WriteFile(path, []byte(sealed), 0o600); err != nil {
				return err
			}

			p := printer(cmd)

			p.Done("Sealed release %d of %s into %s",
				release.Version, p.Bold(release.Project+"/"+release.Environment), p.Path(path))
			p.Note("%d variables, encrypted with the key you sealed it with.", len(release.Variables))
			p.Note("Open it with: kluis run -- <command>")

			return nil
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "where to write the sealed file (default: "+vault.FileName+" next to kluis.json)")
	cmd.Flags().IntVar(&version, "release", 0, "seal a specific release instead of the latest")

	return cmd
}

func unsealCommand() *cobra.Command {
	var (
		file string
		out  string
	)

	cmd := &cobra.Command{
		Use:   "unseal",
		Short: "Decrypt the local vault and show what is in it",
		Long: "Prints the variables in .env format on stdout, or writes them to a\n" +
			"file with --out. Reads nothing from the server.\n\n" +
			"Prefer \"kluis run\" where you can: it never puts the values on disk\n" +
			"or on a terminal that scrolls back.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			payload, path, err := openVault(file)
			if err != nil {
				return err
			}

			rendered := envfile.Render(payload.Variables)

			p := printer(cmd)

			if out == "" {
				// The header goes to stderr and the values go out unstyled:
				// stdout here is a .env someone is piping somewhere, and an
				// escape code in it would end up inside a secret.
				fmt.Fprintf(p.Err(), "%s\n", p.Dim(fmt.Sprintf("# %s: release %d of %s/%s, sealed %s",
					path, payload.Release, payload.Project, payload.Environment, payload.SealedAt)))
				fmt.Fprint(p.Plain().Out(), rendered)

				return nil
			}

			if err := os.WriteFile(out, []byte(rendered), 0o600); err != nil {
				return err
			}

			p.Done("Wrote release %d of %s to %s",
				payload.Release, p.Bold(payload.Project+"/"+payload.Environment), p.Path(out))
			p.Warn("Those values are in plain text now — do not commit that file.")

			return nil
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "the sealed file to read (default: "+vault.FileName+" next to kluis.json)")
	cmd.Flags().StringVarP(&out, "out", "o", "", "write the variables to this file instead of stdout")

	return cmd
}

// vaultSecret resolves the material the vault key is derived from.
//
// The deploy token comes first and covers the case this was built for: a
// machine that already holds credentials for exactly one environment needs no
// second secret to manage, and a token rotation locks the old file by itself.
// KLUIS_VAULT_KEY is the way out for a laptop that logs in interactively and
// therefore has no client secret to derive from.
func vaultSecret() (vault.Secret, error) {
	if key := os.Getenv("KLUIS_VAULT_KEY"); key != "" {
		return vault.FromKey(key)
	}

	if deployTokenSet() {
		return vault.FromDeployToken(os.Getenv("KLUIS_CLIENT_ID"), os.Getenv("KLUIS_CLIENT_SECRET")), nil
	}

	return vault.Secret{}, errors.New(
		"a local vault is locked with your deploy token, and none is set.\n" +
			"Either export KLUIS_CLIENT_ID and KLUIS_CLIENT_SECRET, or pick your own key:\n" +
			"  export KLUIS_VAULT_KEY=$(openssl rand -hex 32)")
}

// vaultPath locates the sealed file.
//
// It deliberately does not need a session: opening a vault must work with the
// server down and the token expired, so the project file is consulted only to
// find the directory, and its absence is not an error.
func vaultPath(override string) string {
	if override != "" {
		return override
	}

	dir := mustGetwd()

	if project, err := config.FindProject(dir); err == nil {
		return filepath.Join(project.Dir(), vault.FileName)
	}

	return filepath.Join(dir, vault.FileName)
}

// openVault reads and decrypts the sealed file.
func openVault(override string) (*vault.Payload, string, error) {
	path := vaultPath(override)

	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, path, fmt.Errorf("%s does not exist yet; create it with \"kluis seal\"", path)
	}
	if err != nil {
		return nil, path, err
	}

	secret, err := vaultSecret()
	if err != nil {
		return nil, path, err
	}

	payload, err := vault.Open(string(contents), secret)
	if errors.Is(err, vault.ErrWrongKey) {
		id, _ := vault.KeyID(string(contents))

		return nil, path, fmt.Errorf(
			"%w (%s was sealed with key %s).\n"+
				"Rotated the deploy token? Run \"kluis seal\" again with the new one",
			err, filepath.Base(path), strings.TrimSpace(id))
	}
	if err != nil {
		return nil, path, err
	}

	return payload, path, nil
}
