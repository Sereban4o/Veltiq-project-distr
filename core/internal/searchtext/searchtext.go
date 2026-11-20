// Package searchtext produces the search snippets and window sizes used by the
// global search results.
//
// The ranking itself stays in PHP because it is driven by Meilisearch; only the
// text work (case-insensitive match, centred excerpt, ellipses) and the window
// arithmetic live here.
package searchtext

import (
	"strings"
	"unicode/utf8"
)

const defaultSnippetLength = 160

// Window returns how many candidates to fetch before the caller filters them by
// access rules (Meilisearch ranks before the ACL check).
func Window(perPage, page int) int {
	if perPage < 1 {
		perPage = 1
	}
	if page < 1 {
		page = 1
	}

	window := perPage * page * 3
	if window < 100 {
		window = 100
	}

	return window
}

// Snippet builds a one-line excerpt around the first case-insensitive match of
// an ellipsis when the text is trimmed.
func Snippet(text, query string, length int) string {
	if text == "" {
		return ""
	}
	if length <= 0 {
		length = defaultSnippetLength
	}

	runes := []rune(text)
	if len(runes) <= length && query == "" {
		return text
	}

	position := indexFold(text, query)
	if position < 0 {
		return truncate(runes, length)
	}

	start := position - length/3
	if start < 0 {
		start = 0
	}

	end := start + length
	if end > len(runes) {
		end = len(runes)
		start = end - length
		if start < 0 {
			start = 0
		}
	}

	excerpt := string(runes[start:end])

	prefix := ""
	if start > 0 {
		prefix = "…"
	}

	suffix := ""
	if len(runes) > end {
		suffix = "…"
	}

	return prefix + excerpt + suffix
}

// indexFold returns the rune index of needle inside haystack, case-insensitively.
func indexFold(haystack, needle string) int {
	if needle == "" {
		return -1
	}

	haystackRunes := []rune(haystack)
	needleRunes := []rune(needle)

	lowerNeedle := []rune(strings.ToLower(needle))
	if len(needleRunes) == 0 || len(lowerNeedle) != len(needleRunes) {
		// Case folding changed the length (rare): fall back to a plain scan.
		return indexPlain(haystackRunes, needleRunes)
	}

	for index := 0; index+len(haystackRunes)-index >= len(needleRunes) && index <= len(haystackRunes)-len(needleRunes); index++ {
		matched := true
		for offset, expected := range lowerNeedle {
			if strings.ToLower(string(haystackRunes[index+offset])) != string(expected) {
				matched = false

				break
			}
		}

		if matched {
			return index
		}
	}

	return -1
}

func indexPlain(haystack, needle []rune) int {
	for index := 0; index <= len(haystack)-len(needle); index++ {
		matched := true
		for offset := range needle {
			if haystack[index+offset] != needle[offset] {
				matched = false

				break
			}
		}

		if matched {
			return index
		}
	}

	return -1
}

// truncate mimics mb_strimwidth: cut to the width and append an ellipsis.
func truncate(runes []rune, length int) string {
	if len(runes) <= length {
		return string(runes)
	}

	return string(runes[:length]) + "…"
}

// RuneLength is the rune count (mb_strlen equivalent) used by callers.
func RuneLength(value string) int {
	return utf8.RuneCountInString(value)
}
