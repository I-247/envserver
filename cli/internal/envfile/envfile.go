// Package envfile reads and writes .env files the way phpdotenv does.
//
// The escaping rules are not obvious and getting them wrong corrupts secrets
// silently, so they are spelled out here:
//
//   - Inside double quotes a backslash starts an escape sequence. Only
//     \" \\ \$ \f \n \r \t \v are meaningful; anything else is a parse error
//     on the PHP side, so we never emit one.
//   - An unescaped $ means interpolation, even inside double quotes. A
//     password like p$ssw0rd must therefore be written as p\$ssw0rd.
//   - Single quotes take no escapes at all, which means a single quoted value
//     can never contain a single quote. We only ever write double quotes.
//   - An unquoted value ends at whitespace or a # comment marker.
package envfile

import (
	"fmt"
	"sort"
	"strings"
)

// File is a parsed .env file that remembers its own layout.
//
// Comments, blank lines and ordering are preserved so that pulling new values
// into an existing file leaves everything a person wrote about it intact.
type File struct {
	lines []line
}

type line struct {
	raw string
	key string // empty for comments and blank lines
}

// MergeResult reports what a Merge did, so the CLI can say it out loud.
type MergeResult struct {
	Added     int
	Updated   int
	Unchanged int
	Skipped   int // present remotely, absent locally, and not added
	Removed   int // present locally, absent remotely, and pruned

	// Changes is the per key decision the counters summarise, in key order.
	Changes []Change
}

// ChangeKind is what a merge decides to do with a single key.
type ChangeKind int

const (
	// KindUnchanged means the local value already equals the remote one.
	KindUnchanged ChangeKind = iota
	// KindUpdated means the key exists locally and gets the remote value.
	KindUpdated
	// KindAdded means the key is absent locally and is written.
	KindAdded
	// KindSkipped means the key is absent locally and a conservative merge
	// leaves it out.
	KindSkipped
	// KindRemoved means the key exists only locally and pruning deletes it.
	KindRemoved
)

// MergeOptions turns the safe default merge into a wider one.
//
// The zero value is the conservative pull: update what is already there,
// invent nothing, delete nothing.
type MergeOptions struct {
	// Constructive also writes keys the file does not have yet.
	Constructive bool
	// Prune deletes keys the file has and the release does not. Destructive
	// by nature, since a .env legitimately holds local only entries, so it is
	// never implied by Constructive.
	Prune bool
}

// Change is one key's fate in a merge.
type Change struct {
	Key  string
	Kind ChangeKind
}

// Parse reads the contents of a .env file.
func Parse(content string) *File {
	file := &File{}

	for _, raw := range strings.Split(strings.TrimSuffix(content, "\n"), "\n") {
		file.lines = append(file.lines, line{raw: raw, key: keyOf(raw)})
	}

	// An empty input splits into one empty line; drop it so an empty file
	// stays empty rather than growing a stray blank line on every write.
	if len(file.lines) == 1 && file.lines[0].raw == "" {
		file.lines = nil
	}

	return file
}

// Keys lists the keys in the order they appear, each one once.
func (f *File) Keys() []string {
	keys := make([]string, 0, len(f.lines))
	seen := make(map[string]bool, len(f.lines))

	for _, l := range f.lines {
		if l.key != "" && !seen[l.key] {
			seen[l.key] = true
			keys = append(keys, l.key)
		}
	}

	return keys
}

// Get reads a single value.
//
// A file can hold the same key twice, and phpdotenv fills its map top to
// bottom, so the last assignment is the one that actually takes effect.
// Reporting the first would describe a file nobody runs.
func (f *File) Get(key string) (string, bool) {
	for i := len(f.lines) - 1; i >= 0; i-- {
		if f.lines[i].key == key {
			return parseValue(valuePart(f.lines[i].raw)), true
		}
	}

	return "", false
}

// Values returns every key and the value that wins for it.
func (f *File) Values() map[string]string {
	values := make(map[string]string, len(f.lines))

	for _, l := range f.lines {
		if l.key != "" {
			values[l.key] = parseValue(valuePart(l.raw))
		}
	}

	return values
}

