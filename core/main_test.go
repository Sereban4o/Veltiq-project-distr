package main

import "testing"

// resolveLicensePublicKey decides which key verifies license signatures. The
// pinned (build-time) key must always win unless the override flag is set, and
// an unpinned build must still work with the environment value.
func TestResolveLicensePublicKey(t *testing.T) {
	const pinned = "pinned-key-base64"
	const envKey = "env-key-base64"

	cases := []struct {
		name       string
		pinned     string
		envKey     string
		override   string
		wantKey    string
		wantPinned bool
	}{
		{name: "pinned only", pinned: pinned, wantKey: pinned, wantPinned: true},
		{name: "pinned ignores env", pinned: pinned, envKey: envKey, wantKey: pinned, wantPinned: true},
		{name: "override allows env", pinned: pinned, envKey: envKey, override: "true", wantKey: envKey, wantPinned: true},
		{name: "override without env keeps pinned", pinned: pinned, override: "true", wantKey: pinned, wantPinned: true},
		{name: "no pinned uses env", envKey: envKey, wantKey: envKey, wantPinned: false},
		{name: "nothing configured", wantKey: "", wantPinned: false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			previous := defaultLicensePublicKey
			defaultLicensePublicKey = tc.pinned
			t.Cleanup(func() { defaultLicensePublicKey = previous })

			t.Setenv("LICENSE_PUBLIC_KEY", tc.envKey)
			t.Setenv(licenseKeyOverrideEnv, tc.override)

			gotKey, gotPinned := resolveLicensePublicKey()

			if gotKey != tc.wantKey {
				t.Fatalf("key = %q, want %q", gotKey, tc.wantKey)
			}
			if gotPinned != tc.wantPinned {
				t.Fatalf("pinned = %v, want %v", gotPinned, tc.wantPinned)
			}
		})
	}
}
