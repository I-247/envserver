package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"syscall"

	"github.com/spf13/cobra"

	"github.com/I-247/envserver/cli/internal/api"
	"github.com/I-247/envserver/cli/internal/config"
	"github.com/I-247/envserver/cli/internal/envfile"
	"github.com/I-247/envserver/cli/internal/ui"
	"github.com/I-247/envserver/cli/internal/vault"
)

func initCommand() *cobra.Command {
	project := &config.Project{}

	cmd := &cobra.Command{
		Use:   "init",
		Short: "Link this directory to an environment",
		Long: "Writes a envclient.json naming the server, team, project and environment.\n" +
			"Commit it: it holds no secrets, and it lets your colleagues just run\n" +
			"\"envclient pull\".",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			for name, value := range map[string]*string{
				"--server": &project.Server, "--team": &project.Team,
				"--project": &project.Name, "--environment": &project.Environment,
			} {
				if strings.TrimSpace(*value) == "" {
					return fmt.Errorf("%s is required", name)
				}
			}

			dir := mustGetwd()

			if err := project.Save(dir); err != nil {
				return err
			}

			p := printer(cmd)

			p.Done("Linked to %s", p.Bold(project.Team+"/"+project.Name+"/"+project.Environment))
			p.Note("Wrote %s — commit it, it holds no secrets.", filepath.Join(dir, config.ProjectFileName))
			p.Note("Add .env and %s to your .gitignore if they are not there yet.", vault.FileName)

			return nil
		},
	}

	cmd.Flags().StringVar(&project.Server, "server", "", "the Envserver server URL")
	cmd.Flags().StringVar(&project.Team, "team", "", "the team slug")
	cmd.Flags().StringVar(&project.Name, "project", "", "the project slug")
	cmd.Flags().StringVar(&project.Environment, "environment", "development", "the environment slug")

	return cmd
}

func pullCommand() *cobra.Command {
	var (
		out          string
		version      int
		constructive bool
		force        bool
		dryRun       bool
		prune        bool
	)

	cmd := &cobra.Command{
		Use:   "pull",
		Short: "Write the published variables into your .env",
		Long: "By default only keys already present in your .env are updated, and\n" +
			"nothing is ever removed. Pass --constructive to add keys you do not\n" +
			"have yet, or --prune to drop keys the release no longer has.\n\n" +
			"You are shown what would change and asked to confirm before\n" +
			"anything is written. Pass --force to skip the question, which is\n" +
			"what a deploy script wants, or --dry-run to only ever look.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			release, err := fetchRelease(cmd, s, version)
			if err != nil {
				return err
			}

			path := out
			if path == "" {
				path = filepath.Join(s.project.Dir(), ".env")
			}

			existing, err := os.ReadFile(path)
			if err != nil && !os.IsNotExist(err) {
				return err
			}

			// An absent .env with a conservative pull would silently write
			// nothing at all, which reads as success and is not. Say so.
			if os.IsNotExist(err) && !constructive && !force {
				return fmt.Errorf(
					"%s does not exist yet, so there are no keys to update.\n"+
						"Run with --constructive to create it from the release", path)
			}

			p := printer(cmd)
			file := envfile.Parse(string(existing))
			options := envfile.MergeOptions{Constructive: constructive, Prune: prune}

			if dryRun {
				printPullResult(p, release, path, envfile.Plan(file, release.Variables, options), options, pullDryRun)

				return nil
			}

			if !force {
				approved, err := confirmPull(cmd, p, release, path, file, options)
				if err != nil || !approved {
					return err
				}
			}

			result := file.Merge(release.Variables, options)

			if err := os.WriteFile(path, []byte(file.String()), 0o600); err != nil {
				return err
			}

			printPullResult(p, release, path, result, options, pullApplied)

			return nil
		},
	}

	cmd.Flags().StringVarP(&out, "out", "o", "", "the file to write (default: .env next to envclient.json)")
	cmd.Flags().IntVar(&version, "release", 0, "pull a specific release instead of the latest")
	cmd.Flags().BoolVar(&constructive, "constructive", false, "also add keys that are not in your file yet")
	cmd.Flags().BoolVar(&force, "force", false, "apply without asking, and write even when the target file does not exist")
	cmd.Flags().BoolVar(&dryRun, "dry-run", false, "report what would change and write nothing")
	cmd.Flags().BoolVar(&prune, "prune", false, "also delete keys your file has and the release does not")

	return cmd
}

// pullMode is why a merge is being printed.
type pullMode int

