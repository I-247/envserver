package main

import (
	"fmt"
	"os"

	"github.com/spf13/cobra"

	"github.com/sebastiaankloos/kluis/cli/internal/api"
	"github.com/sebastiaankloos/kluis/cli/internal/auth"
	"github.com/sebastiaankloos/kluis/cli/internal/config"
)

func loginCommand() *cobra.Command {
	var server string

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Log in to a Kluis server from your terminal",
		Long: "Starts the OAuth device flow: Kluis shows you a code, you approve it\n" +
			"in your browser, and the token is stored on this machine only.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()

			if server == "" {
				project, err := config.FindProject(mustGetwd())
				if err != nil {
					return fmt.Errorf("pass --server, or run this inside a project with a kluis.json")
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

			fmt.Fprintf(cmd.OutOrStdout(), "\n  Open %s\n  and enter the code %s\n\n  Waiting for approval...\n",
				code.VerificationURI, code.UserCode)

			credentials, err := auth.PollForToken(ctx, discovery, code)
			if err != nil {
				return err
			}

			if err := config.SaveCredentials(server, credentials); err != nil {
				return err
			}

			path, _ := config.CredentialsPath()
			fmt.Fprintf(cmd.OutOrStdout(), "\n  Logged in to %s.\n  Token stored in %s\n", server, path)

			return nil
		},
	}

	cmd.Flags().StringVar(&server, "server", "", "the Kluis server to log in to")

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
					return fmt.Errorf("pass --server, or run this inside a project with a kluis.json")
				}

				server = project.Server
			}

			if err := config.ForgetCredentials(server); err != nil {
				return err
			}

			fmt.Fprintf(cmd.OutOrStdout(), "Logged out of %s.\n", server)

			return nil
		},
	}

	cmd.Flags().StringVar(&server, "server", "", "the Kluis server to log out of")

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

			fmt.Fprintf(cmd.OutOrStdout(), "Server   %s\n", s.project.Server)
			fmt.Fprintf(cmd.OutOrStdout(), "Projects %d\n", len(projects))

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