// Set writes a value.
//
// Writing to the first occurrence keeps the file's layout, and any later
// duplicate is dropped: leaving one behind would shadow the value we just
// wrote, so the tool would report a change it did not really make.
func (f *File) Set(key, value string) {
	entry := key + "=" + formatValue(value)
	written := false

	kept := f.lines[:0]

	for _, l := range f.lines {
		if l.key != key {
			kept = append(kept, l)

			continue
		}

		if written {
			continue
		}

		written = true
		l.raw = entry
		kept = append(kept, l)
	}

	f.lines = kept

	if !written {
		f.lines = append(f.lines, line{raw: entry, key: key})
	}
}

// Unset removes a key entirely, including any duplicate assignment of it.
//
// Comments and blank lines around it are left alone: they were written by a
// person about that part of the file, and a pull is not the right moment to
// decide they are now stale.
func (f *File) Unset(key string) {
	kept := f.lines[:0]

	for _, l := range f.lines {
		if l.key != key {
			kept = append(kept, l)
		}
	}

	f.lines = kept
}

// Merge applies remote values to the file.
//
// By default only keys that already exist locally are updated. That is the
// safe direction: a .env usually holds machine specific entries nobody wants
// a pull to invent, and a key you never asked for appearing in your file is
// more surprising than one that stays behind. MergeOptions widens it in
// either direction.
func (f *File) Merge(values map[string]string, options MergeOptions) MergeResult {
	result := Plan(f, values, options)

	for _, change := range result.Changes {
		switch change.Kind {
		case KindUpdated, KindAdded:
			f.Set(change.Key, values[change.Key])
		case KindRemoved:
			f.Unset(change.Key)
		case KindUnchanged, KindSkipped:
		}
	}

	return result
}

// Plan decides what Merge would do without touching the file.
//
// Deliberately the same decision code as Merge rather than a second reading
// of the rules: a dry run that disagrees with the real thing is worse than no
// dry run at all.
func Plan(f *File, values map[string]string, options MergeOptions) MergeResult {
	result := MergeResult{Changes: make([]Change, 0, len(values))}

	for _, key := range mergeKeys(f, values, options.Prune) {
		remote, onServer := values[key]
		current, exists := f.Get(key)

		var kind ChangeKind

		switch {
		case !onServer:
			kind = KindRemoved
			result.Removed++
		case exists && current == remote:
			kind = KindUnchanged
			result.Unchanged++
		case exists:
			kind = KindUpdated
			result.Updated++
		case options.Constructive:
			kind = KindAdded
			result.Added++
		default:
			kind = KindSkipped
			result.Skipped++
		}

		result.Changes = append(result.Changes, Change{Key: key, Kind: kind})
	}

	return result
}

// mergeKeys lists every key a merge has to decide about, in key order.
//
// Local only keys join the list only when pruning: without it they are not a
// decision at all, and reporting them as "unchanged" would suggest the
// release knows about them.
func mergeKeys(f *File, values map[string]string, prune bool) []string {
	keys := sortedKeys(values)

	if !prune {
		return keys
	}

	for _, key := range f.Keys() {
		if _, onServer := values[key]; onServer {
			continue
		}

		// envclient's own credentials can live in this same file (see
		// config.LoadDeployEnv). A release never mentions them, so without
		// this a prune would delete the very credential that fetched it.
		if strings.HasPrefix(key, "ENVCLIENT_") {
			continue
		}

		keys = append(keys, key)
	}

	sort.Strings(keys)

	return keys
}

// String renders the file back to text.
func (f *File) String() string {
	if len(f.lines) == 0 {
		return ""
	}

	raw := make([]string, len(f.lines))
	for i, l := range f.lines {
		raw[i] = l.raw
	}

	return strings.Join(raw, "\n") + "\n"
}

// Render writes a fresh .env file from a map, sorted by key.
func Render(values map[string]string) string {
	var b strings.Builder

	for _, key := range sortedKeys(values) {
		fmt.Fprintf(&b, "%s=%s\n", key, formatValue(values[key]))
	}

	return b.String()
}

