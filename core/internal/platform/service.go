// Package platform wires license verification, the entitlement ticket and the
// module registry into one service. It is the "control plane" of the product:
// PHP owns the data, this owns the decisions.
package platform

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"net"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/veltiq/core/internal/entitlement"
	"github.com/veltiq/core/internal/license"
	"github.com/veltiq/core/internal/registry"
	"github.com/veltiq/core/internal/store"
)

// Version is reported in request codes and heartbeats.
const Version = "1.0.0"

// ErrNoVendorKey means licensing cannot be verified because no public key was
// configured — the service still runs, reporting the grandfather state.
var ErrNoVendorKey = errors.New("no license public key configured")

// Config is the resolved environment.
type Config struct {
	StateDir           string
	DatabaseURL        string
	PublicKeyBase64    string
	ClusterID          string
	AllowGrandfather   bool
	LicenseServerURL   string
	LicenseServerToken string
	HeartbeatInterval  time.Duration
	AppKey             string
	// FingerprintMode selects the fingerprint inputs: "v1" (legacy, default)
	// or "v2" (adds machine/board id + MACs; requires re-binding).
	FingerprintMode string
	// InstallID is an optional operator-provided UUID persisted on the host; the
	// strongest binding factor when set.
	InstallID string
}

// Service holds the current decision state.
type Service struct {
	cfg         Config
	store       store.Store
	vendorKey   ed25519.PublicKey
	ticketKey   ed25519.PrivateKey
	fingerprint string

	mu     sync.RWMutex
	state  store.State
	status license.Status
}

// New opens persistence, loads the state and evaluates the license.
func New(ctx context.Context, cfg Config) (*Service, error) {
	if cfg.StateDir == "" {
		cfg.StateDir = "/var/lib/core"
	}

	if cfg.HeartbeatInterval <= 0 {
		cfg.HeartbeatInterval = 6 * time.Hour
	}

	stateStore, err := openStore(ctx, cfg)
	if err != nil {
		return nil, err
	}

	state, err := stateStore.Load()
	if err != nil {
		log.Printf("core: load state: %v (starting empty)", err)
		state = store.State{Extensions: map[string]store.Extension{}}
	}

	if state.Meta.InstalledAt == "" {
		state.Meta.InstalledAt = time.Now().UTC().Format(time.RFC3339)
	}

	_, ticketKey, err := entitlement.LoadOrCreateKeypair(cfg.StateDir)
	if err != nil {
		return nil, fmt.Errorf("ticket keypair: %w", err)
	}

	service := &Service{
		cfg:         cfg,
		store:       stateStore,
		ticketKey:   ticketKey,
		fingerprint: fingerprint(cfg),
		state:       state,
	}

	if key, err := parsePublicKey(cfg.PublicKeyBase64); err != nil {
		log.Printf("core: %v — license verification disabled, reporting grandfather state", err)
	} else {
		service.vendorKey = key
	}

	service.reloadStatus(time.Now())

	if err := service.persist(); err != nil {
		log.Printf("core: persist initial state: %v", err)
	}

	log.Printf("core: fingerprint %s, license state %s (access %s)",
		service.fingerprint, service.status.State, service.status.Access)

	return service, nil
}

func openStore(ctx context.Context, cfg Config) (store.Store, error) {
	if cfg.DatabaseURL != "" {
		pg, err := store.NewPostgresStore(ctx, cfg.DatabaseURL)
		if err == nil {
			return pg, nil
		}

		log.Printf("core: postgres unavailable (%v), falling back to file state in %s", err, cfg.StateDir)
	}

	return store.NewFileStore(cfg.StateDir)
}

