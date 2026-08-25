package envfile

import (
	"reflect"
	"strings"
	"testing"
)

func TestParseValues(t *testing.T) {
	cases := []struct {
		name  string
		input string
		want  map[string]string
	}{
		{"bare", "APP_ENV=production\n", map[string]string{"APP_ENV": "production"}},
		{"empty", "EMPTY=\n", map[string]string{"EMPTY": ""}},
		{"double quoted", `KEY="two words"`, map[string]string{"KEY": "two words"}},
		{"single quoted", `KEY='it''s'`, map[string]string{"KEY": "it"}},
		{"escaped dollar", `KEY="p\$ssw0rd"`, map[string]string{"KEY": "p$ssw0rd"}},
		{"escaped quote", `KEY="say \"hi\""`, map[string]string{"KEY": `say "hi"`}},
		{"escaped backslash", `KEY="C:\\path"`, map[string]string{"KEY": `C:\path`}},
		{"newline escape", `KEY="a\nb"`, map[string]string{"KEY": "a\nb"}},
		{"tab escape", `KEY="a\tb"`, map[string]string{"KEY": "a\tb"}},
		{"comment stripped", "KEY=value # trailing", map[string]string{"KEY": "value"}},
		{"hash inside quotes", `KEY="value#kept"`, map[string]string{"KEY": "value#kept"}},
		{"export prefix", "export KEY=value", map[string]string{"KEY": "value"}},
		{"whitespace around", "  KEY = value  ", map[string]string{"KEY": "value"}},
		{"comment line", "# just a comment\nKEY=v", map[string]string{"KEY": "v"}},
		{"blank lines", "\n\nKEY=v\n\n", map[string]string{"KEY": "v"}},
		{"equals in value", "KEY=a=b=c", map[string]string{"KEY": "a=b=c"}},
		{"single quoted literal backslash", `KEY='C:\path'`, map[string]string{"KEY": `C:\path`}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := Parse(tc.input).Values()

			if !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("Parse(%q).Values() = %#v, want %#v", tc.input, got, tc.want)
			}
		})
	}
}

func TestRenderRoundTrip(t *testing.T) {
	values := []string{
		"production",
		"",
		"two words",
		"value#notacomment",
		"p$ssw0rd",
		`say "hi"`,
		"it's fine",
		`C:\path\to`,
		"ends-with\\",
		"line1\nline2",
		"a\tb",
		"wachtwoord-mét-ünicode-🔐",
		"postgres://user:p@ss w0rd!#x@host:5432/db?sslmode=require",
		`{"key":"value","n":1}`,
		"-----BEGIN RSA PRIVATE KEY-----\nMIIEow==\n-----END RSA PRIVATE KEY-----",
		"a=b=c",
		"   ",
	}

	for _, value := range values {
		t.Run(value, func(t *testing.T) {
			rendered := Render(map[string]string{"SECRET": value})

			got, ok := Parse(rendered).Get("SECRET")
			if !ok {
				t.Fatalf("Parse(Render(%q)) lost the key; rendered as %q", value, rendered)
			}

			if got != value {
				t.Fatalf("round trip of %q gave %q (rendered as %q)", value, got, rendered)
			}
		})
	}
}

func TestRenderIsSorted(t *testing.T) {
	got := Render(map[string]string{"ZED": "z", "ALPHA": "a"})
	want := "ALPHA=a\nZED=z\n"

	if got != want {
		t.Fatalf("Render() = %q, want %q", got, want)
	}
}

func TestSetReplacesInPlaceAndKeepsComments(t *testing.T) {
	file := Parse("# header\nAPP_ENV=local\n\n# database\nDB_HOST=127.0.0.1\n")

	file.Set("APP_ENV", "production")

	want := "# header\nAPP_ENV=production\n\n# database\nDB_HOST=127.0.0.1\n"
	if got := file.String(); got != want {
		t.Fatalf("String() = %q, want %q", got, want)
	}
}

