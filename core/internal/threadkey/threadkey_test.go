package threadkey

import (
	"crypto/sha1"
	"encoding/hex"
	"testing"
)

func strPtr(value string) *string { return &value }

func sha1hex(value string) string {
	sum := sha1.Sum([]byte(value))

	return hex.EncodeToString(sum[:])
}

func TestComputePrefersParentKey(t *testing.T) {
	got := Compute(Request{
		ParentKey:         strPtr("parent-thread"),
		MessageID:         strPtr("<abc@example.com>"),
		NormalizedSubject: "hello",
	})

	if got != "parent-thread" {
		t.Fatalf("key %q, want parent-thread", got)
	}
}

func TestComputeUsesNormalizedMessageID(t *testing.T) {
	got := Compute(Request{MessageID: strPtr("<ABC@Example.com>")})

	if want := sha1hex("m:abc@example.com"); got != want {
		t.Fatalf("key %q, want %q", got, want)
	}
}

func TestComputeFallsBackToSubject(t *testing.T) {
	got := Compute(Request{NormalizedSubject: "привет мир"})

	if want := sha1hex("s:привет мир"); got != want {
		t.Fatalf("key %q, want %q", got, want)
	}
}

func TestComputeEmptyMessageIDFallsBackToSubject(t *testing.T) {
	got := Compute(Request{MessageID: strPtr("   "), NormalizedSubject: "x"})

	if want := sha1hex("s:x"); got != want {
		t.Fatalf("key %q, want %q", got, want)
	}
}
