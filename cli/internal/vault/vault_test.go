package vault

import (
	"errors"
	"strings"
	"testing"
)

func payload() Payload {
	return Payload{
		Release:     42,
		Project:     "webshop",
		Environment: "production",
		SealedAt:    "2026-08-25T10:00:00Z",
		Variables: map[string]string{
			"APP_KEY":       "base64:aGVsbG8=",
			"DB_PASSWORD":   "p$ssw0rd with spaces",
			"MULTILINE_KEY": "line one\nline two",
		},
	}
}

func TestSealAndOpenRoundTrip(t *testing.T) {
	secret := FromDeployToken("client-1", "s3cret-with-plenty-of-entropy")

	sealed, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	opened, err := Open(sealed, secret)
	if err != nil {
		t.Fatal(err)
	}

	if opened.Release != 42 || opened.Project != "webshop" || opened.Environment != "production" {
		t.Fatalf("metadata = %#v", opened)
	}

	for key, want := range payload().Variables {
		if opened.Variables[key] != want {
			t.Fatalf("%s = %q, want %q", key, opened.Variables[key], want)
		}
	}
}

// The whole point of the file: nothing readable may survive in it.
func TestSealedFileLeaksNeitherValuesNorMetadata(t *testing.T) {
	sealed, err := Seal(payload(), FromDeployToken("client-1", "s3cret-with-plenty-of-entropy"))
	if err != nil {
		t.Fatal(err)
	}

	for _, secret := range []string{"p$ssw0rd", "DB_PASSWORD", "webshop", "production", "aGVsbG8"} {
		if strings.Contains(sealed, secret) {
			t.Fatalf("sealed file contains %q", secret)
		}
	}
}

func TestSealIsDifferentEveryTime(t *testing.T) {
	secret := FromDeployToken("client-1", "s3cret-with-plenty-of-entropy")

	first, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	second, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	// A fresh salt and nonce per seal, so two files of the same release do
	// not betray that they hold the same values.
	if first == second {
		t.Fatal("sealing the same payload twice produced identical bytes")
	}
}

func TestOpeningWithAnotherTokenIsRecognisedAsSuch(t *testing.T) {
	sealed, err := Seal(payload(), FromDeployToken("client-1", "s3cret-with-plenty-of-entropy"))
	if err != nil {
		t.Fatal(err)
	}

	_, err = Open(sealed, FromDeployToken("client-1", "a-completely-different-secret"))
	if !errors.Is(err, ErrWrongKey) {
		t.Fatalf("err = %v, want ErrWrongKey", err)
	}
}

// Same secret, other client id: the label must keep the keys apart, otherwise
// a rotated client with a reused secret would silently open old files.
func TestTheClientIdSeparatesKeysDerivedFromOneSecret(t *testing.T) {
	sealed, err := Seal(payload(), FromDeployToken("client-1", "s3cret-with-plenty-of-entropy"))
	if err != nil {
		t.Fatal(err)
	}

	if _, err := Open(sealed, FromDeployToken("client-2", "s3cret-with-plenty-of-entropy")); !errors.Is(err, ErrWrongKey) {
		t.Fatalf("err = %v, want ErrWrongKey", err)
	}
}

func TestTamperingWithTheCiphertextIsRefused(t *testing.T) {
	secret := FromDeployToken("client-1", "s3cret-with-plenty-of-entropy")

	sealed, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	parts := strings.Split(strings.TrimSpace(sealed), ".")
	parts[4] = flipLast(parts[4])

	if _, err := Open(strings.Join(parts, "."), secret); !errors.Is(err, ErrCorrupt) {
		t.Fatalf("err = %v, want ErrCorrupt", err)
	}
}

// The header is not encrypted, so it has to be authenticated instead.
func TestTamperingWithTheHeaderIsRefused(t *testing.T) {
	secret := FromDeployToken("client-1", "s3cret-with-plenty-of-entropy")

	sealed, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	other, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	mine := strings.Split(strings.TrimSpace(sealed), ".")
	theirs := strings.Split(strings.TrimSpace(other), ".")
	mine[2] = theirs[2]

	if _, err := Open(strings.Join(mine, "."), secret); err == nil {
		t.Fatal("a swapped header was accepted")
	}
}

func TestOpeningSomethingThatIsNotAVault(t *testing.T) {
	secret := FromDeployToken("client-1", "s3cret-with-plenty-of-entropy")

	for _, contents := range []string{"", "APP_ENV=production\n", "envclient-vault.v1.short"} {
		if _, err := Open(contents, secret); !errors.Is(err, ErrCorrupt) {
			t.Fatalf("Open(%q) err = %v, want ErrCorrupt", contents, err)
		}
	}
}

func TestAFutureFormatSaysSoInsteadOfFailingToDecrypt(t *testing.T) {
	secret := FromDeployToken("client-1", "s3cret-with-plenty-of-entropy")

	sealed, err := Seal(payload(), secret)
	if err != nil {
		t.Fatal(err)
	}

	parts := strings.Split(strings.TrimSpace(sealed), ".")
	parts[1] = "v2"

	_, err = Open(strings.Join(parts, "."), secret)
	if err == nil || !strings.Contains(err.Error(), "upgrade the CLI") {
		t.Fatalf("err = %v, want an upgrade instruction", err)
	}
}

func TestFromKeyAcceptsHexBase64AndPlainText(t *testing.T) {
	for _, value := range []string{
		"6f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f6f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f",
		"c2VjcmV0LW1hdGVyaWFsLXRoYXQtaXMtbG9uZy1lbm91Z2g=",
		"a passphrase long enough to be worth something",
	} {
		secret, err := FromKey(value)
		if err != nil {
			t.Fatalf("FromKey(%q): %v", value, err)
		}

		sealed, err := Seal(payload(), secret)
		if err != nil {
			t.Fatal(err)
		}

		if _, err := Open(sealed, secret); err != nil {
			t.Fatalf("round trip for %q: %v", value, err)
		}
	}
}

func TestFromKeyRefusesSomethingTooShortToBeAKey(t *testing.T) {
	if _, err := FromKey("hunter2"); err == nil {
		t.Fatal("a seven character key was accepted")
	}
}

func TestKeyIDIsReadableWithoutTheSecret(t *testing.T) {
	sealed, err := Seal(payload(), FromDeployToken("client-1", "s3cret-with-plenty-of-entropy"))
	if err != nil {
		t.Fatal(err)
	}

	id, err := KeyID(sealed)
	if err != nil {
		t.Fatal(err)
	}

	if len(id) != idLength*2 {
		t.Fatalf("KeyID() = %q", id)
	}
}

func flipLast(encoded string) string {
	last := encoded[len(encoded)-1:]

	replacement := "A"
	if last == "A" {
		replacement = "B"
	}

	return encoded[:len(encoded)-1] + replacement
}
