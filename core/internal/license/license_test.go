package license

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"testing"
	"time"
)

func testKey(t *testing.T) (ed25519.PublicKey, ed25519.PrivateKey) {
	t.Helper()

	publicKey, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}

	return publicKey, privateKey
}

func sampleLicense(now time.Time) License {
	return License{
		Lic:       "L-2026-0001",
		Customer:  "ACME",
		Edition:   "business",
		Features:  []string{"crm", "mail"},
		Modules:   []string{"calendar"},
		NotBefore: now.Add(-24 * time.Hour).Format(time.RFC3339),
		ExpiresAt: now.Add(24 * time.Hour).Format(time.RFC3339),
		GraceDays: 14,
		Servers:   []string{"fp_test"},
		IssuedAt:  now.Format(time.RFC3339),
		Nonce:     "n-1",
	}
}

func TestSealAndParse(t *testing.T) {
	publicKey, privateKey := testKey(t)
	now := time.Date(2026, 1, 1, 12, 0, 0, 0, time.UTC)

	sealed, err := Seal(sampleLicense(now), privateKey)
	if err != nil {
		t.Fatalf("seal: %v", err)
	}

	parsed, err := Parse(sealed, publicKey)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	if parsed.Lic != "L-2026-0001" || parsed.Customer != "ACME" {
		t.Fatalf("unexpected license: %+v", parsed)
	}
}

func TestParseRejectsTamperedPayload(t *testing.T) {
	publicKey, privateKey := testKey(t)
	now := time.Now()

	sealed, err := Seal(sampleLicense(now), privateKey)
	if err != nil {
		t.Fatalf("seal: %v", err)
	}

	var envelope Envelope
	if err := json.Unmarshal(sealed, &envelope); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	// Flip the payload: the signature must no longer match.
	raw := []byte(`{"lic":"L-HACK","expires_at":"2099-01-01T00:00:00Z"}`)
	envelope.Payload = base64.StdEncoding.EncodeToString(raw)

	tampered, _ := json.Marshal(envelope)

	if _, err := Parse(tampered, publicKey); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("expected ErrBadSignature, got %v", err)
	}
}

func TestParseRejectsWrongKey(t *testing.T) {
	_, privateKey := testKey(t)
	otherPublic, _ := testKey(t)

	sealed, _ := Seal(sampleLicense(time.Now()), privateKey)

	if _, err := Parse(sealed, otherPublic); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("expected ErrBadSignature, got %v", err)
	}
}

func TestFingerprintIsStableAndOrderSensitive(t *testing.T) {
	a := Fingerprint("cluster", "app-key", "machine")
	b := Fingerprint("cluster", "app-key", "machine")

	if a != b {
		t.Fatalf("fingerprint not stable: %s vs %s", a, b)
	}

	if len(a) != len("fp_")+12 || a[:3] != "fp_" {
		t.Fatalf("unexpected fingerprint shape: %s", a)
	}

	if a == Fingerprint("other", "app-key", "machine") {
		t.Fatal("fingerprint must change with inputs")
	}
}

func TestEvaluateStates(t *testing.T) {
	now := time.Date(2026, 6, 1, 12, 0, 0, 0, time.UTC)

	tests := []struct {
		name        string
		license     *License
		revoked     map[string]bool
		grandfather bool
		wantState   string
		wantAccess  string
	}{
		{
			name:       "valid",
			license:    licensePtr(License{Lic: "L1", ExpiresAt: now.Add(24 * time.Hour).Format(time.RFC3339), Servers: []string{"fp_test"}}),
			wantState:  StateValid,
			wantAccess: AccessFull,
		},
		{
			name:       "grace",
			license:    licensePtr(License{Lic: "L1", ExpiresAt: now.Add(-24 * time.Hour).Format(time.RFC3339), GraceDays: 14, Servers: []string{"fp_test"}}),
			wantState:  StateGrace,
			wantAccess: AccessFull,
		},
		{
			name:       "expired",
			license:    licensePtr(License{Lic: "L1", ExpiresAt: now.Add(-40 * 24 * time.Hour).Format(time.RFC3339), GraceDays: 14, Servers: []string{"fp_test"}}),
			wantState:  StateExpired,
			wantAccess: AccessReadOnly,
		},
		{
			name:       "revoked-outranks-valid",
			license:    licensePtr(License{Lic: "L1", ExpiresAt: now.Add(24 * time.Hour).Format(time.RFC3339), Servers: []string{"fp_test"}}),
			revoked:    map[string]bool{"L1": true},
			wantState:  StateRevoked,
			wantAccess: AccessBlocked,
		},
		{
			name:       "wrong-fingerprint",
			license:    licensePtr(License{Lic: "L1", ExpiresAt: now.Add(24 * time.Hour).Format(time.RFC3339), Servers: []string{"fp_other"}}),
			wantState:  StateInvalid,
			wantAccess: AccessBlocked,
		},
		{
			name:       "not-yet-active",
			license:    licensePtr(License{Lic: "L1", NotBefore: now.Add(24 * time.Hour).Format(time.RFC3339), ExpiresAt: now.Add(48 * time.Hour).Format(time.RFC3339), Servers: []string{"fp_test"}}),
			wantState:  StateInvalid,
			wantAccess: AccessBlocked,
		},
		{
			name:        "no-license-with-grandfather",
			license:     nil,
			grandfather: true,
			wantState:   StateGrandfathered,
			wantAccess:  AccessFull,
		},
		{
			name:        "no-license-strict",
			license:     nil,
			grandfather: false,
			wantState:   StateInvalid,
			wantAccess:  AccessBlocked,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			revoked := tc.revoked
			if revoked == nil {
				revoked = map[string]bool{}
			}

			status := Evaluate(tc.license, now, revoked, "fp_test", tc.grandfather)

			if status.State != tc.wantState {
				t.Fatalf("state: got %s want %s", status.State, tc.wantState)
			}

			if status.Access != tc.wantAccess {
				t.Fatalf("access: got %s want %s", status.Access, tc.wantAccess)
			}
		})
	}
}

func TestAllows(t *testing.T) {
	status := Status{Features: []string{"crm"}, Modules: []string{"calendar"}}

	if !status.Allows("crm") || !status.Allows("calendar") {
		t.Fatal("expected features and modules to be allowed")
	}

	if status.Allows("mail") {
		t.Fatal("mail must not be allowed")
	}

	// A grandfathered install has no license file and ships fully enabled.
	if !(Status{State: StateGrandfathered}).Allows("mail") {
		t.Fatal("grandfathered status must allow every module")
	}
}

func licensePtr(l License) *License { return &l }
