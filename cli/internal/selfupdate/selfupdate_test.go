package selfupdate

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func withServer(t *testing.T, handler http.HandlerFunc) *httptest.Server {
	t.Helper()

	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)

	return server
}

func TestLatestReturnsTheTagName(t *testing.T) {
	server := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/repos/"+Repo+"/releases/latest" {
			t.Fatalf("unexpected path: %s", r.URL.Path)
		}

		w.Write([]byte(`{"tag_name": "v1.2.3"}`))
	})

	previous := APIBase
	APIBase = server.URL
	defer func() { APIBase = previous }()

	release, err := Latest(context.Background(), server.Client())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if release.Version() != "1.2.3" {
		t.Fatalf("expected version 1.2.3, got %s", release.Version())
	}
}

func TestLatestFailsWhenNoReleaseHasBeenPublished(t *testing.T) {
	server := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	})

	previous := APIBase
	APIBase = server.URL
	defer func() { APIBase = previous }()

	if _, err := Latest(context.Background(), server.Client()); err == nil {
		t.Fatal("expected an error, got none")
	}
}

func TestAssetNameMatchesTheGoreleaserTemplate(t *testing.T) {
	got := AssetName("1.2.3", "linux", "arm64")
	want := "envclient_1.2.3_linux_arm64.tar.gz"

	if got != want {
		t.Fatalf("expected %s, got %s", want, got)
	}
}

func TestVerifyChecksumAcceptsAMatchingSum(t *testing.T) {
	archive := []byte("archive contents")
	sum := sha256.Sum256(archive)
	checksums := []byte(hex.EncodeToString(sum[:]) + "  envclient_1.0.0_linux_amd64.tar.gz\n" +
		"deadbeef  some_other_file.tar.gz\n")

	if err := VerifyChecksum(archive, checksums, "envclient_1.0.0_linux_amd64.tar.gz"); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestVerifyChecksumRejectsATamperedArchive(t *testing.T) {
	checksums := []byte("0000000000000000000000000000000000000000000000000000000000000000  envclient_1.0.0_linux_amd64.tar.gz\n")

	err := VerifyChecksum([]byte("not what was signed"), checksums, "envclient_1.0.0_linux_amd64.tar.gz")
	if err == nil || !strings.Contains(err.Error(), "checksum mismatch") {
		t.Fatalf("expected a checksum mismatch error, got %v", err)
	}
}

func TestVerifyChecksumRejectsAnUnlistedAsset(t *testing.T) {
	err := VerifyChecksum([]byte("x"), []byte("deadbeef  something_else.tar.gz\n"), "envclient_1.0.0_linux_amd64.tar.gz")
	if err == nil {
		t.Fatal("expected an error, got none")
	}
}

func tarGz(t *testing.T, files map[string][]byte) []byte {
	t.Helper()

	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)

	for name, contents := range files {
		if err := tw.WriteHeader(&tar.Header{
			Name: name,
			Mode: 0o755,
			Size: int64(len(contents)),
		}); err != nil {
			t.Fatalf("writing header: %v", err)
		}

		if _, err := tw.Write(contents); err != nil {
			t.Fatalf("writing contents: %v", err)
		}
	}

	if err := tw.Close(); err != nil {
		t.Fatalf("closing tar writer: %v", err)
	}

	if err := gz.Close(); err != nil {
		t.Fatalf("closing gzip writer: %v", err)
	}

	return buf.Bytes()
}

func TestExtractBinaryFindsTheNamedFile(t *testing.T) {
	archive := tarGz(t, map[string][]byte{
		"README.md": []byte("not this one"),
		"envclient": []byte("#!binary contents"),
	})

	got, err := ExtractBinary(archive, "envclient")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if string(got) != "#!binary contents" {
		t.Fatalf("got unexpected contents: %q", got)
	}
}

func TestExtractBinaryFailsWhenTheFileIsMissing(t *testing.T) {
	archive := tarGz(t, map[string][]byte{"README.md": []byte("x")})

	if _, err := ExtractBinary(archive, "envclient"); err == nil {
		t.Fatal("expected an error, got none")
	}
}

func TestInstallReplacesTheDestinationInPlace(t *testing.T) {
	dir := t.TempDir()
	dest := filepath.Join(dir, "envclient")

	if err := os.WriteFile(dest, []byte("old binary"), 0o755); err != nil {
		t.Fatalf("seeding old binary: %v", err)
	}

	if err := Install([]byte("new binary"), dest); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	got, err := os.ReadFile(dest)
	if err != nil {
		t.Fatalf("reading installed binary: %v", err)
	}

	if string(got) != "new binary" {
		t.Fatalf("expected the new contents, got %q", got)
	}

	info, err := os.Stat(dest)
	if err != nil {
		t.Fatalf("stat: %v", err)
	}

	if info.Mode().Perm()&0o111 == 0 {
		t.Fatalf("expected the installed binary to be executable, got mode %v", info.Mode())
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatalf("reading dir: %v", err)
	}

	if len(entries) != 1 {
		t.Fatalf("expected the temp file to be gone, found %d entries", len(entries))
	}
}

func TestDownloadFetchesAnAsset(t *testing.T) {
	server := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/"+Repo+"/releases/download/v1.0.0/envclient_1.0.0_linux_amd64.tar.gz" {
			t.Fatalf("unexpected path: %s", r.URL.Path)
		}

		w.Write([]byte("archive bytes"))
	})

	previous := DownloadBase
	DownloadBase = server.URL
	defer func() { DownloadBase = previous }()

	body, err := Download(context.Background(), server.Client(), "v1.0.0", "envclient_1.0.0_linux_amd64.tar.gz")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if string(body) != "archive bytes" {
		t.Fatalf("got unexpected body: %q", body)
	}
}
