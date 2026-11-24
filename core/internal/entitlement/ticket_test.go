package entitlement

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestSignAndVerifyRoundtrip(t *testing.T) {
	publicKey, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("key: %v", err)
	}

	ticket := Ticket{
		Features:  []string{"crm"},
		Modules:   []string{"calendar"},
		State:     "valid",
		Access:    "full",
		IssuedAt:  time.Now().UTC().Format(time.RFC3339),
		ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339),
	}

	token, err := Sign(privateKey, ticket)
	if err != nil {
		t.Fatalf("sign: %v", err)
	}

	verified, err := Verify(publicKey, token)
	if err != nil {
		t.Fatalf("verify: %v", err)
	}

	if verified.State != "valid" || len(verified.Features) != 1 || verified.Features[0] != "crm" {
		t.Fatalf("unexpected ticket: %+v", verified)
	}
}

func TestVerifyRejectsTamperedTicket(t *testing.T) {
	publicKey, privateKey, _ := ed25519.GenerateKey(rand.Reader)

	token, _ := Sign(privateKey, Ticket{State: "valid", Access: "full"})

	decoded, err := base64.StdEncoding.DecodeString(token)
	if err != nil {
		t.Fatalf("decode: %v", err)
	}

	var envelope Envelope
	_ = json.Unmarshal(decoded, &envelope)
	envelope.Payload = base64.StdEncoding.EncodeToString([]byte(`{"state":"grandfathered","access":"full"}`))

	tampered, _ := json.Marshal(envelope)

	if _, err := Verify(publicKey, base64.StdEncoding.EncodeToString(tampered)); !errors.Is(err, ErrBadTicket) {
		t.Fatalf("expected ErrBadTicket, got %v", err)
	}
}

func TestLoadOrCreateKeypairPersists(t *testing.T) {
	dir := t.TempDir()

	publicKey, privateKey, err := LoadOrCreateKeypair(dir)
	if err != nil {
		t.Fatalf("first load: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "ticket.key")); err != nil {
		t.Fatalf("key file missing: %v", err)
	}

	publicAgain, privateAgain, err := LoadOrCreateKeypair(dir)
	if err != nil {
		t.Fatalf("second load: %v", err)
	}

	if !publicKey.Equal(publicAgain) || !privateKey.Equal(privateAgain) {
		t.Fatal("keypair must be stable across restarts")
	}
}
