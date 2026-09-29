package selfupdate

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"strings"
	"testing"

	"github.com/sigstore/sigstore-go/pkg/testing/ca"
)

const releaseTag = "v1.4.0"

func releaseIdentity(tag string) string {
	return "https://github.com/" + Repo + "/.github/workflows/release.yml@refs/tags/" + tag
}

func virtualSigstore(t *testing.T) *ca.VirtualSigstore {
	t.Helper()

	sigstore, err := ca.NewVirtualSigstore()
	if err != nil {
		t.Fatal(err)
	}

	return sigstore
}

func TestAReleaseSignedByTheReleaseWorkflowVerifies(t *testing.T) {
	sigstore := virtualSigstore(t)
	checksums := []byte("abc  envclient_1.4.0_darwin_arm64.tar.gz\n")

	entity, err := sigstore.Sign(releaseIdentity(releaseTag), Issuer, checksums)
	if err != nil {
		t.Fatal(err)
	}

	if err := verifyEntity(sigstore, entity, checksums, releaseTag); err != nil {
		t.Fatalf("a correctly signed release was refused: %v", err)
	}
}

func TestTamperedChecksumsAreRefused(t *testing.T) {
	sigstore := virtualSigstore(t)
	signed := []byte("abc  envclient_1.4.0_darwin_arm64.tar.gz\n")

	entity, err := sigstore.Sign(releaseIdentity(releaseTag), Issuer, signed)
	if err != nil {
		t.Fatal(err)
	}

	tampered := []byte("evil  envclient_1.4.0_darwin_arm64.tar.gz\n")

	if err := verifyEntity(sigstore, entity, tampered, releaseTag); err == nil {
		t.Fatal("checksums that differ from what was signed were accepted")
	}
}

func TestASignatureFromAnyOtherIdentityIsRefused(t *testing.T) {
	cases := map[string]struct{ identity, issuer string }{
		"a fork":                {"https://github.com/someone/envserver/.github/workflows/release.yml@refs/tags/" + releaseTag, Issuer},
		"another workflow":      {"https://github.com/" + Repo + "/.github/workflows/cli.yml@refs/tags/" + releaseTag, Issuer},
		"a branch run":          {"https://github.com/" + Repo + "/.github/workflows/release.yml@refs/heads/main", Issuer},
		"a different tag":       {releaseIdentity("v1.3.9"), Issuer},
		"a suffix on the tag":   {releaseIdentity(releaseTag + "-evil"), Issuer},
		"another OIDC provider": {releaseIdentity(releaseTag), "https://accounts.google.com"},
	}

	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			sigstore := virtualSigstore(t)
			checksums := []byte("abc  envclient.tar.gz\n")

			entity, err := sigstore.Sign(c.identity, c.issuer, checksums)
			if err != nil {
				t.Fatal(err)
			}

			if err := verifyEntity(sigstore, entity, checksums, releaseTag); err == nil {
				t.Fatalf("a signature by %s (%s) was accepted", c.identity, c.issuer)
			}
		})
	}
}

func TestASignatureFromAnotherRootOfTrustIsRefused(t *testing.T) {
	checksums := []byte("abc  envclient.tar.gz\n")

	entity, err := virtualSigstore(t).Sign(releaseIdentity(releaseTag), Issuer, checksums)
	if err != nil {
		t.Fatal(err)
	}

	if err := verifyEntity(virtualSigstore(t), entity, checksums, releaseTag); err == nil {
		t.Fatal("a signature from an untrusted Sigstore instance was accepted")
	}
}

func TestAnEmptyOrUnreadableSignatureIsRefused(t *testing.T) {
	verifier := SigstoreVerifier{Trusted: virtualSigstore(t)}

	if err := verifier.Verify(context.Background(), []byte("x"), nil, releaseTag); !errors.Is(err, ErrUnsigned) {
		t.Fatalf("err = %v, want ErrUnsigned", err)
	}

	if err := verifier.Verify(context.Background(), []byte("x"), []byte("{not a bundle"), releaseTag); err == nil {
		t.Fatal("an unreadable bundle was accepted")
	}
}