func TestSetAppendsUnknownKey(t *testing.T) {
	file := Parse("APP_ENV=local\n")

	file.Set("NEW_KEY", "value")

	if got := file.String(); got != "APP_ENV=local\nNEW_KEY=value\n" {
		t.Fatalf("String() = %q", got)
	}
}

func TestSetQuotesWhenNeeded(t *testing.T) {
	file := Parse("KEY=old\n")

	file.Set("KEY", "new value")

	if got := file.String(); got != "KEY=\"new value\"\n" {
		t.Fatalf("String() = %q", got)
	}
}

func TestMergeOnlyUpdatesKnownKeysByDefault(t *testing.T) {
	file := Parse("APP_ENV=local\nDB_HOST=127.0.0.1\n")

	result := file.Merge(map[string]string{
		"APP_ENV": "production",
		"NEW_ONE": "value",
		"DB_HOST": "127.0.0.1",
	}, false)

	if result.Updated != 1 || result.Added != 0 || result.Skipped != 1 || result.Unchanged != 1 {
		t.Fatalf("unexpected result %#v", result)
	}

	if strings.Contains(file.String(), "NEW_ONE") {
		t.Fatal("a conservative merge must not add unknown keys")
	}
}

func TestMergeAddsUnknownKeysWhenConstructive(t *testing.T) {
	file := Parse("APP_ENV=local\n")

	result := file.Merge(map[string]string{"APP_ENV": "production", "NEW_ONE": "value"}, true)

	if result.Added != 1 || result.Updated != 1 {
		t.Fatalf("unexpected result %#v", result)
	}

	if got, _ := file.Get("NEW_ONE"); got != "value" {
		t.Fatalf("NEW_ONE = %q", got)
	}
}

func TestMergeNeverRemovesLocalOnlyKeys(t *testing.T) {
	file := Parse("APP_ENV=local\nLOCAL_ONLY=keep-me\n")

	file.Merge(map[string]string{"APP_ENV": "production"}, true)

	if got, ok := file.Get("LOCAL_ONLY"); !ok || got != "keep-me" {
		t.Fatal("a pull must never delete a key that only exists locally")
	}
}

func TestParseHandlesFileWithoutTrailingNewline(t *testing.T) {
	file := Parse("A=1\nB=2")

	file.Set("B", "3")

	if got := file.String(); got != "A=1\nB=3\n" {
		t.Fatalf("String() = %q", got)
	}
}

func TestKeysAreInFileOrder(t *testing.T) {
	got := Parse("C=3\nA=1\nB=2\n").Keys()
	want := []string{"C", "A", "B"}

	if !reflect.DeepEqual(got, want) {
		t.Fatalf("Keys() = %v, want %v", got, want)
	}
}

func TestGetReturnsTheValueThatActuallyWins(t *testing.T) {
	// phpdotenv fills a map top to bottom, so a later assignment overrides an
	// earlier one. Reporting the first would describe a file nobody runs.
	got, ok := Parse("APP_ENV=first\nAPP_ENV=second\n").Get("APP_ENV")

	if !ok || got != "second" {
		t.Fatalf("Get() = %q, want %q", got, "second")
	}
}

func TestSetCollapsesDuplicateKeys(t *testing.T) {
	file := Parse("APP_ENV=first\nOTHER=x\nAPP_ENV=second\n")

	file.Set("APP_ENV", "written")

	want := "APP_ENV=written\nOTHER=x\n"
	if got := file.String(); got != want {
		t.Fatalf("String() = %q, want %q", got, want)
	}
}

func TestMergeLeavesNoShadowedDuplicate(t *testing.T) {
	file := Parse("APP_ENV=first\nAPP_ENV=stale\n")

	file.Merge(map[string]string{"APP_ENV": "production"}, false)

	if got, _ := Parse(file.String()).Get("APP_ENV"); got != "production" {
		t.Fatalf("after merge the effective value is %q, want %q", got, "production")
	}
}

func TestKeysReportsADuplicateOnce(t *testing.T) {
	if got := Parse("A=1\nA=2\nB=3\n").Keys(); !reflect.DeepEqual(got, []string{"A", "B"}) {
		t.Fatalf("Keys() = %v", got)
	}
}
