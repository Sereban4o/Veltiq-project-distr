package registry

import (
	"testing"

	"github.com/veltiq/core/internal/license"
	"github.com/veltiq/core/internal/store"
)

func TestResolveAllowedAndEnabled(t *testing.T) {
	status := license.Status{
		State:    license.StateValid,
		Access:   license.AccessFull,
		Features: []string{"crm"},
		Modules:  []string{"calendar"},
	}

	modules := map[string]store.Extension{
		"calendar":     {ID: "calendar", Tier: "vendor", Enabled: true},
		"crm":          {ID: "crm", Tier: "vendor", Entitlement: "crm", Enabled: true},
		"mail":         {ID: "mail", Tier: "vendor", Entitlement: "mail", Enabled: true},
		"acme.reports": {ID: "acme.reports", Tier: "third_party", Enabled: false},
	}

	views := Resolve(status, modules)

	byID := map[string]View{}
	for _, view := range views {
		byID[view.ID] = view
	}

	if !byID["calendar"].Enabled || !byID["calendar"].Allowed {
		t.Fatal("calendar must be allowed and enabled")
	}

	if !byID["crm"].Enabled {
		t.Fatal("crm entitlement is present, must be enabled")
	}

	if byID["mail"].Allowed || byID["mail"].Enabled {
		t.Fatal("mail is not licensed, must be disabled")
	}

	if byID["mail"].Reason == "" {
		t.Fatal("expected a reason for the denied module")
	}

	if byID["acme.reports"].Enabled {
		t.Fatal("disabled module stays disabled")
	}

	// Sorted by id.
	if views[0].ID != "acme.reports" {
		t.Fatalf("expected sorted output, got %s first", views[0].ID)
	}

	enabled := EnabledIDs(views)
	if len(enabled) != 2 {
		t.Fatalf("expected 2 enabled modules, got %v", enabled)
	}
}

func TestResolveBlocksThirdPartyWithoutLicense(t *testing.T) {
	status := license.Status{State: license.StateInvalid, Access: license.AccessBlocked}

	views := Resolve(status, map[string]store.Extension{
		"acme.reports": {ID: "acme.reports", Tier: "third_party", Enabled: true},
	})

	if views[0].Allowed || views[0].Enabled {
		t.Fatal("third-party module must be blocked without a license")
	}
}
