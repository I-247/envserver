package config

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestFindProjectWalksUpFromASubdirectory(t *testing.T) {
	root := t.TempDir()
	nested := filepath.Join(root, "app", "Http")

	if err := os.MkdirAll(nested, 0o755); err != nil {
		t.Fatal(err)
	}

	write(t, filepath.Join(root, ProjectFileName),
		`{"server":"https://envserver.test","team":"acme","project":"shop","environment":"production"}`)

	project, err := FindProject(nested)
	if err != nil {
		t.Fatal(err)
	}

	if project.Name != "shop" || project.Environment != "production" {
		t.Fatalf("project = %#v", project)
	}

	if project.Dir() != root {
		t.Fatalf("Dir() = %q, want %q", project.Dir(), root)
	}
}

func TestFindProjectReportsWhenThereIsNone(t *testing.T) {
	if _, err := FindProject(t.TempDir()); !errors.Is(err, ErrNoProject) {
		t.Fatalf("err = %v, want ErrNoProject", err)
	}
}

func TestFindProjectRejectsAnIncompleteFile(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, ProjectFileName), `{"server":"https://envserver.test"}`)

	_, err := FindProject(dir)
	if err == nil {
		t.Fatal("expected an error naming the missing fields")
	}
}

func TestSaveAndReloadAProject(t *testing.T) {
	dir := t.TempDir()

	project := &Project{Server: "https://envserver.test", Team: "acme", Name: "shop", Environment: "development"}
	if err := project.Save(dir); err != nil {
		t.Fatal(err)
	}

	reloaded, err := FindProject(dir)
	if err != nil {
		t.Fatal(err)
	}

	if *reloaded != *project {
		t.Fatalf("reloaded = %#v, want %#v", reloaded, project)
	}
}

func TestCredentialsRoundTripPerServer(t *testing.T) {
	t.Setenv("ENVCLIENT_CONFIG_DIR", t.TempDir())

	if err := SaveCredentials("https://work.test", Credentials{AccessToken: "work"}); err != nil {
		t.Fatal(err)
	}

	if err := SaveCredentials("https://personal.test", Credentials{AccessToken: "personal"}); err != nil {
		t.Fatal(err)
	}

	work, found, err := LoadCredentials("https://work.test")
	if err != nil || !found || work.AccessToken != "work" {
		t.Fatalf("work = %#v, found = %v, err = %v", work, found, err)
	}

	personal, _, _ := LoadCredentials("https://personal.test")
	if personal.AccessToken != "personal" {
		t.Fatal("logging in to one server must not overwrite another")
	}
}

func TestCredentialsIgnoreATrailingSlash(t *testing.T) {
	t.Setenv("ENVCLIENT_CONFIG_DIR", t.TempDir())

	if err := SaveCredentials("https://envserver.test/", Credentials{AccessToken: "x"}); err != nil {
		t.Fatal(err)
	}

	if _, found, _ := LoadCredentials("https://envserver.test"); !found {
		t.Fatal("a trailing slash must not create a second entry")
	}
}

func TestCredentialsFileIsNotReadableByOthers(t *testing.T) {
	t.Setenv("ENVCLIENT_CONFIG_DIR", t.TempDir())

	if err := SaveCredentials("https://envserver.test", Credentials{AccessToken: "x"}); err != nil {
		t.Fatal(err)
	}

	path, _ := CredentialsPath()
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}

	if mode := info.Mode().Perm(); mode != 0o600 {
		t.Fatalf("credentials file mode = %o, want 600", mode)
	}
}

func TestForgetRemovesOnlyOneServer(t *testing.T) {
	t.Setenv("ENVCLIENT_CONFIG_DIR", t.TempDir())

	_ = SaveCredentials("https://a.test", Credentials{AccessToken: "a"})
	_ = SaveCredentials("https://b.test", Credentials{AccessToken: "b"})

	if err := ForgetCredentials("https://a.test"); err != nil {
		t.Fatal(err)
	}

	if _, found, _ := LoadCredentials("https://a.test"); found {
		t.Fatal("a.test should be gone")
	}

	if _, found, _ := LoadCredentials("https://b.test"); !found {
		t.Fatal("b.test should still be there")
	}
}

func TestMissingCredentialsAreNotAnError(t *testing.T) {
	t.Setenv("ENVCLIENT_CONFIG_DIR", t.TempDir())

	_, found, err := LoadCredentials("https://envserver.test")
	if err != nil || found {
		t.Fatalf("found = %v, err = %v", found, err)
	}
}

func TestExpiredLeavesAMinuteOfSlack(t *testing.T) {
	cases := map[string]struct {
		expiresAt time.Time
		want      bool
	}{
		"no expiry":       {time.Time{}, false},
		"long valid":      {time.Now().Add(time.Hour), false},
		"about to expire": {time.Now().Add(30 * time.Second), true},
		"expired":         {time.Now().Add(-time.Hour), true},
	}

	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			if got := (Credentials{ExpiresAt: tc.expiresAt}).Expired(); got != tc.want {
				t.Fatalf("Expired() = %v, want %v", got, tc.want)
			}
		})
	}
}

func TestLoadDeployEnvFillsInMissingVariables(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, DeployEnvFileName),
		"ENVCLIENT_CLIENT_ID=abc\nENVCLIENT_CLIENT_SECRET=secret\n")

	for _, key := range []string{"ENVCLIENT_CLIENT_ID", "ENVCLIENT_CLIENT_SECRET"} {
		original, wasSet := os.LookupEnv(key)
		os.Unsetenv(key)

		t.Cleanup(func() {
			if wasSet {
				os.Setenv(key, original)
			} else {
				os.Unsetenv(key)
			}
		})
	}

	LoadDeployEnv(dir)

	if got := os.Getenv("ENVCLIENT_CLIENT_ID"); got != "abc" {
		t.Fatalf("ENVCLIENT_CLIENT_ID = %q, want abc", got)
	}

	if got := os.Getenv("ENVCLIENT_CLIENT_SECRET"); got != "secret" {
		t.Fatalf("ENVCLIENT_CLIENT_SECRET = %q, want secret", got)
	}
}

func TestLoadDeployEnvNeverOverridesARealExport(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, DeployEnvFileName), "ENVCLIENT_CLIENT_ID=from-file\n")

	t.Setenv("ENVCLIENT_CLIENT_ID", "from-shell")

	LoadDeployEnv(dir)

	if got := os.Getenv("ENVCLIENT_CLIENT_ID"); got != "from-shell" {
		t.Fatalf("ENVCLIENT_CLIENT_ID = %q, want from-shell (the file must never win)", got)
	}
}

func TestLoadDeployEnvIgnoresKeysOutsideItsNamespace(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, DeployEnvFileName), "APP_KEY=base64:whatever\n")

	LoadDeployEnv(dir)

	if _, set := os.LookupEnv("APP_KEY"); set {
		os.Unsetenv("APP_KEY")
		t.Fatal("LoadDeployEnv set a variable outside the ENVCLIENT_ namespace")
	}
}

func TestLoadDeployEnvIsANoOpWhenTheFileIsMissing(t *testing.T) {
	// Must not panic or error: most machines will never have this file.
	LoadDeployEnv(t.TempDir())
}

func write(t *testing.T, path, contents string) {
	t.Helper()

	if err := os.WriteFile(path, []byte(contents), 0o644); err != nil {
		t.Fatal(err)
	}
}
