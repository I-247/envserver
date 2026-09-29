package selfupdate

import (
	"strings"
	"testing"
)

func TestReadLimitedRefusesMoreThanTheLimit(t *testing.T) {
	if _, err := readLimited(strings.NewReader(strings.Repeat("x", 11)), 10, "asset"); err == nil {
		t.Fatal("read past the limit")
	}

	body, err := readLimited(strings.NewReader(strings.Repeat("x", 10)), 10, "asset")
	if err != nil || len(body) != 10 {
		t.Fatalf("len = %d, err = %v, want exactly the limit accepted", len(body), err)
	}
}

func TestExtractBinaryRefusesAnOversizedFile(t *testing.T) {
	archive := tarGz(t, map[string][]byte{"envclient": make([]byte, MaxBinary+1)})

	if _, err := ExtractBinary(archive, "envclient"); err == nil {
		t.Fatal("an oversized binary was unpacked")
	}
}

func TestCheckUpgrade(t *testing.T) {
	cases := []struct {
		current, latest string
		allowed         bool
	}{
		{"1.2.3", "1.2.4", true},
		{"1.2.3", "1.10.0", true},
		{"1.2.3", "1.2.3", true},
		{"1.2.3-rc.1", "1.2.3", true},
		{"dev", "0.0.1", true},
		{"1.2.3", "1.2.2", false},
		{"1.10.0", "1.9.9", false},
		{"1.2.3", "1.2.3-rc.1", false},
		{"1.2.3", "not-a-version", false},
		{"1.2.3", "", false},
	}

	for _, c := range cases {
		if err := CheckUpgrade(c.current, c.latest); (err == nil) != c.allowed {
			t.Errorf("CheckUpgrade(%q, %q) = %v, want allowed=%v", c.current, c.latest, err, c.allowed)
		}
	}
}
