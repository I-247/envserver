package main

import (
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"testing"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// The envclient reference is written out by hand in two places. These tests
// hold both against the command tree itself, so a new command, flag or
// ENVCLIENT_* variable cannot ship without its documentation.
const (
	readmePath = "../../README.md"
	portalPath = "../../../resources/js/pages/docs/cli.tsx"

	// portalName is how failures name the portal page: from the repository root.
	portalName = "resources/js/pages/docs/cli.tsx"
)

// documentedCommands are the commands a user can run: cobra's own help and
// completion commands are left to cobra.
func documentedCommands(root *cobra.Command) []*cobra.Command {
	var commands []*cobra.Command

	for _, cmd := range root.Commands() {
		if cmd.Name() == "help" || cmd.Name() == "completion" {
			continue
		}

		commands = append(commands, cmd)
	}

	return commands
}

// documentedFlags are the flags a command defines itself; --help and
// --version are added by cobra.
func documentedFlags(flags *pflag.FlagSet) []*pflag.Flag {
	var documented []*pflag.Flag

	flags.VisitAll(func(flag *pflag.Flag) {
		if flag.Name != "help" && flag.Name != "version" {
			documented = append(documented, flag)
		}
	})

	return documented
}

func readDoc(t *testing.T, path string) string {
	t.Helper()

	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading %s: %v", path, err)
	}

	return string(contents)
}

// between returns the text from start up to the first end after it.
func between(t *testing.T, doc, path, start, end string) string {
	t.Helper()

	from := strings.Index(doc, start)
	if from == -1 {
		t.Fatalf("%s no longer contains %q", path, start)
	}

	rest := doc[from+len(start):]
	if to := strings.Index(rest, end); to != -1 {
		return rest[:to]
	}

	return rest
}

// readmeCommandRows maps each command in the README's Commands table to its row.
func readmeCommandRows(t *testing.T) map[string]string {
	t.Helper()

	table := between(t, readDoc(t, readmePath), readmePath, "\n## Commands\n", "\n## ")
	row := regexp.MustCompile("(?m)^\\| `([a-z-]+)[^|]*\\|.*$")
	rows := map[string]string{}

	for _, match := range row.FindAllStringSubmatch(table, -1) {
		rows[match[1]] = match[0]
	}

	return rows
}

// portalCommandBlocks maps each command in the portal page's commands list to
// its entry, from its usage line up to the next one.
func portalCommandBlocks(t *testing.T) map[string]string {
	t.Helper()

	list := between(t, readDoc(t, portalPath), portalPath, "const commands: Command[] = [", "\n];")
	usage := regexp.MustCompile(`usage: 'envclient ([a-z-]+)`)
	starts := usage.FindAllStringSubmatchIndex(list, -1)
	blocks := map[string]string{}

	for i, start := range starts {
		end := len(list)
		if i+1 < len(starts) {
			end = starts[i+1][0]
		}

		blocks[list[start[2]:start[3]]] = list[start[0]:end]
	}

	return blocks
}

// readmeFlag is how the README writes a flag: `--out`, or `-o/--out`.
func readmeFlag(flag *pflag.Flag) string {
	if flag.Shorthand != "" {
		return fmt.Sprintf("`-%s/--%s`", flag.Shorthand, flag.Name)
	}

	return fmt.Sprintf("`--%s`", flag.Name)
}

// portalFlag is how the portal page writes a flag: '--out', or '-o, --out'.
func portalFlag(flag *pflag.Flag) string {
	if flag.Shorthand != "" {
		return fmt.Sprintf("name: '-%s, --%s'", flag.Shorthand, flag.Name)
	}

	return fmt.Sprintf("name: '--%s'", flag.Name)
}

