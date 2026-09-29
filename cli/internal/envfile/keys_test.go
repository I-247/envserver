package envfile

import (
	"strings"
	"testing"
)

// A release is server data, so a key is not trusted to be a name.
var injectedKeys = map[string]string{
	"APP_ENV":                     "production",
	"SAFE\nINJECTED=from-key":     "x",
	"HAS=EQUALS":                  "x",
	"":                            "empty",
	"1STARTS_WITH_DIGIT":          "x",
	"WITH SPACE":                  "x",
	"LINE\rBREAK":                 "x",
	"ENVCLIENT_SERVER\nOTHER=bad": "x",
}

func TestRenderLeavesOutKeysThatAreNotNames(t *testing.T) {
	rendered := Render(injectedKeys)

	if rendered != "APP_ENV=production\n" {
		t.Fatalf("Render wrote more than the one valid key:\n%q", rendered)
	}
}

func TestMergeNeverWritesAKeyThatIsNotAName(t *testing.T) {
	file := Parse("APP_ENV=local\n")

	result := file.Merge(injectedKeys, MergeOptions{Constructive: true})

	if got := file.String(); got != "APP_ENV=production\n" {
		t.Fatalf("file = %q, want only the valid key updated", got)
	}

	if result.Added != 0 || result.Updated != 1 || result.Skipped != len(injectedKeys)-1 {
		t.Fatalf("result = %+v, invalid keys must be reported as skipped", result)
	}
}

func TestSetIgnoresAKeyThatIsNotAName(t *testing.T) {
	file := Parse("")

	file.Set("A\nB", "x")

	if strings.TrimSpace(file.String()) != "" {
		t.Fatalf("Set wrote %q for an invalid key", file.String())
	}
}

func TestValidKey(t *testing.T) {
	for key, want := range map[string]bool{
		"APP_ENV": true, "_PRIVATE": true, "a1": true,
		"": false, "1A": false, "A-B": false, "A=B": false, "A\nB": false,
	} {
		if ValidKey(key) != want {
			t.Errorf("ValidKey(%q) = %v, want %v", key, !want, want)
		}
	}
}
