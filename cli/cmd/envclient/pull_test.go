package main

import (
	"bytes"
	"strings"
	"testing"

	"github.com/spf13/cobra"

	"github.com/I-247/envserver/cli/internal/api"
	"github.com/I-247/envserver/cli/internal/envfile"
	"github.com/I-247/envserver/cli/internal/ui"
)

// pullFixture builds a command whose streams are buffers, which also makes it
// non interactive: a bytes.Buffer is not a terminal.
func pullFixture(stdin string) (*cobra.Command, *ui.Printer, *bytes.Buffer, *api.Release, *envfile.File) {
	out := &bytes.Buffer{}

	cmd := &cobra.Command{}
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SetOut(out)
	cmd.SetErr(out)

	release := &api.Release{
		Version: 12, Project: "acme/webshop", Environment: "development",
		Variables: map[string]string{"APP_ENV": "production", "MAIL_MAILER": "ses"},
	}

	return cmd, ui.New(out, out), out, release, envfile.Parse("APP_ENV=local\nOLD_ITEM=bye\n")
}

func TestConfirmPullRefusesToGuessWithoutATerminal(t *testing.T) {
	cmd, p, out, release, file := pullFixture("y\n")

	approved, err := confirmPull(cmd, p, release, ".env", file, envfile.MergeOptions{Constructive: true})

	if approved {
		t.Fatal("approved a pull nobody could have answered")
	}

	if err == nil || !strings.Contains(err.Error(), "--force") {
		t.Fatalf("error does not point at --force: %v", err)
	}

	// The preview is still worth printing: it is why the error makes sense.
	if !strings.Contains(out.String(), "would be updated") {
		t.Errorf("no preview before the refusal:\n%s", out.String())
	}
}

func TestConfirmPullDoesNotAskWhenNothingWouldChange(t *testing.T) {
	cmd, p, out, release, _ := pullFixture("")

	file := envfile.Parse("APP_ENV=production\n")

	approved, err := confirmPull(cmd, p, release, ".env", file, envfile.MergeOptions{})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if approved {
		t.Error("asked to write a file that would not change")
	}

	if !strings.Contains(out.String(), "already matches") {
		t.Errorf("did not say why it stopped:\n%s", out.String())
	}
}

func TestConfirmTakesYesAndNothingElse(t *testing.T) {
	for answer, want := range map[string]bool{
		"y\n": true, "Y\n": true, "yes\n": true, " y \n": true,
		"n\n": false, "\n": false, "": false, "yolo\n": false,
	} {
		out := &bytes.Buffer{}

		approved, _ := ui.New(out, out).Confirm(strings.NewReader(answer), "Apply?")
		if approved != want {
			t.Errorf("answer %q gave %v, want %v", answer, approved, want)
		}
	}
}

func TestPreviewAndDryRunDescribeTheSamePull(t *testing.T) {
	_, p, out, release, file := pullFixture("")

	options := envfile.MergeOptions{Constructive: true, Prune: true}
	plan := envfile.Plan(file, release.Variables, options)

	printPullResult(p, release, ".env", plan, options, pullDryRun)
	dry := out.String()

	out.Reset()
	printPullResult(p, release, ".env", plan, options, pullPreview)
	preview := out.String()

	for _, line := range []string{"~ APP_ENV", "+ MAIL_MAILER", "- OLD_ITEM"} {
		if !strings.Contains(dry, line) || !strings.Contains(preview, line) {
			t.Errorf("%q is missing from one of the two renderings", line)
		}
	}
}
