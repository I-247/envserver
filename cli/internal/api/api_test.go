package api

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestTargetPathEscapesSlugs(t *testing.T) {
	target := Target{Team: "acme corp", Project: "web/shop", Environment: "production"}

	got := target.path("/release")
	want := "/api/v1/teams/acme%20corp/projects/web%2Fshop/environments/production/release"

	if got != want {
		t.Fatalf("path() = %q, want %q", got, want)
	}
}

func TestReleaseSendsTheBearerToken(t *testing.T) {
	var seen string

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen = r.Header.Get("Authorization")
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"version": 3}})
	}))
	defer server.Close()

	release, err := New(server.URL, "token-123").Release(context.Background(), Target{}, 0)
	if err != nil {
		t.Fatal(err)
	}

	if seen != "Bearer token-123" {
		t.Fatalf("Authorization = %q", seen)
	}

	if release.Version != 3 {
		t.Fatalf("Version = %d", release.Version)
	}
}

func TestReleaseAsksForASpecificVersion(t *testing.T) {
	var query string

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		query = r.URL.RawQuery
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{}})
	}))
	defer server.Close()

	if _, err := New(server.URL, "t").Release(context.Background(), Target{}, 7); err != nil {
		t.Fatal(err)
	}

	if query != "version=7" {
		t.Fatalf("query = %q", query)
	}
}

func TestErrorsCarryTheServerMessage(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"message": "This environment has no published release yet.",
		})
	}))
	defer server.Close()

	_, err := New(server.URL, "t").Release(context.Background(), Target{}, 0)
	if err == nil {
		t.Fatal("expected an error")
	}

	if !strings.Contains(err.Error(), "no published release") {
		t.Fatalf("error = %q", err)
	}
}

func TestValidationErrorsAreSpelledOut(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnprocessableEntity)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"message": "The given data was invalid.",
			"errors": map[string][]string{
				"variables.bad key": {"[bad key] is not a valid environment variable name."},
			},
		})
	}))
	defer server.Close()

	_, err := New(server.URL, "t").Push(context.Background(), Target{}, map[string]string{"bad key": "1"})
	if err == nil {
		t.Fatal("expected an error")
	}

	if !strings.Contains(err.Error(), "is not a valid environment variable name") {
		t.Fatalf("error = %q", err)
	}
}

func TestUnauthorizedSuggestsLoggingIn(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte("not json"))
	}))
	defer server.Close()

	_, err := New(server.URL, "").Release(context.Background(), Target{}, 0)
	if err == nil || !strings.Contains(err.Error(), "envclient login") {
		t.Fatalf("error = %v", err)
	}
}

func TestPushSendsTheVariablesAsJSON(t *testing.T) {
	var body map[string]map[string]string

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&body)
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"created": 1}})
	}))
	defer server.Close()

	result, err := New(server.URL, "t").Push(context.Background(), Target{}, map[string]string{"A": "1"})
	if err != nil {
		t.Fatal(err)
	}

	if body["variables"]["A"] != "1" {
		t.Fatalf("body = %#v", body)
	}

	if result.Created != 1 {
		t.Fatalf("Created = %d", result.Created)
	}
}

func TestDeployPushSendsTheVariablesToTheDeployScopedPath(t *testing.T) {
	var (
		path string
		body map[string]map[string]string
	)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path = r.URL.Path
		_ = json.NewDecoder(r.Body).Decode(&body)
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"updated": 1}})
	}))
	defer server.Close()

	result, err := New(server.URL, "t").DeployPush(context.Background(), map[string]string{"A": "1"})
	if err != nil {
		t.Fatal(err)
	}

	if path != "/api/v1/deploy/variables" {
		t.Fatalf("path = %q", path)
	}

	if body["variables"]["A"] != "1" {
		t.Fatalf("body = %#v", body)
	}

	if result.Updated != 1 {
		t.Fatalf("Updated = %d", result.Updated)
	}
}

func TestDiscoverNeedsNoToken(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "" {
			t.Errorf("discovery must not send a token, got %q", r.Header.Get("Authorization"))
		}

		_ = json.NewEncoder(w).Encode(map[string]any{
			"data": map[string]any{"client_id": "abc", "scopes": []string{"env:read"}},
		})
	}))
	defer server.Close()

	discovery, err := Discover(context.Background(), server.URL+"/")
	if err != nil {
		t.Fatal(err)
	}

	if discovery.ClientID != "abc" {
		t.Fatalf("ClientID = %q", discovery.ClientID)
	}
}
