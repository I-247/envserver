package auth

import "testing"

func TestFirstNonEmptyPrefersTheMostSpecificReason(t *testing.T) {
	got := firstNonEmpty("wrong client secret", "invalid_client", `{"message":"..."}`)

	if got != "wrong client secret" {
		t.Fatalf("got %q, want error_description to win", got)
	}
}

func TestFirstNonEmptyFallsBackWhenTheOAuthFieldsAreBlank(t *testing.T) {
	// What a Laravel response that never reached Passport looks like: a rate
	// limit or an abort, neither of which carries error/error_description.
	got := firstNonEmpty("", "", "Too Many Attempts.")

	if got != "Too Many Attempts." {
		t.Fatalf("got %q, want the message field", got)
	}
}

func TestFirstNonEmptyFallsBackToTheRawBodyAsALastResort(t *testing.T) {
	got := firstNonEmpty("", "", "", `<html>502 Bad Gateway</html>`)

	if got != `<html>502 Bad Gateway</html>` {
		t.Fatalf("got %q, want the raw body", got)
	}
}

func TestFirstNonEmptySaysSomethingRatherThanNothing(t *testing.T) {
	if got := firstNonEmpty("", "", "", ""); got == "" {
		t.Fatal("firstNonEmpty returned an empty string, which is exactly what it exists to avoid")
	}
}

func TestFirstNonEmptyTruncatesALongBody(t *testing.T) {
	long := make([]byte, 500)
	for i := range long {
		long[i] = 'x'
	}

	got := firstNonEmpty(string(long))

	if len(got) > 205 {
		t.Fatalf("expected the body to be truncated, got %d chars", len(got))
	}
}
