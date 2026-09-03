// Package config reads the two pieces of state the CLI keeps: the project
// link that lives in the repository, and the credentials that must never go
// anywhere near it.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// ProjectFileName is the file committed alongside the code. It names the
// project, never a secret, so it is safe in version control.
const ProjectFileName = "envclient.json"

// Project links a working directory to one environment on one server.
type Project struct {
	Server      string `json:"server"`
	Team        string `json:"team"`
	Name        string `json:"project"`
	Environment string `json:"environment"`

	path string
}

// ErrNoProject is returned when no envclient.json can be found.
var ErrNoProject = errors.New("no envclient.json found; run \"envclient init\" in your project")

// FindProject walks up from dir looking for a envclient.json, the way git finds
// its own root. Running a command from a subdirectory should just work.
func FindProject(dir string) (*Project, error) {
	dir, err := filepath.Abs(dir)
	if err != nil {
		return nil, err
	}

	for {
		path := filepath.Join(dir, ProjectFileName)

		if contents, err := os.ReadFile(path); err == nil {
			project := &Project{path: path}

			if err := json.Unmarshal(contents, project); err != nil {
				return nil, fmt.Errorf("%s is not valid JSON: %w", path, err)
			}

			return project, project.validate()
		}

		parent := filepath.Dir(dir)
		if parent == dir {
			return nil, ErrNoProject
		}

		dir = parent
	}
}

// Dir returns the directory the project file lives in.
func (p *Project) Dir() string {
	return filepath.Dir(p.path)
}

// Save writes the project file.
func (p *Project) Save(dir string) error {
	p.path = filepath.Join(dir, ProjectFileName)

	contents, err := json.MarshalIndent(p, "", "    ")
	if err != nil {
		return err
	}

	return os.WriteFile(p.path, append(contents, '\n'), 0o644)
}

func (p *Project) validate() error {
	missing := []string{}

	for name, value := range map[string]string{
		"server":      p.Server,
		"team":        p.Team,
		"project":     p.Name,
		"environment": p.Environment,
	} {
		if strings.TrimSpace(value) == "" {
			missing = append(missing, name)
		}
	}

	if len(missing) > 0 {
		return fmt.Errorf("%s is missing: %s", p.path, strings.Join(missing, ", "))
	}

	return nil
}

// Credentials holds the tokens for one server.
type Credentials struct {
	AccessToken  string    `json:"access_token"`
	RefreshToken string    `json:"refresh_token,omitempty"`
	ExpiresAt    time.Time `json:"expires_at,omitempty"`
}

// Expired reports whether the access token is past its lifetime.
//
// A minute of slack so a token that dies mid-request is refreshed before the
// request rather than after it fails.
func (c Credentials) Expired() bool {
	return !c.ExpiresAt.IsZero() && time.Now().Add(time.Minute).After(c.ExpiresAt)
}

// store is the on disk shape: credentials per server, so one machine can talk
// to a work instance and a personal one without logging out in between.
type store map[string]Credentials

// CredentialsPath returns the file tokens are kept in.
func CredentialsPath() (string, error) {
	if override := os.Getenv("ENVCLIENT_CONFIG_DIR"); override != "" {
		return filepath.Join(override, "credentials.json"), nil
	}

	dir, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, "envclient", "credentials.json"), nil
}

// LoadCredentials reads the stored credentials for a server.
func LoadCredentials(server string) (Credentials, bool, error) {
	path, err := CredentialsPath()
	if err != nil {
		return Credentials{}, false, err
	}

	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return Credentials{}, false, nil
	}
	if err != nil {
		return Credentials{}, false, err
	}

	var s store
	if err := json.Unmarshal(contents, &s); err != nil {
		return Credentials{}, false, fmt.Errorf("%s is corrupt: %w", path, err)
	}

	credentials, ok := s[normalise(server)]

	return credentials, ok, nil
}

// SaveCredentials stores the credentials for a server.
func SaveCredentials(server string, credentials Credentials) error {
	path, err := CredentialsPath()
	if err != nil {
		return err
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}

	s := store{}
	if contents, err := os.ReadFile(path); err == nil {
		_ = json.Unmarshal(contents, &s)
	}

	s[normalise(server)] = credentials

	contents, err := json.MarshalIndent(s, "", "    ")
	if err != nil {
		return err
	}

	// 0600 from the start: writing world readable and chmodding after would
	// leave a window in which anyone on the box could read the token.
	return os.WriteFile(path, append(contents, '\n'), 0o600)
}

// ForgetCredentials removes the credentials for a server.
func ForgetCredentials(server string) error {
	path, err := CredentialsPath()
	if err != nil {
		return err
	}

	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}

	var s store
	if err := json.Unmarshal(contents, &s); err != nil {
		return err
	}

	delete(s, normalise(server))

	updated, err := json.MarshalIndent(s, "", "    ")
	if err != nil {
		return err
	}

	return os.WriteFile(path, append(updated, '\n'), 0o600)
}

func normalise(server string) string {
	return strings.TrimRight(strings.TrimSpace(server), "/")
}