// keyOf extracts the key from a line, or "" when the line holds no assignment.
func keyOf(raw string) string {
	trimmed := strings.TrimSpace(raw)

	if trimmed == "" || strings.HasPrefix(trimmed, "#") {
		return ""
	}

	trimmed = strings.TrimPrefix(trimmed, "export ")

	name, _, found := strings.Cut(trimmed, "=")
	if !found {
		return ""
	}

	name = strings.TrimSpace(name)
	if name == "" || !isValidKey(name) {
		return ""
	}

	return name
}

func isValidKey(name string) bool {
	for i, r := range name {
		switch {
		case r == '_':
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z':
		case r >= '0' && r <= '9' && i > 0:
		default:
			return false
		}
	}

	return true
}

// valuePart returns everything after the first '=' on an assignment line.
func valuePart(raw string) string {
	_, value, _ := strings.Cut(raw, "=")

	return value
}

// parseValue turns the raw right hand side into the value it denotes.
func parseValue(raw string) string {
	raw = strings.TrimSpace(raw)

	if raw == "" {
		return ""
	}

	switch raw[0] {
	case '\'':
		return readSingleQuoted(raw)
	case '"':
		return readDoubleQuoted(raw)
	default:
		return readUnquoted(raw)
	}
}

// readUnquoted reads up to a comment marker; the value cannot contain spaces
// before one, matching how phpdotenv ends an unquoted value at whitespace.
func readUnquoted(raw string) string {
	if index := strings.Index(raw, "#"); index >= 0 {
		raw = raw[:index]
	}

	return strings.TrimSpace(raw)
}

// readSingleQuoted takes everything up to the closing quote, literally.
func readSingleQuoted(raw string) string {
	if end := strings.Index(raw[1:], "'"); end >= 0 {
		return raw[1 : 1+end]
	}

	return raw[1:]
}

// readDoubleQuoted resolves escape sequences up to the closing quote.
func readDoubleQuoted(raw string) string {
	var b strings.Builder

	for i := 1; i < len(raw); i++ {
		c := raw[i]

		if c == '"' {
			break
		}

		if c != '\\' || i+1 >= len(raw) {
			b.WriteByte(c)

			continue
		}

		i++

		switch raw[i] {
		case 'n':
			b.WriteByte('\n')
		case 'r':
			b.WriteByte('\r')
		case 't':
			b.WriteByte('\t')
		case 'f':
			b.WriteByte('\f')
		case 'v':
			b.WriteByte('\v')
		default:
			// Covers \" \\ \$ and, defensively, anything else.
			b.WriteByte(raw[i])
		}
	}

	return b.String()
}

// formatValue renders a value, quoting and escaping only when needed.
func formatValue(value string) string {
	if value == "" {
		return ""
	}

	if isBare(value) {
		return value
	}

	var b strings.Builder
	b.WriteByte('"')

	for i := 0; i < len(value); i++ {
		switch value[i] {
		case '\\':
			b.WriteString(`\\`)
		case '"':
			b.WriteString(`\"`)
		case '$':
			b.WriteString(`\$`)
		case '\n':
			b.WriteString(`\n`)
		case '\r':
			b.WriteString(`\r`)
		case '\t':
			b.WriteString(`\t`)
		case '\f':
			b.WriteString(`\f`)
		case '\v':
			b.WriteString(`\v`)
		default:
			b.WriteByte(value[i])
		}
	}

	b.WriteByte('"')

	return b.String()
}

// isBare reports whether a value can be written without quotes.
func isBare(value string) bool {
	for i := 0; i < len(value); i++ {
		switch value[i] {
		case ' ', '\t', '\n', '\r', '\f', '\v', '\\', '\'', '"', '#', '$':
			return false
		}
	}

	return true
}

func sortedKeys(values map[string]string) []string {
	keys := make([]string, 0, len(values))
	for key := range values {
		keys = append(keys, key)
	}

	sort.Strings(keys)

	return keys
}
