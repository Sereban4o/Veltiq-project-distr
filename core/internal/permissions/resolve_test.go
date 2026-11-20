package permissions

import "testing"

func str(value string) *string { return &value }

func base() Request {
	return Request{
		Permission:      "edit_issues",
		IsGlobalScope:   false,
		GlobalRole:      str("member"),
		ScopeRole:       str("member"),
		Baseline:        []string{"view_issues", "edit_issues"},
		RolePermissions: []string{"view_projects"},
	}
}

func TestAdminBypassesEverything(t *testing.T) {
	request := base()
	request.GlobalRole = str("admin")
	request.ScopeRole = nil

	result := Resolve(request)

	if !result.Allow || result.Reason != "admin" {
		t.Fatalf("unexpected result: %+v", result)
	}
}

func TestMissingScopeMembershipDenies(t *testing.T) {
	request := base()
	request.ScopeRole = nil

	result := Resolve(request)

	if result.Allow || result.Reason != "no_scope_membership" {
		t.Fatalf("unexpected result: %+v", result)
	}
}

func TestScopeDenyBeatsScopeAllow(t *testing.T) {
	request := base()
	request.ScopeDenies = true
	request.ScopeAllows = true

	if result := Resolve(request); result.Allow {
		t.Fatalf("deny must win: %+v", result)
	}
}

func TestScopeAllowWinsOverGlobalDeny(t *testing.T) {
	request := base()
	request.ScopeAllows = true
	request.GlobalDenies = true

	if result := Resolve(request); !result.Allow || result.Reason != "scope_allow" {
		t.Fatalf("unexpected result: %+v", result)
	}
}

func TestGlobalDenyBeatsGlobalAllow(t *testing.T) {
	request := base()
	request.GlobalDenies = true
	request.GlobalAllows = true

	if result := Resolve(request); result.Allow {
		t.Fatalf("global deny must win: %+v", result)
	}
}

func TestBaselineUsedInsideAScope(t *testing.T) {
	request := base()

	if result := Resolve(request); !result.Allow || result.Reason != "baseline" {
		t.Fatalf("unexpected result: %+v", result)
	}

	request.Permission = "delete_projects"
	if result := Resolve(request); result.Allow {
		t.Fatalf("unexpected allow: %+v", result)
	}
}

func TestRolePermissionsUsedAtGlobalScope(t *testing.T) {
	request := base()
	request.IsGlobalScope = true
	request.ScopeRole = nil
	request.Permission = "view_projects"

	if result := Resolve(request); !result.Allow || result.Reason != "role" {
		t.Fatalf("unexpected result: %+v", result)
	}

	request.Permission = "manage_users"
	if result := Resolve(request); result.Allow {
		t.Fatalf("unexpected allow: %+v", result)
	}
}

// At the global scope the caller fills the override fields from the global
// override rows (there is no separate scope), mirroring the PHP implementation.
func TestGlobalScopeOverridesAreHonoured(t *testing.T) {
	request := base()
	request.IsGlobalScope = true
	request.ScopeRole = nil
	request.ScopeAllows = true

	if result := Resolve(request); !result.Allow || result.Reason != "scope_allow" {
		t.Fatalf("unexpected result: %+v", result)
	}
}
