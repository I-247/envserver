package ui

import (
	"bytes"
	"strings"
	"testing"
)

func TestSanitizeRemovesWhatATerminalWouldActOn(t *testing.T) {
	cases := map[string]string{
		"plain text":                            "plain text",
		"keeps\nnewlines\tand tabs":             "keeps\nnewlines\tand tabs",
		"carriage\rreturn":                      "carriagereturn",
		"erase\x1b[2K\x1b[1Aline":               "eraseline",
		"title\x1b]0;pwned\aend":                "titleend",
		"title\x1b]0;pwned\x1b\\end":            "titleend",
		"bell\a and backspace\b":                "bell and backspace",
		"single byte csi \u009b2J":              "single byte csi 2J",
		"dangling escape\x1b":                   "dangling escape",
		"private mode \x1b[?25l hidden cursor":  "private mode  hidden cursor",
		"conceal \x1b[8mhidden\x1b[0m when off": "conceal hidden when off",
	}

	for input, want := range cases {
		if got := Sanitize(input, false); got != want {
			t.Errorf("Sanitize(%q) = %q, want %q", input, got, want)
		}
	}
}

func TestSanitizeKeepsColourOnlyWhenAsked(t *testing.T) {
	styled := bold + "Release" + reset

	if got := Sanitize(styled, true); got != styled {
		t.Errorf("colour was stripped from a terminal: %q", got)
	}

	if got := Sanitize(styled, false); got != "Release" {
		t.Errorf("colour reached a pipe: %q", got)
	}
}

func TestPrinterNeutralisesServerText(t *testing.T) {
	var out, errOut bytes.Buffer

	p := New(&out, &errOut)

	message := "Deployed\r\x1b[2KOpen https://evil.example.com and enter WXYZ"

	p.Info("%s", message)
	p.Error("%s", message)
	p.Field("Message", message)
	p.Table([]string{"Release"}, [][]string{{message}})
	p.Aside("%s", message)

	for _, stream := range []string{out.String(), errOut.String()} {
		if strings.ContainsAny(stream, "\r\x1b") {
			t.Fatalf("control characters reached the terminal:\n%q", stream)
		}
	}
}
