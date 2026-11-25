package license

import "time"

// States reported to PHP and the admin UI.
const (
	StateValid         = "valid"
	StateGrandfathered = "grandfathered"
	StateGrace         = "grace"
	StateExpired       = "expired"
	StateRevoked       = "revoked"
	StateInvalid       = "invalid"
	StateUnknown       = "unknown"
)

// Access levels. `blocked` means the application API answers 403 and only the
// admin panel stays open (license activation must never be locked out).
const (
	AccessFull     = "full"
	AccessReadOnly = "read_only"
	AccessBlocked  = "blocked"
)

// Status is the single source of truth about licensing, returned by
// GET /v1/license/status and embedded into the entitlement ticket.
type Status struct {
	State       string   `json:"state"`
	Access      string   `json:"access"`
	CanInstall  bool     `json:"can_install"`
	Message     string   `json:"message,omitempty"`
	Lic         string   `json:"lic,omitempty"`
	Customer    string   `json:"customer,omitempty"`
	Edition     string   `json:"edition,omitempty"`
	Features    []string `json:"features"`
	Modules     []string `json:"modules"`
	ExpiresAt   string   `json:"expires_at,omitempty"`
	GraceUntil  string   `json:"grace_until,omitempty"`
	Fingerprint string   `json:"fingerprint"`
}

// Evaluate turns a (possibly nil) license into a status. `revoked` is the set of
// license ids present in the CRL. `allowGrandfather` keeps existing installs
// fully usable, `allowDrift` tolerates a small backwards clock drift.
func Evaluate(lic *License, now time.Time, revoked map[string]bool, fingerprint string, allowGrandfather bool) Status {
	status := Status{
		Features:    []string{},
		Modules:     []string{},
		Fingerprint: fingerprint,
	}

	if lic == nil {
		if allowGrandfather {
			status.State = StateGrandfathered
			status.Access = AccessFull
			status.CanInstall = true
			status.Message = "лицензия не установлена: инсталляция считается унаследованной (всё включено)"

			return status
		}

		status.State = StateInvalid
		status.Access = AccessBlocked
		status.Message = "лицензия отсутствует"

		return status
	}

	status.Lic = lic.Lic
	status.Customer = lic.Customer
	status.Edition = lic.Edition
	status.Features = nonEmpty(lic.Features)
	status.Modules = nonEmpty(lic.Modules)
	status.ExpiresAt = lic.ExpiresAt

	graceUntil, graceErr := lic.GraceUntil()
	if graceErr == nil {
		status.GraceUntil = graceUntil.Format(time.RFC3339)
	}

	switch {
	case revoked[lic.Lic]:
		status.State = StateRevoked
		status.Access = AccessBlocked
		status.Message = "лицензия отозвана"
	case !lic.BindsFingerprint(fingerprint):
		status.State = StateInvalid
		status.Access = AccessBlocked
		if len(lic.Servers) == 0 {
			status.Message = "лицензия не привязана к инсталляции"
		} else {
			status.Message = "лицензия выдана для другой инсталляции"
		}
	default:
		notBefore, err := lic.NotBeforeTime()
		if err != nil {
			status.State = StateInvalid
			status.Access = AccessBlocked
			status.Message = "некорректная дата начала лицензии"

			return status
		}

		expires, err := lic.ExpiresTime()
		if err != nil {
			status.State = StateInvalid
			status.Access = AccessBlocked
			status.Message = "некорректная дата окончания лицензии"

			return status
		}

		switch {
		case !notBefore.IsZero() && now.Before(notBefore):
			status.State = StateInvalid
			status.Access = AccessBlocked
			status.Message = "лицензия ещё не вступила в силу"
		case now.Before(expires) || now.Equal(expires):
			status.State = StateValid
			status.Access = AccessFull
			status.CanInstall = true
		case graceErr == nil && (now.Before(graceUntil) || now.Equal(graceUntil)):
			status.State = StateGrace
			status.Access = AccessFull
			status.Message = "лицензия истекла, действует льготный период"
		default:
			status.State = StateExpired
			status.Access = AccessReadOnly
			status.Message = "лицензия истекла"
		}
	}

	return status
}

// UnknownStatus is returned when the core cannot be reached or the ticket
// expired: the product degrades to read-only instead of failing.
func UnknownStatus(fingerprint string) Status {
	return Status{
		State:       StateUnknown,
		Access:      AccessReadOnly,
		Fingerprint: fingerprint,
		Features:    []string{},
		Modules:     []string{},
		Message:     "состояние лицензии неизвестно (сервис недоступен)",
	}
}

// Allows reports whether the status grants the given feature or module id.
func (s Status) Allows(id string) bool {
	// A grandfathered installation has no license file and the product ships
	// fully enabled ("всё включено"), so every module/feature is allowed.
	if s.State == StateGrandfathered {
		return true
	}

	for _, f := range s.Features {
		if f == id {
			return true
		}
	}

	for _, m := range s.Modules {
		if m == id {
			return true
		}
	}

	return false
}

func nonEmpty(values []string) []string {
	if values == nil {
		return []string{}
	}

	return values
}
