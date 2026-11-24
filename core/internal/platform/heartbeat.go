package platform

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"strings"
	"time"

	"github.com/veltiq/core/internal/license"
)

// heartbeatRequest is what the vendor license server receives. `active_users`
// is pushed by the application (`POST /v1/license/usage`), because the core
// deliberately has no access to application data.
type heartbeatRequest struct {
	Lic         string `json:"lic"`
	Fingerprint string `json:"fingerprint"`
	Version     string `json:"version"`
	ActiveUsers int    `json:"active_users"`
	IssuedAt    string `json:"issued_at"`
}

// heartbeatResponse may carry a refreshed license and revoked ids.
type heartbeatResponse struct {
	License json.RawMessage `json:"license"`
	Crl     []string        `json:"crl"`
	Revoked []string        `json:"revoked"`
}

// heartbeat anchors the monotonic clock and, when a license server is configured,
// refreshes the license. Failures never degrade the running product by
// themselves: expiry/grace is decided by the stored license.
func (s *Service) heartbeat(ctx context.Context) {
	s.mu.Lock()
	s.reloadStatusLocked(time.Now())
	raw := s.state.LicenseRaw
	activeUsers := s.state.Meta.ActiveUsers
	s.mu.Unlock()

	if err := s.persist(); err != nil {
		log.Printf("core: heartbeat persist: %v", err)
	}

	if strings.TrimSpace(s.cfg.LicenseServerURL) == "" || strings.TrimSpace(raw) == "" || s.vendorKey == nil {
		return
	}

	lic, err := license.Parse([]byte(raw), s.vendorKey)
	if err != nil {
		s.appendAudit("license.heartbeat.invalid_license", err.Error())

		return
	}

	payload, err := json.Marshal(heartbeatRequest{
		Lic:         lic.Lic,
		Fingerprint: s.fingerprint,
		Version:     Version,
		ActiveUsers: activeUsers,
		IssuedAt:    time.Now().UTC().Format(time.RFC3339),
	})
	if err != nil {
		return
	}

	requestCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	endpoint := strings.TrimRight(s.cfg.LicenseServerURL, "/") + "/v1/heartbeat"

	request, err := http.NewRequestWithContext(requestCtx, http.MethodPost, endpoint, bytes.NewReader(payload))
	if err != nil {
		log.Printf("core: heartbeat request: %v", err)

		return
	}

	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("X-Fingerprint", s.fingerprint)

	response, err := http.DefaultClient.Do(request)
	if err != nil {
		log.Printf("core: heartbeat transport error: %v", err)
		s.appendAudit("license.heartbeat.failed", err.Error())

		return
	}
	defer response.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(response.Body, 1<<20))

	if response.StatusCode != http.StatusOK {
		log.Printf("core: heartbeat rejected with status %d", response.StatusCode)
		s.appendAudit("license.heartbeat.rejected", fmt.Sprintf("status=%d %s", response.StatusCode, strings.TrimSpace(string(body))))

		return
	}

	var answer heartbeatResponse
	if err := json.Unmarshal(body, &answer); err != nil {
		s.appendAudit("license.heartbeat.malformed", err.Error())

		return
	}

	if len(answer.License) > 0 {
		s.applyServerLicense(answer.License)
	}

	revoked := append(answer.Crl, answer.Revoked...)
	if len(revoked) > 0 {
		s.mu.Lock()
		s.state.Meta.Revoked = dedupe(append(s.state.Meta.Revoked, revoked...))
		s.reloadStatusLocked(time.Now())
		s.mu.Unlock()

		if err := s.persist(); err != nil {
			log.Printf("core: heartbeat persist: %v", err)
		}
	}

	s.appendAudit("license.heartbeat", fmt.Sprintf("ok active_users=%d", activeUsers))
}

// applyServerLicense accepts either an envelope object or its JSON string form.
func (s *Service) applyServerLicense(raw json.RawMessage) {
	candidate := raw

	if len(candidate) > 0 && candidate[0] == '"' {
		var encoded string
		if err := json.Unmarshal(candidate, &encoded); err != nil {
			s.appendAudit("license.heartbeat.license_invalid", err.Error())

			return
		}

		candidate = []byte(encoded)
	}

	lic, err := license.Parse(candidate, s.vendorKey)
	if err != nil {
		s.appendAudit("license.heartbeat.license_invalid", err.Error())

		return
	}

	s.mu.Lock()
	// A refreshed license legitimately reuses the nonce, so it is NOT treated as
	// a replay — only manual activation enforces nonce uniqueness.
	s.state.LicenseRaw = string(candidate)
	s.reloadStatusLocked(time.Now())
	status := s.status
	s.mu.Unlock()

	if err := s.persist(); err != nil {
		log.Printf("core: heartbeat persist: %v", err)
	}

	s.appendAudit("license.refreshed", lic.Lic+" state="+status.State)
}

// SetActiveUsers stores the usage figure reported by the application.
func (s *Service) SetActiveUsers(activeUsers int) error {
	if activeUsers < 0 {
		activeUsers = 0
	}

	s.mu.Lock()
	s.state.Meta.ActiveUsers = activeUsers
	s.mu.Unlock()

	return s.persist()
}