func fingerprint(cfg Config) string {
	inputs := []string{cfg.ClusterID, cfg.AppKey, cfg.InstallID}

	for _, path := range []string{"/etc/machine-id", "/var/lib/dbus/machine-id"} {
		if id, err := os.ReadFile(path); err == nil {
			inputs = append(inputs, string(id))

			break
		}
	}

	if host, err := os.Hostname(); err == nil {
		inputs = append(inputs, host)
	}

	// v2 adds hardware identifiers so a copied install on different hardware
	// (or a different VM) yields a different fingerprint.
	if cfg.FingerprintMode == "v2" {
		for _, path := range []string{"/sys/class/dmi/id/product_uuid", "/sys/class/dmi/id/board_serial"} {
			if id, err := os.ReadFile(path); err == nil {
				inputs = append(inputs, string(id))
			}
		}

		inputs = append(inputs, macAddresses()...)
	}

	return license.Fingerprint(inputs...)
}

// macAddresses returns sorted MACs of non-loopback interfaces (v2 mode only).
func macAddresses() []string {
	interfaces, err := net.Interfaces()
	if err != nil {
		return nil
	}

	macs := make([]string, 0, len(interfaces))
	for _, iface := range interfaces {
		if iface.Flags&net.FlagLoopback != 0 || len(iface.HardwareAddr) == 0 {
			continue
		}

		macs = append(macs, iface.HardwareAddr.String())
	}

	sort.Strings(macs)

	return macs
}

func parsePublicKey(encoded string) (ed25519.PublicKey, error) {
	encoded = strings.TrimSpace(encoded)
	if encoded == "" {
		return nil, ErrNoVendorKey
	}

	raw, err := base64.StdEncoding.DecodeString(encoded)
	if err != nil {
		return nil, fmt.Errorf("%w: %v", ErrNoVendorKey, err)
	}

	if len(raw) != ed25519.PublicKeySize {
		return nil, ErrNoVendorKey
	}

	return ed25519.PublicKey(raw), nil
}

// Status returns the current decision.
func (s *Service) Status() license.Status {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return s.status
}

// Fingerprint is the installation id shown to the operator.
func (s *Service) Fingerprint() string { return s.fingerprint }

// TicketKey exposes the public half PHP pins.
func (s *Service) TicketKey() ed25519.PublicKey {
	return s.ticketKey.Public().(ed25519.PublicKey)
}

// Ticket signs a short-lived entitlement ticket for PHP.
func (s *Service) Ticket() (string, error) {
	status := s.Status()
	now := time.Now().UTC()

	return entitlement.Sign(s.ticketKey, entitlement.Ticket{
		Features:         status.Features,
		Modules:          status.Modules,
		State:            status.State,
		Access:           status.Access,
		IssuedAt:         now.Format(time.RFC3339),
		ExpiresAt:        now.Add(entitlement.TTL).Format(time.RFC3339),
		LicenseExpiresAt: status.ExpiresAt,
	})
}

// RequestCode is the offline activation request.
type RequestCode struct {
	Fingerprint string `json:"fingerprint"`
	Nonce       string `json:"nonce"`
	Version     string `json:"version"`
	Date        string `json:"date"`
}

// RequestCode builds a signed-license request for the operator to send to the
// vendor.
func (s *Service) RequestCode() (RequestCode, error) {
	nonce, err := randomHex(8)
	if err != nil {
		return RequestCode{}, err
	}

	return RequestCode{
		Fingerprint: s.fingerprint,
		Nonce:       nonce,
		Version:     Version,
		Date:        time.Now().UTC().Format(time.RFC3339),
	}, nil
}

// Activate verifies and stores a license file (online or offline).
func (s *Service) Activate(raw []byte) (license.Status, error) {
	if s.vendorKey == nil {
		return license.Status{}, ErrNoVendorKey
	}

	lic, err := license.Parse(raw, s.vendorKey)
	if err != nil {
		s.appendAudit("license.activate.rejected", err.Error())

		return license.Status{}, err
	}

	s.mu.Lock()
	if contains(s.state.Meta.SeenNonces, lic.Nonce) {
		s.mu.Unlock()
		s.appendAudit("license.activate.replay", "nonce "+lic.Nonce)

		return license.Status{}, errors.New("license nonce was already used")
	}

	s.state.Meta.SeenNonces = rememberNonce(s.state.Meta.SeenNonces, lic.Nonce)
	s.state.LicenseRaw = string(raw)
	s.reloadStatusLocked(time.Now())
	status := s.status
	s.mu.Unlock()

	if err := s.persist(); err != nil {
		return status, err
	}

	s.appendAudit("license.activate", lic.Lic+" state="+status.State)

	return status, nil
}

