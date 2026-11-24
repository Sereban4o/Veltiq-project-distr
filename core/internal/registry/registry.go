// Package registry turns the license status plus the admin's enable flags into
// the module list PHP serves to the SPA.
package registry

import (
	"sort"

	"github.com/veltiq/core/internal/license"
	"github.com/veltiq/core/internal/store"
)

// View is one module as exposed over GET /v1/modules.
type View struct {
	ID          string `json:"id"`
	Tier        string `json:"tier"`
	Entitlement string `json:"entitlement,omitempty"`
	Enabled     bool   `json:"enabled"`
	Allowed     bool   `json:"allowed"`
	Reason      string `json:"reason,omitempty"`
}

// Resolve sorts the declared modules and decides what is allowed. `enabled`
// reflects the effective state (declared on AND licensed).
func Resolve(status license.Status, modules map[string]store.Extension) []View {
	views := make([]View, 0, len(modules))

	for _, module := range modules {
		view := View{
			ID:          module.ID,
			Tier:        module.Tier,
			Entitlement: module.Entitlement,
		}

		if module.Tier == "third_party" && status.Access == license.AccessBlocked {
			view.Reason = "нет действующей лицензии"
		} else if module.Entitlement != "" && !status.Allows(module.Entitlement) {
			view.Reason = "модуль не входит в лицензию"
		} else {
			view.Allowed = true
		}

		view.Enabled = view.Allowed && module.Enabled
		views = append(views, view)
	}

	sort.Slice(views, func(i, j int) bool { return views[i].ID < views[j].ID })

	return views
}

// EnabledIDs returns the ids that are actually active — the SPA uses this to
// build its menu and routes.
func EnabledIDs(views []View) []string {
	ids := make([]string, 0, len(views))

	for _, view := range views {
		if view.Enabled {
			ids = append(ids, view.ID)
		}
	}

	return ids
}
