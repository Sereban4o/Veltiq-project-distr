// Package entitlement issues short-lived, signed tickets that PHP verifies with
// the core's public key. PHP therefore checks a signature instead of trusting a
// boolean flag it could patch away.
package entitlement

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// ErrBadTicket is returned when a ticket does not verify.
var ErrBadTicket = errors.New("entitlement ticket is invalid")

// Ticket is the payload PHP consumes.
type Ticket struct {
	Features  []string `json:"features"`
	Modules   []string `json:"modules"`
	State     string   `json:"state"`
	Access    string   `json:"access"`
	IssuedAt  string   `json:"issued_at"`
	ExpiresAt string   `json:"expires_at"`
	// LicenseExpiresAt is the license validity, NOT the ticket TTL: PHP shows it
	// to the operator, so it must not be confused with the 12h ticket lifetime.
	LicenseExpiresAt string `json:"license_expires_at,omitempty"`
}

// Envelope keeps the signed bytes opaque so verification is byte-exact.
type Envelope struct {
	Payload string `json:"payload"`
	Sig     string `json:"sig"`
}

// TTL is how long a ticket stays valid; PHP caches it for the same period.
const TTL = 12 * time.Hour

// Sign serialises and signs a ticket, returning a base64 envelope.
func Sign(privateKey ed25519.PrivateKey, ticket Ticket) (string, error) {
	raw, err := json.Marshal(ticket)
	if err != nil {
		return "", err
	}

	envelope, err := json.Marshal(Envelope{
		Payload: base64.StdEncoding.EncodeToString(raw),
		Sig:     base64.StdEncoding.EncodeToString(ed25519.Sign(privateKey, raw)),
	})
	if err != nil {
		return "", err
	}

	return base64.StdEncoding.EncodeToString(envelope), nil
}

// Verify checks a base64 envelope and returns the ticket.
func Verify(publicKey ed25519.PublicKey, token string) (Ticket, error) {
	decoded, err := base64.StdEncoding.DecodeString(token)
	if err != nil {
		return Ticket{}, ErrBadTicket
	}

	var envelope Envelope
	if err := json.Unmarshal(decoded, &envelope); err != nil {
		return Ticket{}, ErrBadTicket
	}

	raw, err := base64.StdEncoding.DecodeString(envelope.Payload)
	if err != nil {
		return Ticket{}, ErrBadTicket
	}

	sig, err := base64.StdEncoding.DecodeString(envelope.Sig)
	if err != nil {
		return Ticket{}, ErrBadTicket
	}

	if len(publicKey) != ed25519.PublicKeySize || !ed25519.Verify(publicKey, raw, sig) {
		return Ticket{}, ErrBadTicket
	}

	var ticket Ticket
	if err := json.Unmarshal(raw, &ticket); err != nil {
		return Ticket{}, ErrBadTicket
	}

	return ticket, nil
}

// LoadOrCreateKeypair reads the core's ticket keypair from the state directory,
// generating it on first start. The private half never leaves the volume.
func LoadOrCreateKeypair(dir string) (ed25519.PublicKey, ed25519.PrivateKey, error) {
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return nil, nil, err
	}

	path := filepath.Join(dir, "ticket.key")

	if data, err := os.ReadFile(path); err == nil && len(data) == ed25519.PrivateKeySize {
		privateKey := ed25519.PrivateKey(data)

		return privateKey.Public().(ed25519.PublicKey), privateKey, nil
	}

	_, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, nil, err
	}

	if err := os.WriteFile(path, privateKey, 0o600); err != nil {
		return nil, nil, fmt.Errorf("write ticket key: %w", err)
	}

	return privateKey.Public().(ed25519.PublicKey), privateKey, nil
}

// PublicKeyBase64 is what GET /v1/entitlement/pubkey returns.
func PublicKeyBase64(publicKey ed25519.PublicKey) string {
	return base64.StdEncoding.EncodeToString(publicKey)
}

// Allows reports whether the ticket grants the given feature or module id.
func (t Ticket) Allows(id string) bool {
	for _, feature := range t.Features {
		if feature == id {
			return true
		}
	}

	for _, module := range t.Modules {
		if module == id {
			return true
		}
	}

	return false
}
