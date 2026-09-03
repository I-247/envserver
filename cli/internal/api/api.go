// Package api talks to an Envserver server.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Client is an authenticated connection to one Envserver server.
type Client struct {
	Server string
	Token  string
	HTTP   *http.Client
}

// New builds a client with a sane timeout.
func New(server, token string) *Client {
	return &Client{
		Server: strings.TrimRight(server, "/"),
		Token:  token,
		HTTP:   &http.Client{Timeout: 30 * time.Second},
	}
}

// Discovery describes how to log in against a server.
type Discovery struct {
	ClientID           string   `json:"client_id"`
	DeviceCodeEndpoint string   `json:"device_code_endpoint"`
	TokenEndpoint      string   `json:"token_endpoint"`
	APIBase            string   `json:"api_base"`
	Scopes             []string `json:"scopes"`
}

// Release is a published snapshot of an environment.
type Release struct {
	Version     int               `json:"version"`
	Message     string            `json:"message"`
	Project     string            `json:"project"`
	Environment string            `json:"environment"`
	PublishedAt string            `json:"published_at"`
	Variables   map[string]string `json:"variables"`
}

// ReleaseSummary is a release without its values.
type ReleaseSummary struct {
	Version        int    `json:"version"`
	Message        string `json:"message"`
	PublishedBy    string `json:"published_by"`
	PublishedAt    string `json:"published_at"`
	VariablesCount int    `json:"variables_count"`
}

// Change is one difference between two snapshots.
type Change struct {
	Key    string `json:"key"`
	Type   string `json:"type"`
	Before string `json:"before"`
	After  string `json:"after"`
}

// Project is a project with its environments.
type Project struct {
	Team         string `json:"team"`
	Slug         string `json:"slug"`
	Name         string `json:"name"`
	Description  string `json:"description"`
	Environments []struct {
		Slug        string `json:"slug"`
		Name        string `json:"name"`
		AutoPublish bool   `json:"auto_publish"`
	} `json:"environments"`
}

// PushResult reports what a push did on the server.
type PushResult struct {
	Created      int      `json:"created"`
	Updated      int      `json:"updated"`
	Unchanged    int      `json:"unchanged"`
	SharedImpact []string `json:"shared_impact"`
}

// Error is a failed API response, carrying enough to explain itself.
type Error struct {
	StatusCode int
	Message    string
}

func (e *Error) Error() string {
	if e.Message == "" {
		return fmt.Sprintf("the server replied %d", e.StatusCode)
	}

	return e.Message
}

// Discover fetches a server's login details without authenticating.
func Discover(ctx context.Context, server string) (*Discovery, error) {
	client := New(server, "")

	var wrapper struct {
		Data Discovery `json:"data"`
	}

	if err := client.get(ctx, "/api/v1/cli", &wrapper); err != nil {
		return nil, err
	}

	return &wrapper.Data, nil
}

// Projects lists everything the signed in user can reach.
func (c *Client) Projects(ctx context.Context) ([]Project, error) {
	var wrapper struct {
		Data []Project `json:"data"`
	}

	if err := c.get(ctx, "/api/v1/projects", &wrapper); err != nil {
		return nil, err
	}

	return wrapper.Data, nil
}

// Release fetches a release, or the latest one when version is zero.
func (c *Client) Release(ctx context.Context, target Target, version int) (*Release, error) {
	path := target.path("/release")
	if version > 0 {
		path += "?version=" + url.QueryEscape(fmt.Sprint(version))
	}

	var wrapper struct {
		Data Release `json:"data"`
	}

	if err := c.get(ctx, path, &wrapper); err != nil {
		return nil, err
	}

	return &wrapper.Data, nil
}

// Releases lists an environment's history.
func (c *Client) Releases(ctx context.Context, target Target) ([]ReleaseSummary, error) {
	var wrapper struct {
		Data []ReleaseSummary `json:"data"`
	}

	if err := c.get(ctx, target.path("/releases"), &wrapper); err != nil {
		return nil, err
	}

	return wrapper.Data, nil
}

