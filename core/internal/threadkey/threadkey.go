// Package threadkey resolves the conversation key a mail message belongs to.
//
// Only the precedence and hashing live here: the caller normalises the subject
// with its own (PCRE) rules before calling, because Go's regexp engine cannot
// reproduce them exactly.
package threadkey

import (
	"crypto/sha1"
	"encoding/hex"
	"strings"
)

// Request is the resolution input. NormalizedSubject must already be lowercased,
// de-prefixed and whitespace-collapsed by the caller.
type Request struct {
	ParentKey         *string `json:"parent_key"`
	MessageID         *string `json:"message_id"`
	NormalizedSubject string  `json:"normalized_subject"`
}

// Compute returns the thread key: an existing parent key wins, otherwise the
// sha1 of the RFC Message-ID, otherwise the sha1 of the normalised subject.
func Compute(request Request) string {
	if request.ParentKey != nil && *request.ParentKey != "" {
		return *request.ParentKey
	}

	if id := normalizeID(request.MessageID); id != "" {
		return hash("m:" + id)
	}

	return hash("s:" + request.NormalizedSubject)
}

func normalizeID(value *string) string {
	if value == nil {
		return ""
	}

	return strings.ToLower(strings.Trim(*value, " \t<>"))
}

func hash(value string) string {
	sum := sha1.Sum([]byte(value))

	return hex.EncodeToString(sum[:])
}
