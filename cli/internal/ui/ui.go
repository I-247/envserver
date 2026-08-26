// Package ui renders what the commands have to say.
//
// Everything a command prints goes through a Printer, for two reasons. The
// obvious one is that the output then looks the same everywhere. The less
// obvious one is colour: a terminal wants it and a pipe does not, and that
// decision belongs in one place rather than at every Fprintf.
package ui

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"strings"
)

// ANSI select graphic rendition codes. Deliberately the plain sixteen colour
// set: it is the one every terminal, CI runner and multiplexer agrees on.
const (
	reset  = "\x1b[0m"
	bold   = "\x1b[1m"
	dim    = "\x1b[2m"
	red    = "\x1b[31m"
	green  = "\x1b[32m"
	yellow = "\x1b[33m"
	blue   = "\x1b[34m"
	cyan   = "\x1b[36m"
)

// Printer writes styled output to a command's streams.
type Printer struct {
	out    io.Writer
	err    io.Writer
	colour bool
}

// New builds a Printer that colours only when it makes sense to.
func New(out, errOut io.Writer) *Printer {
	return &Printer{out: out, err: errOut, colour: colourWanted(out)}
}

// Plain returns a copy that never colours, for output that is data rather
// than a message: a rendered .env is going somewhere else and must arrive
// exactly as it was written.
func (p *Printer) Plain() *Printer {
	return &Printer{out: p.out, err: p.err, colour: false}
}

// SetColour overrides the detection, for --no-color.
func (p *Printer) SetColour(on bool) {
	p.colour = on && colourWanted(p.out)
}

// Out and Err expose the underlying streams for raw writes.
func (p *Printer) Out() io.Writer { return p.out }
func (p *Printer) Err() io.Writer { return p.err }

// colourWanted decides whether escape codes would help or corrupt.
//
// The order matters: an explicit NO_COLOR beats everything, an explicit
// CLICOLOR_FORCE beats the terminal check (that is how you get colour out of
// a CI runner that pipes stdout), and otherwise only a character device gets
// escape codes, so redirecting to a file leaves clean text behind.
func colourWanted(w io.Writer) bool {
	if os.Getenv("NO_COLOR") != "" || os.Getenv("TERM") == "dumb" {
		return false
	}

	if forced := os.Getenv("CLICOLOR_FORCE"); forced != "" && forced != "0" {
		return true
	}

	file, ok := w.(*os.File)
	if !ok {
		return false
	}

	info, err := file.Stat()
	if err != nil {
		return false
	}

	return info.Mode()&os.ModeCharDevice != 0
}

// paint wraps text in a style, or leaves it alone.
//
// A style nested inside another ends with a reset that clears everything, not
// just itself, so a bold heading holding a dim aside would lose its bold for
// the rest of the line. Re-opening the outer style after every inner reset is
// the cheap fix, and keeps callers from having to think about nesting.
func (p *Printer) paint(style, text string) string {
	if !p.colour || text == "" {
		return text
	}

	return style + strings.ReplaceAll(text, reset, reset+style) + reset
}

func (p *Printer) Bold(text string) string { return p.paint(bold, text) }
func (p *Printer) Dim(text string) string  { return p.paint(dim, text) }

// Title announces what a command is about to report on.
func (p *Printer) Title(format string, a ...any) {
	fmt.Fprintln(p.out, p.paint(bold, fmt.Sprintf(format, a...)))
}

// Done, Warn and Info are the three endings a command can have that are not
// an error: it worked, it worked but read this, or here is a fact.
func (p *Printer) Done(format string, a ...any) {
	fmt.Fprintf(p.out, "%s %s\n", p.paint(green, "✓"), fmt.Sprintf(format, a...))
}

func (p *Printer) Warn(format string, a ...any) {
	fmt.Fprintf(p.out, "%s %s\n", p.paint(yellow, "!"), fmt.Sprintf(format, a...))
}

func (p *Printer) Info(format string, a ...any) {
	fmt.Fprintf(p.out, "%s %s\n", p.paint(blue, "›"), fmt.Sprintf(format, a...))
}

// Error reports a failure on stderr, so that a command whose real output is
// data keeps that data clean.
func (p *Printer) Error(format string, a ...any) {
	mark := "✗"
	if p.colour {
		mark = red + mark + reset
	}

	fmt.Fprintf(p.err, "%s %s\n", mark, fmt.Sprintf(format, a...))
}

// Note is an indented aside under whatever was printed last.
func (p *Printer) Note(format string, a ...any) {
	fmt.Fprintf(p.out, "  %s\n", p.paint(dim, fmt.Sprintf(format, a...)))
}

