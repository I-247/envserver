package auth

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/I-247/envserver/cli/internal/api"
)

func deviceServer(t *testing.T, verificationURI func(host string) string) (*httptest.Server, *api.Discovery) {
	t.Helper()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"device_code":      "device",
			"user_code":        "ABCD-EFGH",
			"verification_uri": verificationURI(r.Host),
			"interval":         5,
			"expires_in":       600,
		})
	}))
	t.Cleanup(server.Close)

	return server, &api.Discovery{
		ClientID:           "client",
		DeviceCodeEndpoint: server.URL + "/oauth/device/code",
		Server:             server.URL,
	}
}

func TestRequestDeviceCodeAcceptsAVerificationPageOnTheServer(t *testing.T) {
	_, discovery := deviceServer(t, func(host string) string { return "http://" + host + "/oauth/device" })

	code, err := RequestDeviceCode(context.Background(), discovery)
	if err != nil {
		t.Fatal(err)
	}

	if code.UserCode != "ABCD-EFGH" {
		t.Fatalf("UserCode = %q", code.UserCode)
	}
}

func TestRequestDeviceCodeRefusesAVerificationPageElsewhere(t *testing.T) {
	_, discovery := deviceServer(t, func(string) string { return "https://envserver-login.example.com/device" })

	if _, err := RequestDeviceCode(context.Background(), discovery); err == nil {
		t.Fatal("a verification page on another host was accepted")
	}
}
