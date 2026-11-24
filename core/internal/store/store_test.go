package store

import (
	"os"
	"path/filepath"
	"testing"
)

func TestFileStoreRoundtrip(t *testing.T) {
	store, err := NewFileStore(t.TempDir())
	if err != nil {
		t.Fatalf("new store: %v", err)
	}

	// Empty store yields an empty state, not an error.
	empty, err := store.Load()
	if err != nil {
		t.Fatalf("load empty: %v", err)
	}

	if len(empty.Extensions) != 0 || empty.LicenseRaw != "" {
		t.Fatalf("unexpected empty state: %+v", empty)
	}

	state := State{
		LicenseRaw: `{"payload":"x","sig":"y"}`,
		Meta:       Meta{LastSeenAt: "2026-01-01T00:00:00Z", Revoked: []string{"L9"}},
		Extensions: map[string]Extension{
			"calendar": {ID: "calendar", Tier: "vendor", Enabled: true},
		},
	}

	if err := store.Save(state); err != nil {
		t.Fatalf("save: %v", err)
	}

	loaded, err := store.Load()
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	if loaded.LicenseRaw != state.LicenseRaw {
		t.Fatalf("license not persisted: %q", loaded.LicenseRaw)
	}

	if !loaded.Extensions["calendar"].Enabled {
		t.Fatal("extension flag not persisted")
	}

	// State files must not be world-readable.
	info, err := os.Stat(filepath.Join(store.dir, "state.json"))
	if err != nil {
		t.Fatalf("stat: %v", err)
	}

	if info.Mode().Perm()&0o077 != 0 {
		t.Fatalf("state.json is too permissive: %v", info.Mode().Perm())
	}
}

func TestFileStoreAuditAppends(t *testing.T) {
	store, _ := NewFileStore(t.TempDir())

	if err := store.AppendAudit(Audit{Action: "license.activate"}); err != nil {
		t.Fatalf("audit: %v", err)
	}

	if err := store.AppendAudit(Audit{Action: "extension.set", Detail: "calendar"}); err != nil {
		t.Fatalf("audit: %v", err)
	}

	data, err := os.ReadFile(filepath.Join(store.dir, "audit.jsonl"))
	if err != nil {
		t.Fatalf("read audit: %v", err)
	}

	lines := 0
	for _, line := range splitLines(string(data)) {
		if line != "" {
			lines++
		}
	}

	if lines != 2 {
		t.Fatalf("expected 2 audit lines, got %d", lines)
	}
}

func splitLines(s string) []string {
	out := []string{}
	current := ""

	for _, r := range s {
		if r == '\n' {
			out = append(out, current)
			current = ""

			continue
		}

		current += string(r)
	}

	return append(out, current)
}
