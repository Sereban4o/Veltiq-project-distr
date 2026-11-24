// Package license verifies Ed25519-signed license files and derives the
// installation fingerprint. It has no persistence of its own — the caller keeps
// state via the store package.
package license

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"
)

// Errors returned by verification. They are intentionally coarse: the caller
// reports a state, not the crypto detail.
var (
	ErrBadSignature = errors.New("license signature is invalid")
	ErrBadPayload   = errors.New("license payload is malformed")
)

// License is the signed payload of a license file.
type License struct {
	Lic          string   `json:"lic"`
	Customer     string   `json:"customer"`
	Edition      string   `json:"edition"`
	Features     []string `json:"features"`
	Modules      []string `json:"modules"`
	Seats        int      `json:"seats"`
	NotBefore    string   `json:"not_before"`
	ExpiresAt    string   `json:"expires_at"`
	GraceDays    int      `json:"grace_days"`
	Servers      []string `json:"servers"`
	Offline      bool     `json:"offline"`
	IssuedAt     string   `json:"issued_at"`
	Nonce        string   `json:"nonce"`
	MaxDriftDays int      `json:"max_drift_days"`
}

// Envelope is the on-disk/on-wire form: base64 canonical payload + detached
// base64 signature over those exact bytes. Keeping the payload opaque avoids
// "re-marshal and hope it is identical" bugs.
type Envelope struct {
	Payload string `json:"payload"`
	Sig     string `json:"sig"`
}

// Parse verifies the envelope signature and decodes the payload.
func Parse(data []byte, publicKey ed25519.PublicKey) (License, error) {
	var env Envelope
	if err := json.Unmarshal(data, &env); err != nil {
		return License{}, ErrBadPayload
	}

	raw, err := base64.StdEncoding.DecodeString(strings.TrimSpace(env.Payload))
	if err != nil {
		return License{}, ErrBadPayload
	}

	sig, err := base64.StdEncoding.DecodeString(strings.TrimSpace(env.Sig))
	if err != nil {
		return License{}, ErrBadSignature
	}

	if len(publicKey) != ed25519.PublicKeySize || !ed25519.Verify(publicKey, raw, sig) {
		return License{}, ErrBadSignature
	}

	var lic License
	if err := json.Unmarshal(raw, &lic); err != nil {
		return License{}, ErrBadPayload
	}

	if lic.Lic == "" || lic.ExpiresAt == "" {
		return License{}, ErrBadPayload
	}

	return lic, nil
}

// Seal builds a signed envelope. Used by the vendor tooling and tests.
func Seal(lic License, privateKey ed25519.PrivateKey) ([]byte, error) {
	raw, err := json.Marshal(lic)
	if err != nil {
		return nil, err
	}

	env := Envelope{
		Payload: base64.StdEncoding.EncodeToString(raw),
		Sig:     base64.StdEncoding.EncodeToString(ed25519.Sign(privateKey, raw)),
	}

	return json.MarshalIndent(env, "", "  ")
}

// Time parses one of the RFC3339 timestamps, tolerating a date-only value.
func parseTime(value string) (time.Time, error) {
	if value == "" {
		return time.Time{}, nil
	}

	if parsed, err := time.Parse(time.RFC3339, value); err == nil {
		return parsed.UTC(), nil
	}

	parsed, err := time.Parse("2006-01-02", value)
	if err != nil {
		return time.Time{}, fmt.Errorf("invalid timestamp %q", value)
	}

	return parsed.UTC(), nil
}

// NotBeforeTime and ExpiresTime decode the validity window.
func (l License) NotBeforeTime() (time.Time, error) { return parseTime(l.NotBefore) }

func (l License) ExpiresTime() (time.Time, error) { return parseTime(l.ExpiresAt) }

// GraceUntil is ExpiresAt + GraceDays.
func (l License) GraceUntil() (time.Time, error) {
	expires, err := l.ExpiresTime()
	if err != nil {
		return time.Time{}, err
	}

	return expires.AddDate(0, 0, l.GraceDays), nil
}

// BindsFingerprint reports whether the license lists the given installation.
// An empty server list means "unbound" (single-customer builds).
func (l License) BindsFingerprint(fp string) bool {
	if len(l.Servers) == 0 {
		return true
	}

	for _, s := range l.Servers {
		if strings.EqualFold(strings.TrimSpace(s), fp) {
			return true
		}
	}

	return false
}

// Fingerprint hashes the installation inputs into a stable `fp_<hex12>` value.
// Raw identifiers never leave the process.
func Fingerprint(inputs ...string) string {
	clean := make([]string, 0, len(inputs))
	for _, in := range inputs {
		in = strings.TrimSpace(in)
		if in != "" {
			clean = append(clean, in)
		}
	}

	sum := sha256.Sum256([]byte(strings.Join(clean, "\x00")))

	return "fp_" + hex.EncodeToString(sum[:])[:12]
}
