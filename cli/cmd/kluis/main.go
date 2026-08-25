// Command kluis syncs environment variables between a Kluis server and a
// working directory.
package main

import (
	"fmt"
	"os"
)

// version is set at build time by GoReleaser.
var version = "dev"

func main() {
	if err := rootCommand().Execute(); err != nil {
		fmt.Fprintln(os.Stderr, "kluis:", err)
		os.Exit(1)
	}
}
