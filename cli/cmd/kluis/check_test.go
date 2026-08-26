package main

import (
	"strings"
	"testing"
)

func release() map[string]string {
	return map[string]string{"APP_ENV": "production", "MAIL_MAILER": "ses"}
}

func TestCheckReportsNothingWhenTheFileMatches(t *testing.T) {
	drifts := compareWithRelease(release(), map[string]string{"APP_ENV": "production", "MAIL_MAILER": "ses"})

	if len(drifts) != 0 {
		t.Fatalf("expected no differences, got %v", drifts)
	}
}

func TestCheckSeparatesMissingChangedAndExtra(t *testing.T) {
	drifts := compareWithRelease(release(), map[string]string{"APP_ENV": "local", "MINE": "keep"})

	want := map[string]driftKind{
		"APP_ENV":     driftChanged,
		"MAIL_MAILER": driftMissing,
		"MINE":        driftExtra,
	}

	if len(drifts) != len(want) {
		t.Fatalf("expected %d differences, got %v", len(want), drifts)
	}

	for _, d := range drifts {
		if want[d.Key] != d.Kind {
			t.Errorf("%s classified as %v, wanted %v", d.Key, d.Kind, want[d.Key])
		}
	}
}

// A key only you have is your business: the same promise "kluis pull" makes
// by never pruning unless it is asked to.
func TestCheckPassesOnAKeyOnlyTheFileHas(t *testing.T) {
	drifts := compareWithRelease(release(), map[string]string{
		"APP_ENV": "production", "MAIL_MAILER": "ses", "MY_DEBUG_TOKEN": "x",
	})

	if failures := countFailures(drifts, false); failures != 0 {
		t.Fatalf("an extra local key failed the check: %d failure(s)", failures)
	}

	if failures := countFailures(drifts, true); failures != 1 {
		t.Fatalf("--strict did not count the extra key: %d failure(s)", failures)
	}
}

func TestCheckFailsOnAMissingKey(t *testing.T) {
	drifts := compareWithRelease(release(), map[string]string{"APP_ENV": "production"})

	if failures := countFailures(drifts, false); failures != 1 {
		t.Fatalf("expected one failure, got %d", failures)
	}
}

func TestCheckSortsByKey(t *testing.T) {
	drifts := compareWithRelease(
		map[string]string{"ZED": "1", "ALPHA": "2", "MIDDLE": "3"},
		map[string]string{},
	)

	got := make([]string, 0, len(drifts))
	for _, d := range drifts {
		got = append(got, d.Key)
	}

	if strings.Join(got, ",") != "ALPHA,MIDDLE,ZED" {
		t.Fatalf("differences are not sorted by key: %v", got)
	}
}

// The exit code is the whole point of the command: 1 already means "something
// went wrong", so drift has to be distinguishable from it.
func TestCheckFailureCarriesItsOwnExitCode(t *testing.T) {
	err := checkFailed{failures: 3}

	if err.ExitCode() != 2 {
		t.Fatalf("expected exit code 2, got %d", err.ExitCode())
	}

	if !strings.Contains(err.Error(), "3 difference") {
		t.Errorf("message does not say how many: %s", err.Error())
	}
}

func TestCheckSaysExtraKeysAreIgnoredUnlessStrict(t *testing.T) {
	drifts := compareWithRelease(release(), map[string]string{
		"APP_ENV": "production", "MAIL_MAILER": "ses", "MINE": "x",
	})

	relaxed := describeDrifts(drifts, false)
	if !strings.Contains(relaxed[0].Detail, "ignored") {
		t.Errorf("relaxed run does not say the key is ignored: %q", relaxed[0].Detail)
	}

	strict := describeDrifts(drifts, true)
	if strings.Contains(strict[0].Detail, "ignored") {
		t.Errorf("strict run still calls the key ignored: %q", strict[0].Detail)
	}
}
