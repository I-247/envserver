package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"

	"github.com/spf13/cobra"

	"github.com/I-247/envserver/cli/internal/api"
	"github.com/I-247/envserver/cli/internal/auth"
	"github.com/I-247/envserver/cli/internal/config"
	"github.com/I-247/envserver/cli/internal/ui"
)

// noColour is set by --no-color. Colour is otherwise decided per stream, so
// this flag only ever takes it away, never forces it on.
var noColour bool

// printer builds the renderer for a command, bound to that command's streams
// so tests can capture what was written.
func printer(cmd *cobra.Command) *ui.Printer {
	p := ui.New(cmd.OutOrStdout(), cmd.ErrOrStderr())

	if noColour {
		p.SetColour(false)
	}

	return p
}

func rootCommand() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "envclient",
		Short: "Manage environment variables stored in Envserver",
		Long: "Envserver keeps your environment variables in one place, with history,\n" +
			"and hands them to your machines when they deploy.",
		Version:       version,
		SilenceUsage:  true,
		SilenceErrors: true,
	}

	cmd.PersistentFlags().BoolVar(&noColour, "no-color", false, "never colour the output")

	cmd.AddCommand(
		loginCommand(),
		logoutCommand(),
		whoamiCommand(),
		initCommand(),
		pullCommand(),
		pushCommand(),
		diffCommand(),
		checkCommand(),
		listCommand(),
		historyCommand(),
		runCommand(),
		sealCommand(),
		unsealCommand(),
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
	return os.Getenv("ENVCLIENT_CLIENT_ID") != "" && os.Getenv("ENVCLIENT_CLIENT_SECRET") != ""
}

// openSession resolves the project and authenticates.
func openSession(ctx context.Context) (*session, error) {
	dir, err := os.Getwd()
	if err != nil {
		return nil, err
	}

	project, err := config.FindProject(dir)
	if err != nil {
		if errors.Is(err, config.ErrNoProject) && deployTokenSet() && os.Getenv("ENVCLIENT_SERVER") != "" {
			project = &config.Project{Server: os.Getenv("ENVCLIENT_SERVER")}
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
			os.Getenv("ENVCLIENT_CLIENT_ID"),
			os.Getenv("ENVCLIENT_CLIENT_SECRET"),
			strings.Fields(envOr("ENVCLIENT_SCOPES", "env:read")),
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
		return "", fmt.Errorf("not logged in to %s; run \"envclient login\"", server)
	}

	if credentials.Expired() {
		return "", fmt.Errorf("your session for %s expired; run \"envclient login\" again", server)
	}

	return credentials.AccessToken, nil
}

func envOr(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}

	return fallback
}
