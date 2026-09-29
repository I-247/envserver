package securefile

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestWriteFileCreatesAnOwnerOnlyFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), ".env")

	if err := WriteFile(path, []byte("A=1\n")); err != nil {
		t.Fatal(err)
	}

	assertFile(t, path, "A=1\n")
}

func TestWriteFileNarrowsAnExistingWiderMode(t *testing.T) {
	path := filepath.Join(t.TempDir(), ".env")

	if err := os.WriteFile(path, []byte("OLD=1\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := WriteFile(path, []byte("NEW=1\n")); err != nil {
		t.Fatal(err)
	}

	assertFile(t, path, "NEW=1\n")
}

func TestWriteFileRefusesASymlink(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlinks need privileges on Windows")
	}

	dir := t.TempDir()
	target := filepath.Join(dir, "bashrc")
	link := filepath.Join(dir, ".env")

	if err := os.WriteFile(target, []byte("keep me\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}

	if err := WriteFile(link, []byte("A=1\n")); err == nil {
		t.Fatal("wrote through a symlink")
	}

	contents, _ := os.ReadFile(target)
	if string(contents) != "keep me\n" {
		t.Fatalf("symlink target changed to %q", contents)
	}
}

func TestWriteFileLeavesNoTemporaryFileBehind(t *testing.T) {
	dir := t.TempDir()

	if err := WriteFile(filepath.Join(dir, ".env"), []byte("A=1\n")); err != nil {
		t.Fatal(err)
	}

	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Fatalf("directory holds %d entries, want only .env", len(entries))
	}
}

func TestPrivateDirNarrowsAnExistingDirectory(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("unix permissions")
	}

	dir := filepath.Join(t.TempDir(), "envclient")

	if err := os.Mkdir(dir, 0o755); err != nil {
		t.Fatal(err)
	}

	if err := PrivateDir(dir); err != nil {
		t.Fatal(err)
	}

	info, _ := os.Stat(dir)
	if info.Mode().Perm() != 0o700 {
		t.Fatalf("mode = %o, want 700", info.Mode().Perm())
	}
}

func assertFile(t *testing.T, path, want string) {
	t.Helper()

	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	if string(contents) != want {
		t.Fatalf("contents = %q, want %q", contents, want)
	}

	if runtime.GOOS == "windows" {
		return
	}

	info, _ := os.Stat(path)
	if info.Mode().Perm() != Mode {
		t.Fatalf("mode = %o, want %o", info.Mode().Perm(), Mode)
	}
}