const (
	// pullApplied is the report of a pull that has just been written.
	pullApplied pullMode = iota
	// pullDryRun is --dry-run: a look, and nothing more will happen.
	pullDryRun
	// pullPreview is the same look, but a question follows it.
	pullPreview
)

// printPullResult reports a merge, whether or not it was written.
//
// One renderer for all three so the preview cannot describe a pull that
// differs from the one you get; only the tense and the closing line change.
func printPullResult(p *ui.Printer, release *api.Release, path string, result envfile.MergeResult, options envfile.MergeOptions, mode pullMode) {
	dryRun := mode != pullApplied

	heading := fmt.Sprintf("Release %d  %s", release.Version, p.Dim(release.Project+"/"+release.Environment))
	if mode == pullDryRun {
		heading += p.Dim("  (dry run)")
	}

	p.Title("%s", heading)

	changes := make([]ui.Change, 0, len(result.Changes))

	// Two spellings per kind rather than one rewritten on the fly: a dry run
	// that says "would be" in front of a past tense reads like a bug report.
	details := map[envfile.ChangeKind][2]string{
		envfile.KindUpdated: {"updated", "would be updated"},
		envfile.KindAdded:   {"added", "would be added"},
		envfile.KindRemoved: {"removed, not in the release", "would be removed, not in the release"},
		envfile.KindSkipped: {"left out, only on the server", "would be left out, only on the server"},
	}

	marks := map[envfile.ChangeKind]string{
		envfile.KindUpdated: "~",
		envfile.KindAdded:   "+",
		envfile.KindRemoved: "-",
		envfile.KindSkipped: ".",
	}

	for _, change := range result.Changes {
		if change.Kind == envfile.KindUnchanged {
			continue
		}

		detail := details[change.Kind][0]
		if dryRun {
			detail = details[change.Kind][1]
		}

		changes = append(changes, ui.Change{Mark: marks[change.Kind], Key: change.Key, Detail: detail})
	}

	p.Changes(changes)

	summary := strings.Join([]string{
		p.Count(result.Updated, "updated"),
		p.Count(result.Added, "added"),
		p.Count(result.Removed, "removed"),
		p.Count(result.Unchanged, "unchanged"),
	}, p.Dim(" · "))

	switch mode {
	case pullApplied:
		p.Done("%s  %s", p.Path(path), summary)
	case pullDryRun:
		p.Info("Nothing was written  %s", p.Dim("·")+" "+summary)
	case pullPreview:
		p.Info("%s  %s", p.Path(path), summary)
	}

	if result.Skipped > 0 && !options.Constructive {
		p.Warn("%d only on the server; add them with %s", result.Skipped, p.Bold("--constructive"))
	}
}

// confirmPull shows what a pull would do and asks whether to do it.
//
// A pull rewrites a file that is often the only copy of something, so the
// default is to look before leaping. It returns quietly when there is nothing
// to decide: a question with one sensible answer is just noise.
func confirmPull(cmd *cobra.Command, p *ui.Printer, release *api.Release, path string, file *envfile.File, options envfile.MergeOptions) (bool, error) {
	plan := envfile.Plan(file, release.Variables, options)

	if plan.Updated+plan.Added+plan.Removed == 0 {
		printPullResult(p, release, path, plan, options, pullDryRun)
		p.Done("Your file already matches the release.")

		return false, nil
	}

	printPullResult(p, release, path, plan, options, pullPreview)

	// No terminal means no answer is coming. Saying so beats hanging a
	// deploy on a prompt nobody will ever see, and beats assuming yes.
	if !ui.Interactive(cmd.InOrStdin()) {
		return false, errors.New(
			"this pull needs confirmation and there is no terminal to ask at.\n" +
				"Pass --force to apply it, or --dry-run to only look")
	}

	approved, err := p.Confirm(cmd.InOrStdin(), "Apply this to %s?", p.Path(path))
	if err != nil {
		return false, err
	}

	if !approved {
		p.Info("Left alone. Nothing was written.")
	}

	return approved, nil
}

