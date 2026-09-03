package main

import (
	"fmt"
	"os"
	"path/filepath"
	"sort"

	"github.com/spf13/cobra"

	"github.com/I-247/envserver/cli/internal/envfile"
	"github.com/I-247/envserver/cli/internal/ui"
)

// driftKind says how a local entry and the release disagree.
type driftKind int

const (
	// driftMissing: the release has the key, the file does not. A deploy
	// from this file would start a process without it.
	driftMissing driftKind = iota
	// driftChanged: both have the key, with different values.
	driftChanged
	// driftExtra: only the file has it. Left out of the verdict by default,
	// for the same reason "envclient pull" never prunes without being asked: a
	// machine specific entry is not a mistake.
	driftExtra
)

// drift is one disagreement between a working directory and a release.
type drift struct {
	Key  string
	Kind driftKind
}

// checkFailed reports that a check ran fine and did not like what it found.
//
// It carries its own exit code so a CI step can tell "this file is out of
// date" apart from "the server was unreachable", and it prints nothing extra:
// the report is already on screen by the time it is returned.
type checkFailed struct {
	failures int
}

func (e checkFailed) Error() string {
	return fmt.Sprintf("%d difference(s) between the file and the release", e.failures)
}

// ExitCode is what the process exits with. Reserved values: 1 is any ordinary
// failure, so drift takes 2.
func (e checkFailed) ExitCode() int { return 2 }

// compareWithRelease lists every disagreement, sorted by key so two runs of
// the same check read the same way.
func compareWithRelease(release, local map[string]string) []drift {
	keys := map[string]bool{}

	for key := range local {
		keys[key] = true
	}

	for key := range release {
		keys[key] = true
	}

	sorted := make([]string, 0, len(keys))
	for key := range keys {
		sorted = append(sorted, key)
	}
	sort.Strings(sorted)

	drifts := make([]drift, 0, len(sorted))

	for _, key := range sorted {
		remote, onServer := release[key]
		mine, isLocal := local[key]

		switch {
		case onServer && !isLocal:
			drifts = append(drifts, drift{Key: key, Kind: driftMissing})
		case isLocal && !onServer:
			drifts = append(drifts, drift{Key: key, Kind: driftExtra})
		case mine != remote:
			drifts = append(drifts, drift{Key: key, Kind: driftChanged})
		}
	}

	return drifts
}

// countFailures says how many of the differences the check refuses to pass on.
func countFailures(drifts []drift, strict bool) int {
	failures := 0

	for _, d := range drifts {
		if d.Kind != driftExtra || strict {
			failures++
		}
	}

	return failures
}

// describeDrifts renders the differences the way "envclient diff" renders them.
func describeDrifts(drifts []drift, strict bool) []ui.Change {
	changes := make([]ui.Change, 0, len(drifts))

	for _, d := range drifts {
		switch d.Kind {
		case driftMissing:
			changes = append(changes, ui.Change{Mark: "+", Key: d.Key, Detail: "in the release, not in your file"})
		case driftChanged:
			changes = append(changes, ui.Change{Mark: "~", Key: d.Key, Detail: "differs from the release"})
		case driftExtra:
			detail := "only in your file, ignored"
			if strict {
				detail = "only in your file"
			}

			changes = append(changes, ui.Change{Mark: "-", Key: d.Key, Detail: detail})
		}
	}

	return changes
}

func checkCommand() *cobra.Command {
	var (
		file   string
		strict bool
	)

	cmd := &cobra.Command{
		Use:   "check",
		Short: "Fail when your .env does not match the latest release",
		Long: "Reads the latest release and compares it with your file, like \"envclient\n" +
			"diff\", but exits 2 when they disagree so a pipeline can stop on it.\n\n" +
			"Keys that exist only in your file are reported and then ignored: your\n" +
			"machine specific entries are yours. Pass --strict to count those too.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, err := openSession(cmd.Context())
			if err != nil {
				return err
			}

			release, err := fetchRelease(cmd, s, 0)
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

			drifts := compareWithRelease(release.Variables, envfile.Parse(string(contents)).Values())
			failures := countFailures(drifts, strict)

			p := printer(cmd)
			p.Title("Release %d  %s  vs  %s",
				release.Version, p.Dim(release.Project+"/"+release.Environment), p.Path(path))

			if len(drifts) > 0 {
				p.Changes(describeDrifts(drifts, strict))
			}

			if failures == 0 {
				p.Done("Your file matches the release  %s", p.Dim(fmt.Sprintf("· %d keys", len(release.Variables))))

				return nil
			}

			p.Warn("%s", p.Count(failures, "difference(s)"))

			return checkFailed{failures: failures}
		},
	}

	cmd.Flags().StringVarP(&file, "file", "f", "", "the file to check (default: .env next to envclient.json)")
	cmd.Flags().BoolVar(&strict, "strict", false, "also fail on keys that exist only in your file")

	return cmd
}
