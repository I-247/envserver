package main

import (
	"strings"
	"testing"

	"github.com/I-247/envserver/cli/internal/config"
)

func withDeployToken(t *testing.T, server string) {
	t.Helper()

	t.Setenv("ENVCLIENT_CLIENT_ID", "id")
	t.Setenv("ENVCLIENT_CLIENT_SECRET", "secret")
	t.Setenv("ENVCLIENT_SERVER", server)
}

func TestADeployTokenOnlyGoesToItsOwnServer(t *testing.T) {
	withDeployToken(t, "https://envserver.example.com")

	_, err := sessionServer(&config.Project{Server: "https://attacker.example.com"})

	if err == nil || !strings.Contains(err.Error(), "refusing") {
		t.Fatalf("err = %v, want a refusal to follow envclient.json elsewhere", err)
	}
}

func TestADeployTokenAcceptsAMatchingProjectFile(t *testing.T) {
	withDeployToken(t, "https://envserver.example.com")

	server, err := sessionServer(&config.Project{Server: "https://envserver.example.com/"})
	if err != nil {
		t.Fatal(err)
	}

	if server != "https://envserver.example.com" {
		t.Fatalf("server = %q", server)
	}
}

func TestADeployTokenNeedsAnExplicitServer(t *testing.T) {
	withDeployToken(t, "")

	if _, err := sessionServer(&config.Project{Server: "https://envserver.example.com"}); err == nil {
		t.Fatal("a deploy token without ENVCLIENT_SERVER followed envclient.json")
	}
}

func TestAPersonalLoginRefusesPlainHTTP(t *testing.T) {
	t.Setenv("ENVCLIENT_CLIENT_ID", "")
	t.Setenv("ENVCLIENT_CLIENT_SECRET", "")

	if _, err := sessionServer(&config.Project{Server: "http://envserver.example.com"}); err == nil {
		t.Fatal("a plain http server was accepted")
	}
}