// Pending lists what would change if the environment were published now.
func (c *Client) Pending(ctx context.Context, target Target) ([]Change, error) {
	var wrapper struct {
		Data []Change `json:"data"`
	}

	if err := c.get(ctx, target.path("/pending"), &wrapper); err != nil {
		return nil, err
	}

	return wrapper.Data, nil
}

// Push sends local values to the server.
func (c *Client) Push(ctx context.Context, target Target, variables map[string]string) (*PushResult, error) {
	var wrapper struct {
		Data PushResult `json:"data"`
	}

	body := map[string]any{"variables": variables}

	if err := c.post(ctx, target.path("/variables"), body, &wrapper); err != nil {
		return nil, err
	}

	return &wrapper.Data, nil
}

// Publish creates a release.
func (c *Client) Publish(ctx context.Context, target Target, message string) (*Release, error) {
	var wrapper struct {
		Data Release `json:"data"`
	}

	if err := c.post(ctx, target.path("/releases"), map[string]any{"message": message}, &wrapper); err != nil {
		return nil, err
	}

	return &wrapper.Data, nil
}

// DeployRelease fetches the release a deploy token stands for.
func (c *Client) DeployRelease(ctx context.Context, version int) (*Release, error) {
	path := "/api/v1/deploy/release"
	if version > 0 {
		path += "?version=" + url.QueryEscape(fmt.Sprint(version))
	}

	var wrapper struct {
		Data Release `json:"data"`
	}

	if err := c.get(ctx, path, &wrapper); err != nil {
		return nil, err
	}

	return &wrapper.Data, nil
}

// Target names one environment on the server.
type Target struct {
	Team        string
	Project     string
	Environment string
}

func (t Target) path(suffix string) string {
	return fmt.Sprintf(
		"/api/v1/teams/%s/projects/%s/environments/%s%s",
		url.PathEscape(t.Team),
		url.PathEscape(t.Project),
		url.PathEscape(t.Environment),
		suffix,
	)
}

func (c *Client) get(ctx context.Context, path string, out any) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, c.Server+path, nil)
	if err != nil {
		return err
	}

	return c.do(request, out)
}

func (c *Client) post(ctx context.Context, path string, body any, out any) error {
	encoded, err := json.Marshal(body)
	if err != nil {
		return err
	}

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.Server+path, bytes.NewReader(encoded))
	if err != nil {
		return err
	}

	request.Header.Set("Content-Type", "application/json")

	return c.do(request, out)
}

func (c *Client) do(request *http.Request, out any) error {
	request.Header.Set("Accept", "application/json")

	if c.Token != "" {
		request.Header.Set("Authorization", "Bearer "+c.Token)
	}

	response, err := c.HTTP.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()

	body, err := io.ReadAll(response.Body)
	if err != nil {
		return err
	}

	if response.StatusCode >= 400 {
		return &Error{StatusCode: response.StatusCode, Message: messageFrom(response.StatusCode, body)}
	}

	if out == nil {
		return nil
	}

	return json.Unmarshal(body, out)
}

// messageFrom turns a server error into something worth reading. Laravel
// returns "message" for aborts and both "message" and "errors" for validation
// failures, so the field names are known rather than guessed.
func messageFrom(status int, body []byte) string {
	var payload struct {
		Message string              `json:"message"`
		Errors  map[string][]string `json:"errors"`
	}

	if err := json.Unmarshal(body, &payload); err != nil {
		return defaultMessage(status)
	}

	var b strings.Builder
	b.WriteString(payload.Message)

	if b.Len() == 0 {
		b.WriteString(defaultMessage(status))
	}

	for field, messages := range payload.Errors {
		for _, message := range messages {
			fmt.Fprintf(&b, "\n  %s: %s", field, message)
		}
	}

	return b.String()
}

func defaultMessage(status int) string {
	switch status {
	case http.StatusUnauthorized:
		return "not logged in; run \"envclient login\""
	case http.StatusForbidden:
		return "your token is not allowed to do that"
	case http.StatusNotFound:
		return "not found; check the team, project and environment in envclient.json"
	case http.StatusServiceUnavailable:
		return "this server has no CLI client yet; ask an admin to run \"php artisan envserver:cli-client\""
	default:
		return fmt.Sprintf("the server replied %d", status)
	}
}
