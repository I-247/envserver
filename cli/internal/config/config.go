// Package config reads the two pieces of state the CLI keeps: the project
// link that lives in the repository, and the credentials that must never go
// anywhere near it.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/I-247/envserver/cli/internal/envfile"
	"github.com/I-247/envserver/cli/internal/securefile"
)

// ProjectFileName is the file committed alongside the code. It names the
// project, never a secret, so it is safe in version control.
const ProjectFileName = "envclient.json"

// DeployEnvFileName holds ENVCLIENT_* values for a machine that would
// otherwise need them exported by hand, such as ENVCLIENT_CLIENT_ID and
// ENVCLIENT_CLIENT_SECRET for a deploy token.
//
// Deliberately not the .env file "pull" manages, and deliberately not named
// close to vault.FileName (".env.envclient", the sealed vault): a credential
// kept in the managed .env would be deleted the moment "pull --prune" runs
// and the release doesn't mention it, which would lock out the very
// credential that fetched it. Never commit this file.
const DeployEnvFileName = ".envclientrc"

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

	if err := securefile.PrivateDir(filepath.Dir(path)); err != nil {
		return err
	}

	s, err := readStore(path)
	if err != nil {
		return err
	}

	s[normalise(server)] = credentials

	return writeStore(path, s)
}

// readStore reads every server's credentials, or an empty store when there
// is no file yet.
//
// A file that exists but cannot be read or parsed is an error, not an empty
// store: treating it as empty would write back only the server being saved
// and silently drop every other login on the machine.
func readStore(path string) (store, error) {
	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return store{}, nil
	}
	if err != nil {
		return nil, err
	}

	s := store{}
	if err := json.Unmarshal(contents, &s); err != nil {
		return nil, fmt.Errorf("%s is corrupt; fix or remove it first: %w", path, err)
	}

	return s, nil
}

// writeStore writes the credentials file, owner only from the first byte.
func writeStore(path string, s store) error {
	contents, err := json.MarshalIndent(s, "", "    ")
	if err != nil {
		return err
	}

	return securefile.WriteFile(path, append(contents, '\n'))
}

// ForgetCredentials removes the credentials for a server.
func ForgetCredentials(server string) error {
	path, err := CredentialsPath()
	if err != nil {
		return err
	}

	if _, err := os.Stat(path); errors.Is(err, os.ErrNotExist) {
		return nil
	}

	s, err := readStore(path)
	if err != nil {
		return err
	}

	delete(s, normalise(server))

	return writeStore(path, s)
}

func normalise(server string) string {
	return strings.TrimRight(strings.TrimSpace(server), "/")
}

// deployEnvFiles are checked in order for ENVCLIENT_* values. .env is
// listed for people who keep a deploy token's credentials right alongside
// the values it fetches rather than in a dedicated file — envfile's
// mergeKeys knows to never prune an ENVCLIENT_* key for exactly that reason,
// so a "pull --prune" there can't delete the credential that ran it.
var deployEnvFiles = []string{DeployEnvFileName, ".env"}

// fileSettableKeys are the only ENVCLIENT_* keys a file in the working
// directory may set. Everything in that directory may have come from a
// repository somebody else wrote, so ENVCLIENT_CONFIG_DIR (where a login
// token is written) and ENVCLIENT_VAULT_KEY (what `seal` encrypts with) are
// only ever taken from a real export. ENVCLIENT_SERVER is settable, but see
// LoadDeployEnv for the condition.
var fileSettableKeys = map[string]bool{
	"ENVCLIENT_CLIENT_ID":     true,
	"ENVCLIENT_CLIENT_SECRET": true,
	"ENVCLIENT_SCOPES":        true,
	"ENVCLIENT_SERVER":        true,
}

// LoadDeployEnv reads each of deployEnvFiles in dir that exists, and sets
// the fileSettableKeys it defines that are not already in the process
// environment.
//
// A real export always wins over either file, and the first file in the
// list wins over the second for the same key. Errors are swallowed: both
// files are an optional convenience, and a machine that exported the
// variables the normal way should never be tripped up by a file it doesn't
// have.
//
// A file only names the server for credentials it supplied itself. A cloned
// repository could otherwise ship a .env with ENVCLIENT_SERVER pointing at a
// host of its choosing, and the client secret exported on a CI runner would
// be sent there on the next pull.
func LoadDeployEnv(dir string) {
	for _, name := range deployEnvFiles {
		contents, err := os.ReadFile(filepath.Join(dir, name))
		if err != nil {
			continue
		}

		values := envfile.Parse(string(contents)).Values()

		for key, value := range values {
			if !fileSettableKeys[key] || key == "ENVCLIENT_SERVER" {
				continue
			}

			if _, set := os.LookupEnv(key); !set {
				os.Setenv(key, value)
			}
		}

		server, named := values["ENVCLIENT_SERVER"]
		_, exported := os.LookupEnv("ENVCLIENT_SERVER")

		if named && !exported && suppliesCredentialsInUse(values) {
			os.Setenv("ENVCLIENT_SERVER", server)
		}
	}
}

// suppliesCredentialsInUse reports whether the file's client id and secret
// are the ones the process will actually authenticate with.
func suppliesCredentialsInUse(values map[string]string) bool {
	id, secret := values["ENVCLIENT_CLIENT_ID"], values["ENVCLIENT_CLIENT_SECRET"]

	return id != "" && secret != "" &&
		os.Getenv("ENVCLIENT_CLIENT_ID") == id &&
		os.Getenv("ENVCLIENT_CLIENT_SECRET") == secret
}

// SameServer reports whether two server URLs name the same Envserver.
func SameServer(a, b string) bool {
	return strings.EqualFold(normalise(a), normalise(b))
}

// CheckServer refuses a server URL a token should never be sent to.
//
// Plain http is only accepted for addresses that never leave the machine or
// the local development domain (.test, .localhost): anything else would put
// a bearer token or client secret on the wire in the clear.
func CheckServer(server string) error {
	parsed, err := url.Parse(normalise(server))
	if err != nil || parsed.Host == "" {
		return fmt.Errorf("%q is not a server URL; expected something like https://envserver.example.com", server)
	}

	switch parsed.Scheme {
	case "https":
		return nil
	case "http":
		if isLocalHost(parsed.Hostname()) {
			return nil
		}

		return fmt.Errorf("refusing to send credentials to %s over plain http; use https", server)
	default:
		return fmt.Errorf("%q is not a server URL; expected something like https://envserver.example.com", server)
	}
}

func isLocalHost(host string) bool {
	host = strings.ToLower(host)

	if host == "localhost" || strings.HasSuffix(host, ".localhost") || strings.HasSuffix(host, ".test") {
		return true
	}

	ip := net.ParseIP(host)

	return ip != nil && ip.IsLoopback()
}