// recordingVerifier stands in for Sigstore in the download tests, which are
// about ordering and refusal rather than cryptography.
type recordingVerifier struct {
	err    error
	called bool
}

func (v *recordingVerifier) Verify(_ context.Context, _, _ []byte, _ string) error {
	v.called = true

	return v.err
}

func releaseServer(t *testing.T, assets map[string][]byte, requested *[]string) {
	t.Helper()

	server := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		name := r.URL.Path[strings.LastIndex(r.URL.Path, "/")+1:]
		*requested = append(*requested, name)

		body, ok := assets[name]
		if !ok {
			http.NotFound(w, r)

			return
		}

		_, _ = w.Write(body)
	})

	previous := DownloadBase
	DownloadBase = server.URL
	t.Cleanup(func() { DownloadBase = previous })
}

func checksumLine(archive []byte, name string) []byte {
	sum := sha256.Sum256(archive)

	return []byte(hex.EncodeToString(sum[:]) + "  " + name + "\n")
}

func TestFetchVerifiedRefusesAReleaseWithoutASignature(t *testing.T) {
	archive := []byte("archive")
	var requested []string

	releaseServer(t, map[string][]byte{
		"checksums.txt":    checksumLine(archive, "envclient.tar.gz"),
		"envclient.tar.gz": archive,
	}, &requested)

	verifier := &recordingVerifier{}

	_, err := FetchVerified(context.Background(), http.DefaultClient, verifier, releaseTag, "envclient.tar.gz")

	if !errors.Is(err, ErrUnsigned) {
		t.Fatalf("err = %v, want ErrUnsigned", err)
	}

	for _, name := range requested {
		if name == "envclient.tar.gz" {
			t.Fatal("the archive was downloaded before the release was known to be signed")
		}
	}
}

func TestFetchVerifiedRefusesWhenTheSignatureDoesNotVerify(t *testing.T) {
	archive := []byte("archive")
	var requested []string

	releaseServer(t, map[string][]byte{
		"checksums.txt":    checksumLine(archive, "envclient.tar.gz"),
		SignatureAsset:     []byte("{}"),
		"envclient.tar.gz": archive,
	}, &requested)

	verifier := &recordingVerifier{err: errors.New("the release signature does not verify")}

	if _, err := FetchVerified(context.Background(), http.DefaultClient, verifier, releaseTag, "envclient.tar.gz"); err == nil {
		t.Fatal("a release whose signature failed was installed")
	}
}

func TestFetchVerifiedChecksTheArchiveAgainstTheSignedChecksums(t *testing.T) {
	archive := []byte("archive")
	var requested []string

	releaseServer(t, map[string][]byte{
		"checksums.txt":    checksumLine([]byte("what was signed"), "envclient.tar.gz"),
		SignatureAsset:     []byte("{}"),
		"envclient.tar.gz": archive,
	}, &requested)

	verifier := &recordingVerifier{}

	if _, err := FetchVerified(context.Background(), http.DefaultClient, verifier, releaseTag, "envclient.tar.gz"); err == nil {
		t.Fatal("an archive that does not match the signed checksums was accepted")
	}

	if !verifier.called {
		t.Fatal("the signature was never checked")
	}
}

func TestFetchVerifiedReturnsAVerifiedArchive(t *testing.T) {
	archive := []byte("archive")
	var requested []string

	releaseServer(t, map[string][]byte{
		"checksums.txt":    checksumLine(archive, "envclient.tar.gz"),
		SignatureAsset:     []byte("{}"),
		"envclient.tar.gz": archive,
	}, &requested)

	got, err := FetchVerified(context.Background(), http.DefaultClient, &recordingVerifier{}, releaseTag, "envclient.tar.gz")
	if err != nil {
		t.Fatal(err)
	}

	if string(got) != "archive" {
		t.Fatalf("archive = %q", got)
	}
}