func pushCommand() *cobra.Command {
	var (
		file    string
		publish bool
		message string
	)

	cmd := &cobra.Command{
		Use:   "push",
		Short: "Send the values in your .env to the server",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			// Most deploy tokens are read only by design, and the server
			// enforces that on its own — a token without env:write gets a
			// 403 the moment fetchPush below reaches it. --publish stays
			// developer-only regardless: there is no deploy-scoped route
			// for it, so a clear local error beats a confusing remote one.
			if deployTokenSet() && publish {
				return fmt.Errorf(
					"--publish needs a personal login, even with a deploy token that can push.\n" +
						"Push without --publish, then publish from the portal, or run \"envclient login\" to do both from here")
			}

			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			path := file
			if path == "" {
				path = filepath.Join(s.project.Dir(), ".env")
			}

			contents, err := os.ReadFile(path)
			if err != nil {
				return err
			}

			values := envfile.Parse(string(contents)).Values()
			if len(values) == 0 {
				return fmt.Errorf("%s holds no variables", path)
			}

			result, err := pushVariables(cmd, s, values)
			if err != nil {
				return err
			}

			p := printer(cmd)

			p.Done("Pushed %s  %s", p.Path(path), strings.Join([]string{
				p.Count(result.Created, "created"),
				p.Count(result.Updated, "updated"),
				p.Count(result.Unchanged, "unchanged"),
			}, p.Dim(" · ")))

			// Worth interrupting for: a shared variable reaching another
			// environment is the one thing a push does that you cannot see
			// from the file you pushed.
			if len(result.SharedImpact) > 0 {
				p.Warn("Shared variables you changed also reach:")

				for _, reached := range result.SharedImpact {
					p.Note("%s", reached)
				}
			}

			if !publish {
				p.Note("Not published yet — add --publish, or publish from the portal.")

				return nil
			}

			release, err := s.client.Publish(cmd.Context(), s.target, message)
			if err != nil {
				return err
			}

			p.Done("Published release %s", p.Bold(fmt.Sprintf("%d", release.Version)))

			return nil
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "the file to read (default: .env next to envclient.json)")
	cmd.Flags().BoolVar(&publish, "publish", false, "publish a release straight after pushing")
	cmd.Flags().StringVarP(&message, "message", "m", "", "message for the published release")

	return cmd
}

func diffCommand() *cobra.Command {
	var file string

	cmd := &cobra.Command{
		Use:   "diff",
		Short: "Compare your .env with the latest release",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			release, err := s.client.Release(cmd.Context(), s.target, 0)
			if err != nil {
				return err
			}

			path := file
			if path == "" {
				path = filepath.Join(s.project.Dir(), ".env")
			}

			contents, err := os.ReadFile(path)
			if err != nil && !os.IsNotExist(err) {
				return err
			}

			local := envfile.Parse(string(contents)).Values()

			keys := map[string]bool{}
			for key := range local {
				keys[key] = true
			}
			for key := range release.Variables {
				keys[key] = true
			}

			sorted := make([]string, 0, len(keys))
			for key := range keys {
				sorted = append(sorted, key)
			}
			sort.Strings(sorted)

			p := printer(cmd)
			changes := make([]ui.Change, 0, len(sorted))

			for _, key := range sorted {
				remote, onServer := release.Variables[key]
				mine, isLocal := local[key]

				switch {
				case onServer && !isLocal:
					changes = append(changes, ui.Change{Mark: "+", Key: key, Detail: "only on the server"})
				case isLocal && !onServer:
					changes = append(changes, ui.Change{Mark: "-", Key: key, Detail: "only in your file"})
				case mine != remote:
					changes = append(changes, ui.Change{Mark: "~", Key: key, Detail: "differs"})
				}
			}

			p.Title("Release %d  %s  vs  %s",
				release.Version, p.Dim(release.Project+"/"+release.Environment), p.Path(path))

			if len(changes) == 0 {
				p.Done("Your file matches the release  %s", p.Dim(fmt.Sprintf("· %d keys", len(release.Variables))))

				return nil
			}

			p.Changes(changes)
			p.Info("%s", p.Count(len(changes), "difference(s)"))

			return nil
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "the file to compare (default: .env next to envclient.json)")

	return cmd
}

func listCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "list",
		Short: "List the projects and environments you can reach",
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

			if len(projects) == 0 {
				p.Warn("You cannot reach any project on this server yet.")

				return nil
			}

			rows := make([][]string, 0, len(projects))

			for _, project := range projects {
				names := make([]string, 0, len(project.Environments))
				for _, environment := range project.Environments {
					names = append(names, environment.Slug)
				}

				rows = append(rows, []string{
					project.Team + "/" + project.Slug,
					strings.Join(names, ", "),
				})
			}

			p.Title("%s", p.Count(len(projects), "project(s)"))
			p.Table([]string{"PROJECT", "ENVIRONMENTS"}, rows)

			return nil
		},
	}
}

func historyCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "history",
		Short: "Show the release history of this environment",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			releases, err := s.client.Releases(cmd.Context(), s.target)
			if err != nil {
				return err
			}

			p := printer(cmd)

			if len(releases) == 0 {
				p.Warn("No release has been published for this environment yet.")

				return nil
			}

			rows := make([][]string, 0, len(releases))

			for _, release := range releases {
				rows = append(rows, []string{
					"#" + fmt.Sprintf("%d", release.Version),
					release.PublishedAt,
					fmt.Sprintf("%d", release.VariablesCount),
					release.PublishedBy,
					release.Message,
				})
			}

			p.Title("Release history  %s", p.Dim(s.target.Project+"/"+s.target.Environment))
			p.Table([]string{"RELEASE", "PUBLISHED", "VARS", "BY", "MESSAGE"}, rows)

			return nil
		},
	}
}

func runCommand() *cobra.Command {
	var (
		version   int
		file      string
		useVault  bool
		useRemote bool
	)

	cmd := &cobra.Command{
		Use:   "run -- <command> [args...]",
		Short: "Run a command with the variables injected, without writing a file",
		Long: "Hands the variables to the child process directly, so nothing lands\n" +
			"on disk in plain text. That is the safer way to run deploy steps\n" +
			"like migrations.\n\n" +
			"When a sealed " + vault.FileName + " is present it is used, which needs neither\n" +
			"the network nor a live token. Otherwise the release is fetched.",
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if useVault && useRemote {
				return errors.New("--vault and --remote ask for opposite things")
			}

			variables, err := runVariables(cmd, version, file, useVault, useRemote)
			if err != nil {
				return err
			}

			binary, err := exec.LookPath(args[0])
			if err != nil {
				return err
			}

			// Replace this process rather than wrapping it, so signals, the
			// exit code and the terminal all behave as if envclient was never here.
			return syscall.Exec(binary, args, childEnvironment(variables))
		},
	}

	cmd.Flags().IntVar(&version, "release", 0, "use a specific release instead of the latest")
	cmd.Flags().StringVarP(&file, "file", "f", "", "the sealed file to read (default: "+vault.FileName+" next to envclient.json)")
	cmd.Flags().BoolVar(&useVault, "vault", false, "insist on the local sealed file and never reach the server")
	cmd.Flags().BoolVar(&useRemote, "remote", false, "insist on the server even when a sealed file exists")

	return cmd
}

// runVariables decides where the values come from.
//
// The sealed file wins by default because it is the cheaper and more
// available of the two: no round trip, no token that may have expired. A
// --release always means the server, since a vault holds exactly one.
func runVariables(cmd *cobra.Command, version int, file string, useVault, useRemote bool) (map[string]string, error) {
	sealed := useVault
	if !useVault && !useRemote && version == 0 {
		_, err := os.Stat(vaultPath(file))
		sealed = err == nil
	}

	if sealed {
		payload, path, err := openVault(file)
		if err != nil {
			return nil, err
		}

		p := printer(cmd)
		fmt.Fprintf(p.Err(), "%s\n", p.Dim(fmt.Sprintf("Using release %d from %s", payload.Release, path)))

		return payload.Variables, nil
	}

	s, err := openSession(cmd.Context())
	if err != nil {
		return nil, err
	}

	release, err := fetchRelease(cmd, s, version)
	if err != nil {
		return nil, err
	}

	return release.Variables, nil
}

// childEnvironment merges the variables over the environment envclient was
// started with.
//
// Merged by key rather than appended: a duplicate entry in an environment
// block is resolved by libc taking the first one, so appending would let a
// stale value in the shell quietly beat the release.
func childEnvironment(variables map[string]string) []string {
	merged := make([]string, 0, len(os.Environ())+len(variables))

	for _, entry := range os.Environ() {
		key, _, found := strings.Cut(entry, "=")
		if found {
			if _, overridden := variables[key]; overridden {
				continue
			}
		}

		merged = append(merged, entry)
	}

	for key, value := range variables {
		merged = append(merged, key+"="+value)
	}

	return merged
}

// fetchRelease reads a release through whichever credential is in play.
func fetchRelease(cmd *cobra.Command, s *session, version int) (*api.Release, error) {
	if deployTokenSet() {
		return s.client.DeployRelease(cmd.Context(), version)
	}

	return s.client.Release(cmd.Context(), s.target, version)
}

// pushVariables sends values through whichever credential is in play. A
// deploy token's session carries no team/project/environment (it never had
// one to resolve), so it must go through the deploy-scoped endpoint that
// identifies the environment from the token itself; the server rejects it
// with a 403 unless that token was granted env:write.
func pushVariables(cmd *cobra.Command, s *session, variables map[string]string) (*api.PushResult, error) {
	if deployTokenSet() {
		return s.client.DeployPush(cmd.Context(), variables)
	}

	return s.client.Push(cmd.Context(), s.target, variables)
}