func TestEveryCommandAndFlagIsInTheReadme(t *testing.T) {
	rows := readmeCommandRows(t)

	for _, cmd := range documentedCommands(rootCommand()) {
		row, found := rows[cmd.Name()]
		if !found {
			t.Errorf("command %q is missing from the Commands table in cli/README.md", cmd.Name())
			continue
		}

		for _, flag := range documentedFlags(cmd.LocalNonPersistentFlags()) {
			if !strings.Contains(row, readmeFlag(flag)) {
				t.Errorf("%s for %q is missing from its row in cli/README.md", readmeFlag(flag), cmd.Name())
			}
		}
	}
}

func TestEveryCommandAndFlagIsOnThePortalPage(t *testing.T) {
	blocks := portalCommandBlocks(t)

	for _, cmd := range documentedCommands(rootCommand()) {
		block, found := blocks[cmd.Name()]
		if !found {
			t.Errorf("command %q is missing from commands in %s", cmd.Name(), portalName)
			continue
		}

		for _, flag := range documentedFlags(cmd.LocalNonPersistentFlags()) {
			if !strings.Contains(block, portalFlag(flag)) {
				t.Errorf("%s for %q is missing from its entry in %s", portalFlag(flag), cmd.Name(), portalName)
			}
		}
	}
}

func TestGlobalFlagsAreInBothDocs(t *testing.T) {
	readme := readDoc(t, readmePath)
	portal := readDoc(t, portalPath)

	for _, flag := range documentedFlags(rootCommand().PersistentFlags()) {
		name := "--" + flag.Name

		if !strings.Contains(readme, "`"+name+"`") {
			t.Errorf("global flag %s is missing from cli/README.md", name)
		}

		if !strings.Contains(portal, "<Code>"+name+"</Code>") {
			t.Errorf("global flag %s is missing from %s", name, portalName)
		}
	}
}

// A command removed from the CLI should not live on in its documentation.
func TestTheDocsOnlyDescribeCommandsThatExist(t *testing.T) {
	existing := map[string]bool{}
	for _, cmd := range documentedCommands(rootCommand()) {
		existing[cmd.Name()] = true
	}

	for name := range readmeCommandRows(t) {
		if !existing[name] {
			t.Errorf("cli/README.md documents %q, which envclient no longer has", name)
		}
	}

	for name := range portalCommandBlocks(t) {
		if !existing[name] {
			t.Errorf("%s documents %q, which envclient no longer has", portalName, name)
		}
	}
}

// environmentVariablesInSource collects every ENVCLIENT_* name the CLI's own
// code mentions, tests excluded.
func environmentVariablesInSource(t *testing.T) []string {
	t.Helper()

	literal := regexp.MustCompile(`"(ENVCLIENT_[A-Z_]+)"`)
	found := map[string]bool{}

	err := filepath.WalkDir("../..", func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}

		if entry.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return nil
		}

		source, err := os.ReadFile(path)
		if err != nil {
			return err
		}

		for _, match := range literal.FindAllStringSubmatch(string(source), -1) {
			found[match[1]] = true
		}

		return nil
	})
	if err != nil {
		t.Fatalf("scanning the CLI source: %v", err)
	}

	names := make([]string, 0, len(found))
	for name := range found {
		names = append(names, name)
	}
	sort.Strings(names)

	if len(names) == 0 {
		t.Fatal("found no ENVCLIENT_* variables in the CLI source; the scan is broken")
	}

	return names
}

func TestEveryEnvironmentVariableIsInBothDocs(t *testing.T) {
	readmeTable := between(t, readDoc(t, readmePath), readmePath, "\n## Environment variables\n", "\n## ")
	portalList := between(t, readDoc(t, portalPath), portalPath, "const environmentVariables: Flag[] = [", "\n];")

	for _, name := range environmentVariablesInSource(t) {
		if !strings.Contains(readmeTable, "| `"+name+"` |") {
			t.Errorf("%s is missing from the Environment variables table in cli/README.md", name)
		}

		if !strings.Contains(portalList, "name: '"+name+"'") {
			t.Errorf("%s is missing from environmentVariables in %s", name, portalName)
		}
	}
}
