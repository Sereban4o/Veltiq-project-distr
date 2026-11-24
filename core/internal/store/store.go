// Package store persists the core's own state: the license, extension flags,
// monotonic clock anchor and a small audit log. It intentionally owns nothing
// else — the core must not read application data.
package store

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// ErrNotFound is returned when no state has been saved yet.
var ErrNotFound = errors.New("state not found")

// Meta carries everything that is not the license or extension flags.
type Meta struct {
	LastSeenAt  string   `json:"last_seen_at,omitempty"`
	ClockTamper bool     `json:"clock_tamper,omitempty"`
	SeenNonces  []string `json:"seen_nonces,omitempty"`
	Revoked     []string `json:"revoked,omitempty"`
	InstalledAt string   `json:"installed_at,omitempty"`
	ActiveUsers int      `json:"active_users,omitempty"`
}

// Module is a module declared by the PHP manifest sync.
type Extension struct {
	ID          string `json:"id"`
	Tier        string `json:"tier"`
	Entitlement string `json:"entitlement,omitempty"`
	Enabled     bool   `json:"enabled"`
	// DefaultEnabled is applied only when the extension is first declared; after
	// that the administrator's choice (Enabled) is authoritative.
	DefaultEnabled bool   `json:"default_enabled,omitempty"`
	DeclaredAt     string `json:"declared_at,omitempty"`
}

// State is the whole persisted snapshot.
type State struct {
	LicenseRaw string               `json:"license_raw,omitempty"`
	Meta       Meta                 `json:"meta"`
	Extensions map[string]Extension `json:"extensions"`
}

// Audit is one immutable record of a licensing/module decision.
type Audit struct {
	At     string `json:"at"`
	Action string `json:"action"`
	Detail string `json:"detail,omitempty"`
	Actor  string `json:"actor,omitempty"`
}

// Store is the persistence contract (file or Postgres).
type Store interface {
	Load() (State, error)
	Save(State) error
	AppendAudit(Audit) error
	Close() error
}

// FileStore keeps a single JSON snapshot plus a JSONL audit file in a volume.
// It is the fallback when Postgres is not configured or unreachable.
type FileStore struct {
	dir string
	mu  sync.Mutex
}

// NewFileStore prepares the state directory.
func NewFileStore(dir string) (*FileStore, error) {
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return nil, err
	}

	return &FileStore{dir: dir}, nil
}

func (f *FileStore) statePath() string { return filepath.Join(f.dir, "state.json") }

func (f *FileStore) auditPath() string { return filepath.Join(f.dir, "audit.jsonl") }

// Load reads the snapshot; a missing file is an empty state, not an error.
func (f *FileStore) Load() (State, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	state := State{Extensions: map[string]Extension{}}

	data, err := os.ReadFile(f.statePath())
	if err != nil {
		if os.IsNotExist(err) {
			return state, nil
		}

		return state, err
	}

	if err := json.Unmarshal(data, &state); err != nil {
		return State{Extensions: map[string]Extension{}}, err
	}

	if state.Extensions == nil {
		state.Extensions = map[string]Extension{}
	}

	return state, nil
}

// Save writes the snapshot atomically (tmp + rename) with 0600.
func (f *FileStore) Save(state State) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	if state.Extensions == nil {
		state.Extensions = map[string]Extension{}
	}

	data, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}

	tmp := f.statePath() + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}

	return os.Rename(tmp, f.statePath())
}

// AppendAudit appends one JSON line.
func (f *FileStore) AppendAudit(entry Audit) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	if entry.At == "" {
		entry.At = time.Now().UTC().Format(time.RFC3339)
	}

	line, err := json.Marshal(entry)
	if err != nil {
		return err
	}

	file, err := os.OpenFile(f.auditPath(), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o640)
	if err != nil {
		return err
	}
	defer file.Close()

	_, err = file.Write(append(line, '\n'))

	return err
}

// Close is a no-op for the file store.
func (f *FileStore) Close() error { return nil }