// ApplyCRL stores a signed revocation list. The payload is a license-file-shaped
// envelope whose `lic` list enumerates revoked ids.
func (s *Service) ApplyCRL(raw []byte) error {
	// CRL is verified like a license but its `modules` field carries revocation
	// ids, so the same key and format are reused.
	lic, err := license.Parse(raw, s.vendorKey)
	if err != nil {
		return err
	}

	s.mu.Lock()
	s.state.Meta.Revoked = append(s.state.Meta.Revoked, lic.Modules...)
	s.state.Meta.Revoked = dedupe(s.state.Meta.Revoked)
	s.reloadStatusLocked(time.Now())
	s.mu.Unlock()

	if err := s.persist(); err != nil {
		return err
	}

	s.appendAudit("license.crl", fmt.Sprintf("%d entries", len(lic.Modules)))

	return nil
}

// SyncExtensions records the manifests PHP discovered, preserving enable flags.
func (s *Service) SyncExtensions(declared []store.Extension) error {
	s.mu.Lock()
	if s.state.Extensions == nil {
		s.state.Extensions = map[string]store.Extension{}
	}

	now := time.Now().UTC().Format(time.RFC3339)
	for _, module := range declared {
		if module.ID == "" {
			continue
		}

		existing, ok := s.state.Extensions[module.ID]
		if ok {
			module.Enabled = existing.Enabled
		} else {
			module.Enabled = module.DefaultEnabled
		}

		module.DeclaredAt = now
		s.state.Extensions[module.ID] = module
	}

	s.mu.Unlock()

	return s.persist()
}

// SetExtension toggles one module, refusing to enable what the license disallows.
func (s *Service) SetExtension(id string, enabled bool) ([]registry.View, error) {
	s.mu.Lock()
	module, ok := s.state.Extensions[id]
	if !ok {
		s.mu.Unlock()

		return nil, fmt.Errorf("unknown module %q", id)
	}

	if enabled {
		views := registry.Resolve(s.status, s.state.Extensions)
		for _, view := range views {
			if view.ID == id && !view.Allowed {
				s.mu.Unlock()
				s.appendAudit("module.enable.rejected", id+": "+view.Reason)

				return views, fmt.Errorf("module %q is not allowed: %s", id, view.Reason)
			}
		}
	}

	module.Enabled = enabled
	s.state.Extensions[id] = module
	views := registry.Resolve(s.status, s.state.Extensions)
	s.mu.Unlock()

	if err := s.persist(); err != nil {
		return views, err
	}

	s.appendAudit("module.set", fmt.Sprintf("%s enabled=%t", id, enabled))

	return views, nil
}

// Modules returns the resolved module list for PHP/SPA.
func (s *Service) Extensions() []registry.View {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return registry.Resolve(s.status, s.state.Extensions)
}

// StartHeartbeat anchors the monotonic clock and, when a license server is
// configured, refreshes the license in the background.
func (s *Service) StartHeartbeat(ctx context.Context) {
	go func() {
		ticker := time.NewTicker(s.cfg.HeartbeatInterval)
		defer ticker.Stop()

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				s.heartbeat(ctx)
			}
		}
	}()
}

// reloadStatus recomputes the license decision.
func (s *Service) reloadStatus(now time.Time) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.reloadStatusLocked(now)
}

