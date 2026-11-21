package pageacl

import "testing"

func strPtr(value string) *string { return &value }

func TestResolveSpaceAdminWins(t *testing.T) {
	got := Resolve(Request{
		IsSpaceAdmin: true,
		Chain:        []ChainLink{{HasGrants: true, Grant: nil}},
	})

	if got != "admin" {
		t.Fatalf("permission %q, want admin", got)
	}
}

func TestResolveRestrictedAncestorGrant(t *testing.T) {
	got := Resolve(Request{
		Chain: []ChainLink{
			{HasGrants: false},
			{HasGrants: true, Grant: strPtr("viewer")},
			{HasGrants: true, Grant: strPtr("editor")},
		},
	})

	if got != "viewer" {
		t.Fatalf("permission %q, want viewer", got)
	}
}

func TestResolveRestrictedAncestorCutoff(t *testing.T) {
	got := Resolve(Request{
		SpaceCanEdit: true,
		Chain: []ChainLink{
			{HasGrants: false},
			{HasGrants: true, Grant: nil},
		},
	})

	if got != "none" {
		t.Fatalf("permission %q, want none", got)
	}
}

func TestResolveFallsBackToSpacePermissions(t *testing.T) {
	cases := []struct {
		name string
		req  Request
		want string
	}{
		{"editor", Request{SpaceCanEdit: true, SpaceCanView: true}, "editor"},
		{"viewer", Request{SpaceCanView: true}, "viewer"},
		{"none", Request{}, "none"},
	}

	for _, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			if got := Resolve(test.req); got != test.want {
				t.Fatalf("permission %q, want %q", got, test.want)
			}
		})
	}
}
