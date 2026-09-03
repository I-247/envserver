// Command envclient syncs environment variables between an Envserver server and a
// working directory.
package main

import (
	"errors"
	"os"

	"github.com/I-247/envserver/cli/internal/ui"
)

// version is set at build time by GoReleaser.
var version = "dev"

func main() {
	if err := rootCommand().Execute(); err != nil {
		// A command that already reported its own outcome carries the exit
		// code it wants and nothing more to say. "envclient check" is the one
		// doing that today: printing an error under its own report would
		// read as a second, different problem.
		var coded interface{ ExitCode() int }
		if errors.As(err, &coded) {
			os.Exit(coded.ExitCode())
		}

		ui.New(os.Stdout, os.Stderr).Error("%s", err)
		os.Exit(1)
	}
}
