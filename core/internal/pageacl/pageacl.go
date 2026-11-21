// Package pageacl resolves a user's effective permission on a knowledgebase page.
//
// The inheritance rule (nearest restricted ancestor wins and acts as a cutoff,
// otherwise space-level permissions apply) is product logic, so it lives in the
// compiled service; the caller keeps database access and builds the ancestor
// chain.
package pageacl

// ChainLink is one ancestor of the page, ordered from the page itself up to its
// root. HasGrants reports whether that node carries any explicit permission row;
// Grant is the permission for the requesting user, when present.
type ChainLink struct {
	HasGrants bool    `json:"has_grants"`
	Grant     *string `json:"grant"`
}

// Request is the resolution input.
type Request struct {
	IsSpaceAdmin bool        `json:"is_space_admin"`
	SpaceCanEdit bool        `json:"space_can_edit"`
	SpaceCanView bool        `json:"space_can_view"`
	Chain        []ChainLink `json:"chain"`
}

// Resolve returns the effective permission as one of "admin", "editor",
// "viewer" or "none".
func Resolve(request Request) string {
	if request.IsSpaceAdmin {
		return "admin"
	}

	for _, link := range request.Chain {
		if ! link.HasGrants {
			continue
		}

		// The nearest restricted ancestor is a cutoff: a missing grant for the
		// user denies access instead of falling through to space permissions.
		if link.Grant == nil {
			return "none"
		}

		return *link.Grant
	}

	if request.SpaceCanEdit {
		return "editor"
	}

	if request.SpaceCanView {
		return "viewer"
	}

	return "none"
}
