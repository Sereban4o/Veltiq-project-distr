package platform

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"strconv"
	"testing"
	"time"

	"github.com/veltiq/core/internal/entitlement"
	"github.com/veltiq/core/internal/license"
	"github.com/veltiq/core/internal/store"
)

func newTestService(t *testing.T, publicKeyBase64 string, grandfather bool) *Service {
	t.Helper()

	service, err := New(context.Background(), Config{
		StateDir:         t.TempDir(),
		ClusterID:        "test-cluster",
		AppKey:           "base64:test-app-key",
		PublicKeyBase64:  publicKeyBase64,
		AllowGrandfather: grandfather,
	})
	if err != nil {
		t.Fatalf("new service: %v", err)
	}

	return service
}

func TestServiceGrandfathersWithoutLicense(t *testing.T) {
	service := newTestService(t, "", true)

	status := service.Status()
	if status.State != license.StateGrandfathered || status.Access != license.AccessFull {
		t.Fatalf("expected grandfather state, got %+v", status)
	}
}

func TestServiceStrictWithoutLicense(t *testing.T) {
	service := newTestService(t, "", false)

	status := service.Status()
	if status.State != license.StateInvalid || status.Access != license.AccessBlocked {
		t.Fatalf("expected blocked state, got %+v", status)
	}
}

func TestActivateLicenseAndIssueTicket(t *testing.T) {
	publicKey, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("key: %v", err)
	}

	service := newTestService(t, base64.StdEncoding.EncodeToString(publicKey), false)
	if service.Status().State != license.StateInvalid {
		t.Fatalf("expected invalid before activation, got %s", service.Status().State)
	}

	fingerprint := service.Fingerprint()

	sealed, err := license.Seal(license.License{
		Lic:       "L-TEST",
		Customer:  "ACME",
		Edition:   "business",
		Features:  []string{"crm", "mail"},
		Modules:   []string{"calendar"},
		NotBefore: time.Now().Add(-time.Hour).Format(time.RFC3339),
		ExpiresAt: time.Now().Add(24 * time.Hour).Format(time.RFC3339),
		GraceDays: 14,
		Servers:   []string{fingerprint},
		Nonce:     "nonce-1",
	}, privateKey)
	if err != nil {
		t.Fatalf("seal: %v", err)
	}

	status, err := service.Activate(sealed)
	if err != nil {
		t.Fatalf("activate: %v", err)
	}

	if status.State != license.StateValid {
		t.Fatalf("expected valid after activation, got %s", status.State)
	}

	// Replaying the same nonce must be refused.
	if _, err := service.Activate(sealed); err == nil {
		t.Fatal("expected replay to be rejected")
	}

	// The ticket PHP will verify must round-trip with the core's public key.
	token, err := service.Ticket()
	if err != nil {
		t.Fatalf("ticket: %v", err)
	}

	ticket, err := entitlement.Verify(service.TicketKey(), token)
	if err != nil {
		t.Fatalf("verify ticket: %v", err)
	}

	if ticket.State != license.StateValid || !ticket.Allows("calendar") {
		t.Fatalf("unexpected ticket: %+v", ticket)
	}

	if ticket.LicenseExpiresAt == "" {
		t.Fatal("ticket must carry the license expiry, not only the ticket TTL")
	}
}

func TestModuleSetRespectsEntitlement(t *testing.T) {
	publicKey, privateKey, _ := ed25519.GenerateKey(rand.Reader)
	service := newTestService(t, base64.StdEncoding.EncodeToString(publicKey), false)

	sealed, _ := license.Seal(license.License{
		Lic:       "L-TEST",
		Features:  []string{"crm"},
		Modules:   []string{"calendar"},
		NotBefore: time.Now().Add(-time.Hour).Format(time.RFC3339),
		ExpiresAt: time.Now().Add(24 * time.Hour).Format(time.RFC3339),
		Servers:   []string{service.Fingerprint()},
		Nonce:     "nonce-2",
	}, privateKey)

	if _, err := service.Activate(sealed); err != nil {
		t.Fatalf("activate: %v", err)
	}

	if err := service.SyncExtensions([]store.Extension{
		{ID: "calendar", Tier: "vendor"},
		{ID: "mail", Tier: "vendor", Entitlement: "mail"},
	}); err != nil {
		t.Fatalf("sync: %v", err)
	}

	if _, err := service.SetExtension("calendar", true); err != nil {
		t.Fatalf("enable calendar: %v", err)
	}

	if _, err := service.SetExtension("mail", true); err == nil {
		t.Fatal("mail must not be enableable without the entitlement")
	}

	modules := service.Extensions()

	byID := map[string]bool{}
	for _, module := range modules {
		byID[module.ID] = module.Enabled
	}

	if !byID["calendar"] {
		t.Fatal("calendar must be enabled")
	}

	if byID["mail"] {
		t.Fatal("mail must stay disabled")
	}
}

