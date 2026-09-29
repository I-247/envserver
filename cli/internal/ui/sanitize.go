package ui

import "strings"

// Sanitize removes what a terminal would act on rather than show.
//
// Much of what the CLI prints came from the server: release messages, names,
// error texts, the device-login URL. An escape sequence in one of them can
// move the cursor, erase the line above, rewrite the window title or, with a
// carriage return, overwrite what was just printed, for instance to show a
// fake approval URL. Newlines and tabs survive, every other control
// character goes, and escape sequences go too, except colour and weight
// (SGR, `ESC [ ... m`) when keepStyle is set: the Printer adds those itself,
// often to text that was formatted into a sentence before it is written.
func Sanitize(text string, keepStyle bool) string {
	var b strings.Builder

	runes := []rune(text)

	for i := 0; i < len(runes); i++ {
		r := runes[i]

		switch {
		case r == '\x1b':
			end, isStyle := escapeEnd(runes, i)

			if isStyle && keepStyle {
				b.WriteString(string(runes[i : end+1]))
			}

			i = end
		case r == '\n' || r == '\t':
			b.WriteRune(r)
		case r < 0x20 || r == 0x7f || (r >= 0x80 && r <= 0x9f):
			// C0, DEL and C1 controls, including the single-byte CSI.
		default:
			b.WriteRune(r)
		}
	}

	return b.String()
}

// escapeEnd finds the last rune of the escape sequence starting at start,
// and reports whether it is a plain SGR sequence.
func escapeEnd(runes []rune, start int) (int, bool) {
	if start+1 >= len(runes) {
		return start, false
	}

	switch runes[start+1] {
	case '[':
		// CSI: parameter bytes, intermediate bytes, then one final byte.
		onlyStyleParameters := true

		for i := start + 2; i < len(runes); i++ {
			r := runes[i]

			switch {
			case r >= 0x40 && r <= 0x7e:
				return i, r == 'm' && onlyStyleParameters
			case (r >= '0' && r <= '9') || r == ';':
			case r >= 0x20 && r <= 0x3f:
				onlyStyleParameters = false
			default:
				// Not a well formed sequence: drop what was read so far.
				return i - 1, false
			}
		}

		return len(runes) - 1, false
	case ']', 'P', '_', '^', 'X':
		// OSC and the other string sequences run to BEL or ESC \.
		for i := start + 2; i < len(runes); i++ {
			if runes[i] == '\a' {
				return i, false
			}

			if runes[i] == '\x1b' && i+1 < len(runes) && runes[i+1] == '\\' {
				return i + 1, false
			}
		}

		return len(runes) - 1, false
	default:
		return start + 1, false
	}
}
