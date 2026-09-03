package main

import (
	"fmt"
	"net/http"
	"os"
	"runtime"
	"time"

	"github.com/spf13/cobra"

	"github.com/I-247/envserver/cli/internal/selfupdate"
	"github.com/I-247/envserver/cli/internal/ui"
)

func updateCommand() *cobra.Command {
	var (
		check bool
		force bool
	)

	cmd := &cobra.Command{
		Use:   "update",
		Short: "Update envclient to the latest release",
		Long: "Downloads the latest envclient release for this OS and architecture,\n" +
			"checks it against the published checksums, and replaces this binary\n" +
			"in place.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			p := printer(cmd)
			client := &http.Client{Timeout: 30 * time.Second}

			release, err := selfupdate.Latest(ctx, client)
			if err != nil {
				return err
			}

			latest := release.Version()

			p.Title("envclient update")
			p.Field("Installed", installedVersionLabel())
			p.Field("Latest", latest)

			if latest == version && !force {
				p.Done("Already on the latest version.")

				return nil
			}

			if check {
				p.Info("Run %s to install it.", p.Bold("envclient update"))

				return nil
			}

			if !ui.Interactive(cmd.InOrStdin()) {
				if !force {
					return fmt.Errorf("this update needs confirmation and there is no terminal to ask at.\n" +
						"Pass --force to install it, or --check to only look")
				}
			} else if !force {
				approved, err := p.Confirm(cmd.InOrStdin(), "Install envclient %s?", latest)
				if err != nil {
					return err
				}

				if !approved {
					p.Info("Left alone.")

					return nil
				}
			}

			exe, err := os.Executable()
			if err != nil {
				return err
			}

			name := selfupdate.AssetName(latest, runtime.GOOS, runtime.GOARCH)

			p.Note("Downloading %s...", name)

			archive, err := selfupdate.Download(ctx, client, release.TagName, name)
			if err != nil {
				return err
			}

			checksums, err := selfupdate.Download(ctx, client, release.TagName, "checksums.txt")
			if err != nil {
				return err
			}

			if err := selfupdate.VerifyChecksum(archive, checksums, name); err != nil {
				return err
			}

			binary, err := selfupdate.ExtractBinary(archive, "envclient")
			if err != nil {
				return err
			}

			if err := selfupdate.Install(binary, exe); err != nil {
				return err
			}

			p.Done("Updated to %s  %s", p.Bold(latest), p.Path(exe))

			return nil
		},
	}

	cmd.Flags().BoolVar(&check, "check", false, "only report whether an update is available")
	cmd.Flags().BoolVar(&force, "force", false, "skip the confirmation prompt (and reinstall even if already current)")

	return cmd
}

// installedVersionLabel reads better than a bare "dev" when this binary was
// built locally rather than by GoReleaser.
func installedVersionLabel() string {
	if version == "dev" {
		return "dev build"
	}

	return version
}
