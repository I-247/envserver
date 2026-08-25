package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"

	"github.com/spf13/cobra"

	"github.com/sebastiaankloos/kluis/cli/internal/api"
	"github.com/sebastiaankloos/kluis/cli/internal/auth"
	"github.com/sebastiaankloos/kluis/cli/internal/config"
)

func rootCommand() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "kluis",
		Short: "Manage environment variables stored in Kluis",
		Long: "Kluis keeps your environment variables in one place, with history,\n" +
			"and hands them to your machines when they deploy.",
		Version:       version,
		SilenceUsage:  true,
		SilenceErrors: true,
	}

	cmd.AddCommand(
		loginCommand(),
		logoutCommand(),
		whoamiCommand(),
		initCommand(),
		pullCommand(),
		pushCommand(),
		diffCommand(),
		listCommand(),
		historyCommand(),
		runCommand(),
	)

	return cmd
}

// session is everything a command needs to talk to the server.
type session struct {
	client  *api.Client
	target  api.Target
	project *config.Project
}

// deployTokenSet reports whether the environment holds machine credentials.
//
// Checked before anything else so a deploy server never depends on a
// credentials file having been written by an interactive login.
func deployTokenSet() bool {
	return os.Getenv("KLUIS_CLIENT_ID") != "" && os.Getenv("KLUIS_CLIENT_SECRET") != ""
}

// openSession resolves the project and authenticates.
func openSession(ctx context.Context) (*session, error) {
	dir, err := os.Getwd()
	if err != nil {
		return nil, err
	}

	project, err := config.FindProject(dir)
	if err != nil {
		if errors.Is(err, config.ErrNoProject) && deployTokenSet() && os.Getenv("KLUIS_SERVER") != "" {
			project = &config.Project{Server: os.Getenv("KLUIS_SERVER")}
		} else {
			return nil, err
		}
	}

	token, err := accessToken(ctx, project.Server)
	if err != nil {
		return nil, err
	}

	return &session{
		client:  api.New(project.Server, token),
		target:  api.Target{Team: project.Team, Project: project.Name, Environment: project.Environment},
		project: project,
	}, nil
}

// accessToken picks the right credential for the situation: machine
// credentials from the environment when present, otherwise the token stored
// by an interactive login.
func accessToken(ctx context.Context, server string) (string, error) {
	if deployTokenSet() {
		credentials, err := auth.ClientCredentials(
			ctx,
			server,
			os.Getenv("KLUIS_CLIENT_ID"),
			os.Getenv("KLUIS_CLIENT_SECRET"),
			strings.Fields(envOr("KLUIS_SCOPES", "env:read")),
		)
		if err != nil {
			return "", err
		}

		return credentials.AccessToken, nil
	}

	credentials, found, err := config.LoadCredentials(server)
	if err != nil {
		return "", err
	}

	if !found {
		return "", fmt.Errorf("not logged in to %s; run \"kluis login\"", server)
	}

	if credentials.Expired() {
		return "", fmt.Errorf("your session for %s expired; run \"kluis login\" again", server)
	}

	return credentials.AccessToken, nil
}

func envOr(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}

	return fallback
}
