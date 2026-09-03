package main

import (
	"fmt"
	"os"

	"github.com/spf13/cobra"

	"github.com/I-247/envserver/cli/internal/api"
	"github.com/I-247/envserver/cli/internal/auth"
	"github.com/I-247/envserver/cli/internal/config"
)

func loginCommand() *cobra.Command {
	var server string

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Log in to an Envserver server from your terminal",
		Long: "Starts the OAuth device flow: Envserver shows you a code, you approve it\n" +
			"in your browser, and the token is stored on this machine only.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()

			if server == "" {
				project, err := config.FindProject(mustGetwd())
				if err != nil {
					return fmt.Errorf("pass --server, or run this inside a project with a envclient.json")
				}

				server = project.Server
			}

			discovery, err := api.Discover(ctx, server)
			if err != nil {
				return err
			}

			code, err := auth.RequestDeviceCode(ctx, discovery)
			if err != nil {
				return err
			}

			p := printer(cmd)

			p.Blank()
			p.Info("Open %s", p.Path(code.VerificationURI))
			p.Note("and enter the code  %s", p.Highlight(code.UserCode))
			p.Blank()
			p.Note("Waiting for approval...")

			credentials, err := auth.PollForToken(ctx, discovery, code)
			if err != nil {
				return err
			}

			if err := config.SaveCredentials(server, credentials); err != nil {
				return err
			}

			path, _ := config.CredentialsPath()

			p.Blank()
			p.Done("Logged in to %s", p.Bold(server))
			p.Note("Token stored in %s — this machine only.", path)

			return nil
		},
	}

	cmd.Flags().StringVar(&server, "server", "", "the Envserver server to log in to")

	return cmd
}

func logoutCommand() *cobra.Command {
	var server string

	cmd := &cobra.Command{
		Use:   "logout",
		Short: "Remove the stored token for a server",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			if server == "" {
				project, err := config.FindProject(mustGetwd())
				if err != nil {
					return fmt.Errorf("pass --server, or run this inside a project with a envclient.json")
				}

				server = project.Server
			}

			if err := config.ForgetCredentials(server); err != nil {
				return err
			}

			p := printer(cmd)
			p.Done("Logged out of %s", p.Bold(server))

			return nil
		},
	}

	cmd.Flags().StringVar(&server, "server", "", "the Envserver server to log out of")

	return cmd
}

func whoamiCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "whoami",
		Short: "Show which server you are logged in to and what you can reach",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			projects, err := s.client.Projects(cmd.Context())
			if err != nil {
				return err
			}

			p := printer(cmd)

			p.Title("Signed in")
			p.Field("Server", s.project.Server)
			p.Field("Projects", fmt.Sprintf("%d", len(projects)))

			if s.project.Name != "" {
				p.Field("Linked", s.project.Team+"/"+s.project.Name+"/"+s.project.Environment)
			}

			if deployTokenSet() {
				p.Field("Auth", "deploy token from the environment")
			} else {
				p.Field("Auth", "your login on this machine")
			}

			return nil
		},
	}
}

func mustGetwd() string {
	dir, err := os.Getwd()
	if err != nil {
		return "."
	}

	return dir
}
