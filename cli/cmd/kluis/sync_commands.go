package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"syscall"

	"github.com/spf13/cobra"

	"github.com/sebastiaankloos/kluis/cli/internal/api"
	"github.com/sebastiaankloos/kluis/cli/internal/config"
	"github.com/sebastiaankloos/kluis/cli/internal/envfile"
)

func initCommand() *cobra.Command {
	project := &config.Project{}

	cmd := &cobra.Command{
		Use:   "init",
		Short: "Link this directory to an environment",
		Long: "Writes a kluis.json naming the server, team, project and environment.\n" +
			"Commit it: it holds no secrets, and it lets your colleagues just run\n" +
			"\"kluis pull\".",
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

			fmt.Fprintf(cmd.OutOrStdout(),
				"Wrote %s.\nCommit it, and add .env to your .gitignore if it is not there yet.\n",
				filepath.Join(dir, config.ProjectFileName))

			return nil
		},
	}

	cmd.Flags().StringVar(&project.Server, "server", "", "the Kluis server URL")
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
	)

	cmd := &cobra.Command{
		Use:   "pull",
		Short: "Write the published variables into your .env",
		Long: "By default only keys already present in your .env are updated, and\n" +
			"nothing is ever removed. Pass --constructive to add keys you do not\n" +
			"have yet.",
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

			file := envfile.Parse(string(existing))
			result := file.Merge(release.Variables, constructive)

			if err := os.WriteFile(path, []byte(file.String()), 0o600); err != nil {
				return err
			}

			fmt.Fprintf(cmd.OutOrStdout(),
				"Release %d of %s/%s written to %s\n  %d updated, %d added, %d unchanged",
				release.Version, release.Project, release.Environment, path,
				result.Updated, result.Added, result.Unchanged)

			if result.Skipped > 0 {
				fmt.Fprintf(cmd.OutOrStdout(),
					"\n  %d key(s) exist on the server but not in your file; use --constructive to add them",
					result.Skipped)
			}

			fmt.Fprintln(cmd.OutOrStdout())

			return nil
		},
	}

	cmd.Flags().StringVarP(&out, "out", "o", "", "the file to write (default: .env next to kluis.json)")
	cmd.Flags().IntVar(&version, "release", 0, "pull a specific release instead of the latest")
	cmd.Flags().BoolVar(&constructive, "constructive", false, "also add keys that are not in your file yet")
	cmd.Flags().BoolVar(&force, "force", false, "write even when the target file does not exist")

	return cmd
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

			result, err := s.client.Push(cmd.Context(), s.target, values)
			if err != nil {
				return err
			}

			fmt.Fprintf(cmd.OutOrStdout(), "Pushed %s: %d created, %d updated, %d unchanged\n",
				path, result.Created, result.Updated, result.Unchanged)

			if len(result.SharedImpact) > 0 {
				fmt.Fprintf(cmd.OutOrStdout(),
					"\nShared variables you changed also reach:\n  %s\n",
					strings.Join(result.SharedImpact, "\n  "))
			}

			if !publish {
				return nil
			}

			release, err := s.client.Publish(cmd.Context(), s.target, message)
			if err != nil {
				return err
			}

			fmt.Fprintf(cmd.OutOrStdout(), "\nPublished release %d.\n", release.Version)

			return nil
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "the file to read (default: .env next to kluis.json)")
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

			differences := 0

			for _, key := range sorted {
				remote, onServer := release.Variables[key]
				mine, isLocal := local[key]

				switch {
				case onServer && !isLocal:
					fmt.Fprintf(cmd.OutOrStdout(), "  + %s (only on the server)\n", key)
				case isLocal && !onServer:
					fmt.Fprintf(cmd.OutOrStdout(), "  - %s (only in your file)\n", key)
				case mine != remote:
					fmt.Fprintf(cmd.OutOrStdout(), "  ~ %s\n", key)
				default:
					continue
				}

				differences++
			}

			if differences == 0 {
				fmt.Fprintf(cmd.OutOrStdout(), "Your file matches release %d.\n", release.Version)
			}

			return nil
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "the file to compare (default: .env next to kluis.json)")

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

			for _, project := range projects {
				names := make([]string, 0, len(project.Environments))
				for _, environment := range project.Environments {
					names = append(names, environment.Slug)
				}

				fmt.Fprintf(cmd.OutOrStdout(), "%s/%s  %s\n",
					project.Team, project.Slug, strings.Join(names, ", "))
			}

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

			for _, release := range releases {
				fmt.Fprintf(cmd.OutOrStdout(), "%4d  %s  %2d vars  %s %s\n",
					release.Version, release.PublishedAt, release.VariablesCount,
					release.PublishedBy, release.Message)
			}

			return nil
		},
	}
}

func runCommand() *cobra.Command {
	var version int

	cmd := &cobra.Command{
		Use:   "run -- <command> [args...]",
		Short: "Run a command with the variables injected, without writing a file",
		Long: "Fetches the release and hands the variables to the child process\n" +
			"directly. Nothing touches the disk, which is the safer way to run\n" +
			"deploy steps like migrations.",
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			release, err := fetchRelease(cmd, s, version)
			if err != nil {
				return err
			}

			binary, err := exec.LookPath(args[0])
			if err != nil {
				return err
			}

			environment := os.Environ()
			for key, value := range release.Variables {
				environment = append(environment, key+"="+value)
			}

			// Replace this process rather than wrapping it, so signals, the
			// exit code and the terminal all behave as if kluis was never here.
			return syscall.Exec(binary, args, environment)
		},
	}

	cmd.Flags().IntVar(&version, "release", 0, "use a specific release instead of the latest")

	return cmd
}

// fetchRelease reads a release through whichever credential is in play.
func fetchRelease(cmd *cobra.Command, s *session, version int) (*api.Release, error) {
	if deployTokenSet() {
		return s.client.DeployRelease(cmd.Context(), version)
	}

	return s.client.Release(cmd.Context(), s.target, version)
}