// Line writes an unstyled line.
func (p *Printer) Line(format string, a ...any) {
	fmt.Fprintf(p.out, format+"\n", a...)
}

// Blank separates blocks.
func (p *Printer) Blank() {
	fmt.Fprintln(p.out)
}

// Interactive reports whether a stream is a terminal a person can answer at.
//
// Asked before prompting rather than after: a deploy server has no terminal,
// and a prompt there either hangs the deploy or reads EOF and silently
// answers no. Both look like the tool broke.
func Interactive(in io.Reader) bool {
	file, ok := in.(*os.File)
	if !ok {
		return false
	}

	info, err := file.Stat()
	if err != nil {
		return false
	}

	return info.Mode()&os.ModeCharDevice != 0
}

// Confirm asks a yes or no question and defaults to no.
//
// The question goes to stderr so that a command whose real output is data
// does not get a prompt mixed into it.
func (p *Printer) Confirm(in io.Reader, format string, a ...any) (bool, error) {
	fmt.Fprintf(p.err, "%s %s %s ", p.paint(yellow, "?"), fmt.Sprintf(format, a...), p.paint(dim, "[y/N]"))

	answer, err := bufio.NewReader(in).ReadString('\n')
	if err != nil && answer == "" {
		return false, err
	}

	switch strings.ToLower(strings.TrimSpace(answer)) {
	case "y", "yes":
		return true, nil
	default:
		return false, nil
	}
}

// Change is one line in a list of what happened to a key.
type Change struct {
	Mark   string // +, -, ~ or .
	Key    string
	Detail string
}

// Changes prints a list of keys, aligned so the details line up and the eye
// can run down the marks.
//
// The mark carries the meaning and the colour only repeats it, so the list
// still reads in a pipe, in a CI log and to anyone who cannot tell green from
// red.
func (p *Printer) Changes(changes []Change) {
	width := 0
	for _, change := range changes {
		if len(change.Key) > width {
			width = len(change.Key)
		}
	}

	for _, change := range changes {
		style := dim

		switch change.Mark {
		case "+":
			style = green
		case "-":
			style = red
		case "~":
			style = yellow
		}

		fmt.Fprintf(p.out, "  %s %s%s%s\n",
			p.paint(style, change.Mark),
			change.Key,
			strings.Repeat(" ", width-len(change.Key)+2),
			p.paint(dim, change.Detail))
	}
}

// Table prints rows in aligned columns, with an optional dim header.
func (p *Printer) Table(header []string, rows [][]string) {
	widths := make([]int, 0)

	for _, row := range append([][]string{header}, rows...) {
		for i, cell := range row {
			for len(widths) <= i {
				widths = append(widths, 0)
			}

			if len(cell) > widths[i] {
				widths[i] = len(cell)
			}
		}
	}

	if len(header) > 0 {
		fmt.Fprintf(p.out, "  %s\n", p.paint(dim, strings.TrimRight(row(header, widths), " ")))
	}

	for _, r := range rows {
		fmt.Fprintf(p.out, "  %s\n", strings.TrimRight(row(r, widths), " "))
	}
}

// Field prints a label and value pair, for a handful of facts.
//
// The padding sits outside the style: trailing spaces inside an escape span
// are invisible until someone selects the line with a mouse and drags a bar
// of coloured whitespace along with it.
func (p *Printer) Field(label, value string) {
	const column = 10

	padding := column - len(label)
	if padding < 1 {
		padding = 1
	}

	fmt.Fprintf(p.out, "  %s%s%s\n", p.paint(dim, label), strings.Repeat(" ", padding), value)
}

// Highlight marks a value the reader has to act on, such as a device code.
func (p *Printer) Highlight(text string) string {
	return p.paint(bold+cyan, text)
}

// Path styles a filename so it stands out from the sentence around it.
func (p *Printer) Path(text string) string {
	return p.paint(cyan, text)
}

// Count styles a number, dimmed when it is zero: a summary line is mostly
// zeroes, and dimming them leaves the eye on what actually happened.
func (p *Printer) Count(n int, noun string) string {
	text := fmt.Sprintf("%d %s", n, noun)

	if n == 0 {
		return p.paint(dim, text)
	}

	return text
}

func row(cells []string, widths []int) string {
	var b strings.Builder

	for i, cell := range cells {
		fmt.Fprintf(&b, "%-*s", widths[i], cell)

		if i < len(cells)-1 {
			b.WriteString("  ")
		}
	}

	return b.String()
}
