package ui

import (
	"bytes"
	"os"
	"strings"
	"testing"
)

func TestNoEscapeCodesWhenNotATerminal(t *testing.T) {
	var out bytes.Buffer

	p := New(&out, &out)

	p.Title("Release %d", 12)
	p.Done("written")
	p.Warn("careful")
	p.Changes([]Change{{Mark: "+", Key: "APP_ENV", Detail: "added"}})

	if strings.Contains(out.String(), "\x1b[") {
		t.Errorf("a buffer got escape codes:\n%q", out.String())
	}
}

func TestNoColorEnvironmentWins(t *testing.T) {
	t.Setenv("CLICOLOR_FORCE", "1")
	t.Setenv("NO_COLOR", "1")

	if colourWanted(os.Stdout) {
		t.Error("NO_COLOR did not win from CLICOLOR_FORCE")
	}
}

func TestForcedColourSurvivesAPipe(t *testing.T) {
	t.Setenv("NO_COLOR", "")
	t.Setenv("TERM", "xterm")
	t.Setenv("CLICOLOR_FORCE", "1")

	var out bytes.Buffer

	p := New(&out, &out)
	p.Done("written")

	if !strings.Contains(out.String(), "\x1b[") {
		t.Errorf("CLICOLOR_FORCE was ignored:\n%q", out.String())
	}
}

func TestPlainNeverColours(t *testing.T) {
	t.Setenv("CLICOLOR_FORCE", "1")

	var out bytes.Buffer

	p := New(&out, &out).Plain()
	if got := p.Bold("APP_KEY=secret"); got != "APP_KEY=secret" {
		t.Errorf("Plain styled data: %q", got)
	}
}

func TestChangesAlignOnTheLongestKey(t *testing.T) {
	var out bytes.Buffer

	New(&out, &out).Changes([]Change{
		{Mark: "+", Key: "A", Detail: "added"},
		{Mark: "~", Key: "LONGER_KEY", Detail: "updated"},
	})

	lines := strings.Split(strings.TrimRight(out.String(), "\n"), "\n")

	if strings.Index(lines[0], "added") != strings.Index(lines[1], "updated") {
		t.Errorf("details are not aligned:\n%s", out.String())
	}
}

func TestTableAlignsColumnsAndTrimsTrailingSpace(t *testing.T) {
	var out bytes.Buffer

	New(&out, &out).Table(
		[]string{"PROJECT", "ENVIRONMENTS"},
		[][]string{{"acme/webshop", "development"}, {"a/b", "staging"}},
	)

	for _, line := range strings.Split(strings.TrimRight(out.String(), "\n"), "\n") {
		if strings.HasSuffix(line, " ") {
			t.Errorf("line has trailing whitespace: %q", line)
		}
	}

	lines := strings.Split(out.String(), "\n")
	if strings.Index(lines[1], "development") != strings.Index(lines[2], "staging") {
		t.Errorf("columns are not aligned:\n%s", out.String())
	}
}
