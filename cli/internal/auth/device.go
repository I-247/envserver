// Package auth runs the OAuth device authorization flow.
package auth

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/I-247/envserver/cli/internal/api"
	"github.com/I-247/envserver/cli/internal/config"
)

// DeviceCode is what the server hands back to start a login.
type DeviceCode struct {
	DeviceCode      string `json:"device_code"`
	UserCode        string `json:"user_code"`
	VerificationURI string `json:"verification_uri"`
	Interval        int    `json:"interval"`
	ExpiresIn       int    `json:"expires_in"`
}

// ErrDenied is returned when the user rejects the request in the browser.
var ErrDenied = errors.New("the request was denied in the browser")

// ErrExpired is returned when the code went stale before it was approved.
var ErrExpired = errors.New("the code expired before it was approved")

// RequestDeviceCode asks the server to start a device login.
func RequestDeviceCode(ctx context.Context, discovery *api.Discovery) (*DeviceCode, error) {
	form := url.Values{
		"client_id": {discovery.ClientID},
		"scope":     {strings.Join(discovery.Scopes, " ")},
	}

	response, err := post(ctx, discovery.DeviceCodeEndpoint, form)
	if err != nil {
		return nil, err
	}

	var code DeviceCode
	if err := json.Unmarshal(response, &code); err != nil {
		return nil, err
	}

	if code.DeviceCode == "" {
		return nil, fmt.Errorf("the server did not hand out a device code: %s", response)
	}

	if code.Interval <= 0 {
		code.Interval = 5
	}

	return &code, nil
}

// PollForToken waits for the user to approve the request in their browser.
//
// The interval is dictated by the server and widened whenever it says
// slow_down, which is the whole point of the field: polling faster than asked
// gets the client rate limited rather than served.
func PollForToken(ctx context.Context, discovery *api.Discovery, code *DeviceCode) (config.Credentials, error) {
	interval := time.Duration(code.Interval) * time.Second
	deadline := time.Now().Add(time.Duration(max(code.ExpiresIn, 60)) * time.Second)

	for {
		select {
		case <-ctx.Done():
			return config.Credentials{}, ctx.Err()
		case <-time.After(interval):
		}

		if time.Now().After(deadline) {
			return config.Credentials{}, ErrExpired
		}

		body, err := post(ctx, discovery.TokenEndpoint, url.Values{
			"grant_type":  {"urn:ietf:params:oauth:grant-type:device_code"},
			"client_id":   {discovery.ClientID},
			"device_code": {code.DeviceCode},
		})
		if err != nil {
			return config.Credentials{}, err
		}

		var payload struct {
			Error        string `json:"error"`
			AccessToken  string `json:"access_token"`
			RefreshToken string `json:"refresh_token"`
			ExpiresIn    int    `json:"expires_in"`
		}

		if err := json.Unmarshal(body, &payload); err != nil {
			return config.Credentials{}, err
		}

		switch payload.Error {
		case "":
			return config.Credentials{
				AccessToken:  payload.AccessToken,
				RefreshToken: payload.RefreshToken,
				ExpiresAt:    time.Now().Add(time.Duration(payload.ExpiresIn) * time.Second),
			}, nil
		case "authorization_pending":
			continue
		case "slow_down":
			interval += 5 * time.Second
		case "access_denied":
			return config.Credentials{}, ErrDenied
		case "expired_token":
			return config.Credentials{}, ErrExpired
		default:
			return config.Credentials{}, fmt.Errorf("login failed: %s", payload.Error)
		}
	}
}

// ClientCredentials exchanges a deploy token's id and secret for an access
// token. This is the non-interactive path a deploy server takes.
func ClientCredentials(ctx context.Context, server, clientID, clientSecret string, scopes []string) (config.Credentials, error) {
	body, err := post(ctx, strings.TrimRight(server, "/")+"/oauth/token", url.Values{
		"grant_type":    {"client_credentials"},
		"client_id":     {clientID},
		"client_secret": {clientSecret},
		"scope":         {strings.Join(scopes, " ")},
	})
	if err != nil {
		return config.Credentials{}, err
	}

	var payload struct {
		Error       string `json:"error"`
		AccessToken string `json:"access_token"`
		ExpiresIn   int    `json:"expires_in"`
	}

	if err := json.Unmarshal(body, &payload); err != nil {
		return config.Credentials{}, err
	}

	if payload.AccessToken == "" {
		return config.Credentials{}, fmt.Errorf("could not authenticate with ENVCLIENT_CLIENT_ID/ENVCLIENT_CLIENT_SECRET: %s", payload.Error)
	}

	return config.Credentials{
		AccessToken: payload.AccessToken,
		ExpiresAt:   time.Now().Add(time.Duration(payload.ExpiresIn) * time.Second),
	}, nil
}

func post(ctx context.Context, endpoint string, form url.Values) ([]byte, error) {
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, strings.NewReader(form.Encode()))
	if err != nil {
		return nil, err
	}

	request.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	request.Header.Set("Accept", "application/json")

	response, err := (&http.Client{Timeout: 30 * time.Second}).Do(request)
	if err != nil {
		return nil, err
	}
	defer response.Body.Close()

	return io.ReadAll(response.Body)
}
