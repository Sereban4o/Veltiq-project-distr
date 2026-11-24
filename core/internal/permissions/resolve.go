// Package permissions resolves whether a user holds a permission in a scope.
//
// The decision order (deny before allow, requested scope before global, then
// membership baseline and role permissions) is product logic, so it lives in the
// compiled service. The caller passes everything it read from the database and
// applies the answer; the local PHP implementation stays as a fallback.
package permissions

// Request carries the pre-computed inputs of one decision.
type Request struct {
	Permission      string   `json:"permission"`
	IsGlobalScope   bool     `json:"is_global_scope"`
	GlobalRole      *string  `json:"global_role"`
	ScopeRole       *string  `json:"scope_role"`
	ScopeDenies     bool     `json:"scope_denies"`
	ScopeAllows     bool     `json:"scope_allows"`
	GlobalDenies    bool     `json:"global_denies"`
	GlobalAllows    bool     `json:"global_allows"`
	Baseline        []string `json:"baseline"`
	RolePermissions []string `json:"role_permissions"`
}

// Result is the decision plus the rule that produced it (handy for debugging).
type Result struct {
	Allow  bool   `json:"allow"`
	Reason string `json:"reason"`
}

// Resolve mirrors \App\Services\Permissions\PermissionService::resolve().
func Resolve(r Request) Result {
	// 1. Global admin bypass.
	if r.GlobalRole != nil && *r.GlobalRole == "admin" {
		return Result{Allow: true, Reason: "admin"}
	}

	// 2. Scope access gate.
	if !r.IsGlobalScope && r.ScopeRole == nil {
		return Result{Allow: false, Reason: "no_scope_membership"}
	}

	// 3. Requested scope overrides (deny wins inside the level).
	if r.ScopeDenies {
		return Result{Allow: false, Reason: "scope_deny"}
	}
	if r.ScopeAllows {
		return Result{Allow: true, Reason: "scope_allow"}
	}

	// 4. Global overrides, unless we are already at the global scope.
	if !r.IsGlobalScope {
		if r.GlobalDenies {
			return Result{Allow: false, Reason: "global_deny"}
		}
		if r.GlobalAllows {
			return Result{Allow: true, Reason: "global_allow"}
		}

		// 5. Membership baseline inside the scope.
		return Result{Allow: contains(r.Baseline, r.Permission), Reason: "baseline"}
	}

	// 6. Global role permissions.
	return Result{Allow: contains(r.RolePermissions, r.Permission), Reason: "role"}
}

func contains(list []string, needle string) bool {
	for _, item := range list {
		if item == needle {
			return true
		}
	}

	return false
}