func (s *Service) reloadStatusLocked(now time.Time) {
	// Clock anchor: a backwards jump is recorded, never fatal.
	if s.state.Meta.LastSeenAt != "" {
		if lastSeen, err := time.Parse(time.RFC3339, s.state.Meta.LastSeenAt); err == nil {
			driftDays := s.driftDays()
			if now.Before(lastSeen.AddDate(0, 0, -driftDays)) {
				s.state.Meta.ClockTamper = true
				log.Printf("core: clock tamper detected (now %s < last seen %s)", now.Format(time.RFC3339), lastSeen.Format(time.RFC3339))
			}
		}
	}

	lic, err := s.currentLicense()
	if err != nil {
		log.Printf("core: stored license is unreadable: %v", err)
	}

	revoked := map[string]bool{}
	for _, id := range s.state.Meta.Revoked {
		revoked[id] = true
	}

	s.status = license.Evaluate(lic, now, revoked, s.fingerprint, s.cfg.AllowGrandfather)

	if s.state.Meta.ClockTamper && s.status.State == license.StateValid {
		// Do not flatter a tampered clock: degrade to grace instead of valid.
		s.status.State = license.StateGrace
		s.status.Message = "обнаружен откат часов, действует льготный режим"
	}

	s.state.Meta.LastSeenAt = now.UTC().Format(time.RFC3339)
}

func (s *Service) driftDays() int {
	lic, err := s.currentLicense()
	if err != nil || lic == nil || lic.MaxDriftDays <= 0 {
		return 3
	}

	return lic.MaxDriftDays
}

// currentLicense must be called with the lock held (or before sharing).
func (s *Service) currentLicense() (*license.License, error) {
	if strings.TrimSpace(s.state.LicenseRaw) == "" {
		return nil, nil
	}

	if s.vendorKey == nil {
		return nil, ErrNoVendorKey
	}

	lic, err := license.Parse([]byte(s.state.LicenseRaw), s.vendorKey)
	if err != nil {
		return nil, err
	}

	return &lic, nil
}

func (s *Service) persist() error {
	s.mu.RLock()
	snapshot := s.state
	s.mu.RUnlock()

	snapshot.Extensions = copyExtensions(snapshot.Extensions)

	return s.store.Save(snapshot)
}

func (s *Service) appendAudit(action, detail string) {
	if err := s.store.AppendAudit(store.Audit{Action: action, Detail: detail}); err != nil {
		log.Printf("core: audit append: %v", err)
	}
}

// Audit records an action triggered by the application (module install, license
// change). The core owns the audit trail for licensing/module decisions.
func (s *Service) Audit(action, detail, actor string) error {
	if action == "" {
		return errors.New("audit action is required")
	}

	return s.store.AppendAudit(store.Audit{Action: action, Detail: detail, Actor: actor})
}

// Revoked returns the ids currently in the CRL (for the status endpoint).
func (s *Service) Revoked() []string {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return append([]string{}, s.state.Meta.Revoked...)
}

// ClockTamper reports the anchor flag.
func (s *Service) ClockTamper() bool {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return s.state.Meta.ClockTamper
}

// ActiveUsers is the usage figure reported by the application.
func (s *Service) ActiveUsers() int {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return s.state.Meta.ActiveUsers
}

func copyExtensions(in map[string]store.Extension) map[string]store.Extension {
	out := make(map[string]store.Extension, len(in))
	for k, v := range in {
		out[k] = v
	}

	return out
}

func randomHex(bytes int) (string, error) {
	buf := make([]byte, bytes)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}

	return hex.EncodeToString(buf), nil
}

func contains(values []string, needle string) bool {
	if needle == "" {
		return false
	}

	for _, value := range values {
		if value == needle {
			return true
		}
	}

	return false
}

func rememberNonce(values []string, nonce string) []string {
	values = append(values, nonce)
	if len(values) > 100 {
		values = values[len(values)-100:]
	}

	return values
}

func dedupe(values []string) []string {
	seen := map[string]bool{}
	out := make([]string, 0, len(values))

	for _, value := range values {
		if value == "" || seen[value] {
			continue
		}

		seen[value] = true
		out = append(out, value)
	}

	return out
}
