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
		updateCommand(),
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
	if err != nil && !(errors.Is(err, config.ErrNoProject) && deployTokenSet()) {
		return nil, err
	}

	server, err := sessionServer(project)
	if err != nil {
		return nil, err
	}

	if project == nil {
		project = &config.Project{Server: server}
	}

	token, err := accessToken(ctx, server)
	if err != nil {
		return nil, err
	}

	return &session{
		client:  api.New(server, token),
		target:  api.Target{Team: project.Team, Project: project.Name, Environment: project.Environment},
		project: project,
	}, nil
}

// sessionServer decides which server this session talks to.
//
// A deploy token belongs to ENVCLIENT_SERVER and to nothing else: the
// envclient.json next to it came with the repository, and whoever wrote the
// repository must not be able to point the client secret somewhere of their
// choosing. A personal login needs no such guard, because its token is
// stored per server and a different URL simply finds none.
func sessionServer(project *config.Project) (string, error) {
	if !deployTokenSet() {
		return project.Server, config.CheckServer(project.Server)
	}

	server := os.Getenv("ENVCLIENT_SERVER")
	if server == "" {
		return "", errors.New("a deploy token needs ENVCLIENT_SERVER set next to it, " +
			"so the client secret is only ever sent to the server it belongs to")
	}

	if err := config.CheckServer(server); err != nil {
		return "", err
	}

	if project != nil && project.Server != "" && !config.SameServer(project.Server, server) {
		return "", fmt.Errorf("envclient.json points at %s, but the deploy token belongs to %s; "+
			"refusing to send it anywhere else", project.Server, server)
	}

	return server, nil
}

// accessToken picks the right credential for the situation: machine
// credentials from the environment when present, otherwise the token stored
// by an interactive login.
func accessToken(ctx context.Context, server string) (string, error) {
	if deployTokenSet() {
		// Requesting a scope here only says "this access token may claim to
		// have it"; ResolveDeployToken still checks it against the deploy
		// token's own scopes column before anything is actually allowed
		// (Passport itself will hand out any scope it knows to any client,
		// per DeployToken's doc comment). So it's safe, and necessary, to
		// always ask for everything a deploy token could plausibly do —
		// under-asking here is what previously turned a token that really
		// was allowed to push into a confusing "Invalid scope(s) provided."
		credentials, err := auth.ClientCredentials(
			ctx,
			server,
			os.Getenv("ENVCLIENT_CLIENT_ID"),
			os.Getenv("ENVCLIENT_CLIENT_SECRET"),
			strings.Fields(envOr("ENVCLIENT_SCOPES", "env:read env:write")),
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
