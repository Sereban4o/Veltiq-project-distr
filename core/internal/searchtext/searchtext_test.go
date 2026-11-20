package searchtext

import (
	"strings"
	"testing"
)

func TestWindow(t *testing.T) {
	cases := []struct{ perPage, page, want int }{
		{50, 1, 150},
		{10, 1, 100},
		{100, 3, 900},
		{0, 0, 100},
	}

	for _, c := range cases {
		if got := Window(c.perPage, c.page); got != c.want {
			t.Fatalf("Window(%d, %d) = %d, want %d", c.perPage, c.page, got, c.want)
		}
	}
}

func TestSnippetEmptyText(t *testing.T) {
	if got := Snippet("", "x", 0); got != "" {
		t.Fatalf("unexpected snippet %q", got)
	}
}

func TestSnippetCentresTheMatch(t *testing.T) {
	text := "prefix text with the needle inside and a long tail that should be trimmed away"
	got := Snippet(text, "needle", 30)

	if !strings.HasPrefix(got, "…") {
		t.Fatalf("expected a leading ellipsis, got %q", got)
	}
	if !contains(got, "needle") {
		t.Fatalf("match lost: %q", got)
	}
}

func TestSnippetIsCaseInsensitive(t *testing.T) {
	got := Snippet("Quarterly REPORT for December", "report", 160)

	if !contains(got, "REPORT") {
		t.Fatalf("unexpected snippet %q", got)
	}
}

func TestSnippetFallsBackToTheHead(t *testing.T) {
	text := "abcdefghijklmnopqrstuvwxyz"
	got := Snippet(text, "zzz", 10)

	if got != "abcdefghij…" {
		t.Fatalf("unexpected snippet %q", got)
	}
}

func TestSnippetKeepsShortTextUnchanged(t *testing.T) {
	if got := Snippet("short", "", 160); got != "short" {
		t.Fatalf("unexpected snippet %q", got)
	}
}

func TestSnippetCountsRunesNotBytes(t *testing.T) {
	text := "Отчёт о квартальных результатах компании за декабрь"
	got := Snippet(text, "кварталь", 20)

	if !contains(got, "кварталь") {
		t.Fatalf("unexpected snippet %q", got)
	}
	if RuneLength(got) > 22 {
		t.Fatalf("snippet is too long: %d runes", RuneLength(got))
	}
}

func contains(haystack, needle string) bool {
	return indexFold(haystack, needle) >= 0
}
