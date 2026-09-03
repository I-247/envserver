// Package selfupdate finds, downloads and installs the latest envclient
// release from GitHub.
package selfupdate

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

// Repo is the GitHub repository releases are published to.
const Repo = "I-247/envserver"

// APIBase and DownloadBase point at GitHub. Tests override them to talk to a
// local server instead.
var (
	APIBase      = "https://api.github.com"
	DownloadBase = "https://github.com"
)

// Release is the subset of a GitHub release this package needs.
type Release struct {
	TagName string `json:"tag_name"`
}

// Version is the release's tag without its leading "v".
func (r Release) Version() string {
	return strings.TrimPrefix(r.TagName, "v")
}

// Latest fetches the newest published release.
func Latest(ctx context.Context, client *http.Client) (*Release, error) {
	body, err := get(ctx, client, fmt.Sprintf("%s/repos/%s/releases/latest", APIBase, Repo))
	if err != nil {
		return nil, err
	}

	var release Release
	if err := json.Unmarshal(body, &release); err != nil {
		return nil, err
	}

	if release.TagName == "" {
		return nil, fmt.Errorf("the latest release has no tag")
	}

	return &release, nil
}

// AssetName is the archive name GoReleaser publishes for a version and
// platform, matching cli/.goreleaser.yaml's name_template.
func AssetName(version, goos, goarch string) string {
	return fmt.Sprintf("envclient_%s_%s_%s.tar.gz", version, goos, goarch)
}

// Download fetches one asset from a release.
func Download(ctx context.Context, client *http.Client, tag, name string) ([]byte, error) {
	return get(ctx, client, fmt.Sprintf("%s/%s/releases/download/%s/%s", DownloadBase, Repo, tag, name))
}

func get(ctx context.Context, client *http.Client, url string) ([]byte, error) {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	request.Header.Set("Accept", "application/vnd.github+json")

	response, err := client.Do(request)
	if err != nil {
		return nil, err
	}
	defer response.Body.Close()

	body, err := io.ReadAll(response.Body)
	if err != nil {
		return nil, err
	}

	if response.StatusCode == http.StatusNotFound {
		return nil, fmt.Errorf("nothing found at %s — has a release been published yet?", url)
	}

	if response.StatusCode >= 400 {
		return nil, fmt.Errorf("%s replied %d fetching %s", DownloadBase, response.StatusCode, url)
	}

	return body, nil
}

// VerifyChecksum checks an archive's sha256 against the line naming it in a
// GoReleaser checksums.txt.
func VerifyChecksum(archive, checksums []byte, name string) error {
	sum := sha256.Sum256(archive)
	got := hex.EncodeToString(sum[:])

	for _, line := range strings.Split(string(checksums), "\n") {
		fields := strings.Fields(line)
		if len(fields) != 2 || fields[1] != name {
			continue
		}

		if fields[0] != got {
			return fmt.Errorf("checksum mismatch for %s: expected %s, got %s", name, fields[0], got)
		}

		return nil
	}

	return fmt.Errorf("%s is not listed in checksums.txt", name)
}

// ExtractBinary pulls a single regular file out of a gzip-compressed tar
// archive by its base name.
func ExtractBinary(archive []byte, name string) ([]byte, error) {
	gz, err := gzip.NewReader(bytes.NewReader(archive))
	if err != nil {
		return nil, err
	}
	defer gz.Close()

	reader := tar.NewReader(gz)

	for {
		header, err := reader.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, err
		}

		if header.Typeflag != tar.TypeReg || filepath.Base(header.Name) != name {
			continue
		}

		return io.ReadAll(reader)
	}

	return nil, fmt.Errorf("%s not found in the archive", name)
}

// Install atomically replaces the file at dest with binary.
//
// The new file is written into dest's own directory first, so the rename
// that follows lands on one filesystem. That also makes it safe to replace a
// running binary: the OS keeps a process's executable open by inode, not by
// path, so the process using the old name keeps running under it until it
// exits, and the new name is what the next launch picks up.
func Install(binary []byte, dest string) error {
	dir := filepath.Dir(dest)

	temp, err := os.CreateTemp(dir, ".envclient-update-*")
	if err != nil {
		return fmt.Errorf("cannot write to %s: %w (try running this with sudo)", dir, err)
	}
	tempPath := temp.Name()
	defer os.Remove(tempPath) // no-op once the rename below succeeds

	if _, err := temp.Write(binary); err != nil {
		temp.Close()

		return err
	}

	if err := temp.Close(); err != nil {
		return err
	}

	if err := os.Chmod(tempPath, 0o755); err != nil {
		return err
	}

	return os.Rename(tempPath, dest)
}
