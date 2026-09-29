// Package securefile writes files that hold secrets.
//
// os.WriteFile is not enough for those: it follows a symlink, so a
// repository that commits .env as a link to ~/.bashrc gets that file
// rewritten; it only applies its mode when the file is new, so a .env that
// was 0644 stays readable to everyone after a pull; and it truncates before
// writing, so a crash halfway leaves half a file.
package securefile

import (
	"fmt"
	"os"
	"path/filepath"
)

// Mode is what every file written here ends up with.
const Mode os.FileMode = 0o600

// WriteFile replaces path with data, readable by the owner only.
//
// It refuses a symlink or anything else that is not a regular file, writes
// to a temporary file next to the target and renames it over the target, so
// the path holds either the old contents or the new ones, never a mix.
func WriteFile(path string, data []byte) error {
	info, err := os.Lstat(path)

	switch {
	case err == nil && info.Mode()&os.ModeSymlink != 0:
		return fmt.Errorf("refusing to write %s: it is a symlink, and following it could overwrite a file elsewhere", path)
	case err == nil && !info.Mode().IsRegular():
		return fmt.Errorf("refusing to write %s: it is not a regular file", path)
	case err != nil && !os.IsNotExist(err):
		return err
	}

	temp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".*.tmp")
	if err != nil {
		return err
	}

	// Removing after a successful rename fails harmlessly: the name is gone.
	defer os.Remove(temp.Name())

	if err := writeAndSync(temp, data); err != nil {
		temp.Close()

		return err
	}

	if err := temp.Close(); err != nil {
		return err
	}

	return os.Rename(temp.Name(), path)
}

func writeAndSync(file *os.File, data []byte) error {
	if err := file.Chmod(Mode); err != nil {
		return err
	}

	if _, err := file.Write(data); err != nil {
		return err
	}

	return file.Sync()
}

// PrivateDir creates dir for the owner only, and narrows it to 0700 when it
// already existed with a wider mode.
func PrivateDir(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}

	info, err := os.Stat(dir)
	if err != nil {
		return err
	}

	if info.Mode().Perm()&0o077 != 0 {
		return os.Chmod(dir, 0o700)
	}

	return nil
}