func TestSetExtensionAllowedWhenGrandfathered(t *testing.T) {
	// No license and grandfathering on: a module with an entitlement must still
	// be enableable (previously it was refused as "not in the license").
	service := newTestService(t, "", true)

	if err := service.SyncExtensions([]store.Extension{
		{ID: "crm", Tier: "vendor", Entitlement: "crm"},
	}); err != nil {
		t.Fatalf("sync: %v", err)
	}

	if _, err := service.SetExtension("crm", true); err != nil {
		t.Fatalf("crm must be enableable when grandfathered: %v", err)
	}

	modules := service.Extensions()
	if len(modules) != 1 || modules[0].ID != "crm" || !modules[0].Enabled {
		t.Fatalf("crm must be enabled, got %+v", modules)
	}
}

func TestStateSurvivesRestart(t *testing.T) {
	dir := t.TempDir()

	first, err := New(context.Background(), Config{
		StateDir:         dir,
		ClusterID:        "cluster",
		AppKey:           "key",
		AllowGrandfather: true,
	})
	if err != nil {
		t.Fatalf("first: %v", err)
	}

	if err := first.SyncExtensions([]store.Extension{{ID: "calendar", Tier: "vendor"}}); err != nil {
		t.Fatalf("sync: %v", err)
	}

	if _, err := first.SetExtension("calendar", true); err != nil {
		t.Fatalf("enable: %v", err)
	}

	second, err := New(context.Background(), Config{
		StateDir:         dir,
		ClusterID:        "cluster",
		AppKey:           "key",
		AllowGrandfather: true,
	})
	if err != nil {
		t.Fatalf("second: %v", err)
	}

	enabled := false
	for _, module := range second.Extensions() {
		if module.ID == "calendar" {
			enabled = module.Enabled
		}
	}

	if !enabled {
		t.Fatal("module flag must survive a restart")
	}
}

func TestActiveUsersArePersisted(t *testing.T) {
	dir := t.TempDir()

	first, err := New(context.Background(), Config{
		StateDir:         dir,
		ClusterID:        "cluster",
		AppKey:           "key",
		AllowGrandfather: true,
	})
	if err != nil {
		t.Fatalf("first: %v", err)
	}

	if err := first.SetActiveUsers(17); err != nil {
		t.Fatalf("set usage: %v", err)
	}

	second, err := New(context.Background(), Config{
		StateDir:         dir,
		ClusterID:        "cluster",
		AppKey:           "key",
		AllowGrandfather: true,
	})
	if err != nil {
		t.Fatalf("second: %v", err)
	}

	if second.ActiveUsers() != 17 {
		t.Fatalf("active users not persisted: %d", second.ActiveUsers())
	}
}

// A vendor heartbeat refreshes the license; reusing the nonce is legitimate and
// must not be mistaken for a replay.
func TestApplyServerLicenseRefreshesExpiry(t *testing.T) {
	publicKey, privateKey, _ := ed25519.GenerateKey(rand.Reader)
	service := newTestService(t, base64.StdEncoding.EncodeToString(publicKey), false)

	seal := func(expires time.Time) []byte {
		raw, err := license.Seal(license.License{
			Lic:       "L-REFRESH",
			NotBefore: time.Now().Add(-time.Hour).Format(time.RFC3339),
			ExpiresAt: expires.Format(time.RFC3339),
			Servers:   []string{service.Fingerprint()},
			Nonce:     "nonce-refresh",
		}, privateKey)
		if err != nil {
			t.Fatalf("seal: %v", err)
		}

		return raw
	}

	// Almost expired…
	if _, err := service.Activate(seal(time.Now().Add(time.Minute))); err != nil {
		t.Fatalf("activate: %v", err)
	}

	// …then refreshed by the server with a later expiry and the same nonce.
	refreshed := string(seal(time.Now().Add(365 * 24 * time.Hour)))
	service.applyServerLicense(json.RawMessage(strconv.Quote(refreshed)))

	if service.Status().State != license.StateValid {
		t.Fatalf("expected valid after refresh, got %s", service.Status().State)
	}

	expires, err := time.Parse(time.RFC3339, service.Status().ExpiresAt)
	if err != nil {
		t.Fatalf("parse expiry: %v", err)
	}

	if !expires.After(time.Now().Add(300 * 24 * time.Hour)) {
		t.Fatalf("expiry was not refreshed: %s", expires)
	}
}
