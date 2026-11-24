// Command core is the compiled "protected core" service.
//
// It exposes algorithmically valuable logic to the Laravel backend over HTTP so
// that the implementation ships as a binary instead of readable PHP. Access is
// restricted by a shared token; the service is meant to run on the internal
// Docker network only (never published to the host).
package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"github.com/veltiq/core/internal/entitlement"
	"github.com/veltiq/core/internal/pageacl"
	"github.com/veltiq/core/internal/permissions"
	"github.com/veltiq/core/internal/platform"
	"github.com/veltiq/core/internal/registry"
	"github.com/veltiq/core/internal/searchtext"
	"github.com/veltiq/core/internal/store"
)

type server struct {
	token    string
	platform *platform.Service
}

type freeSlotsRequest struct {
	WindowStart string `json:"window_start"`
	WindowEnd   string `json:"window_end"`
	Duration    int    `json:"duration"`
	Meetings    []struct {
		Start  *string `json:"start"`
		End    *string `json:"end"`
		AllDay bool    `json:"all_day"`
	} `json:"meetings"`
}

type slotPayload struct {
	Start string `json:"start"`
	End   string `json:"end"`
}

func main() {
	token := os.Getenv("CORE_TOKEN")
	if token == "" {
		log.Fatal("CORE_TOKEN is required: refusing to start an unauthenticated core service")
	}

	addr := os.Getenv("CORE_ADDR")
	if addr == "" {
		addr = ":8080"
	}

	mux := http.NewServeMux()

	platformService := newPlatformService()
	srv := &server{token: token, platform: platformService}

	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("/v1/license/status", srv.authorized(srv.licenseStatus))
	mux.HandleFunc("/v1/license/request", srv.authorized(srv.licenseRequest))
	mux.HandleFunc("/v1/license/activate", srv.authorized(srv.licenseActivate))
	mux.HandleFunc("/v1/license/crl", srv.authorized(srv.licenseCRL))
	mux.HandleFunc("/v1/license/usage", srv.authorized(srv.licenseUsage))
	mux.HandleFunc("/v1/entitlement/pubkey", srv.authorized(srv.entitlementPubKey))
	mux.HandleFunc("/v1/entitlement/token", srv.authorized(srv.entitlementToken))
	mux.HandleFunc("/v1/extensions", srv.authorized(srv.extensionsList))
	mux.HandleFunc("/v1/extensions/sync", srv.authorized(srv.extensionsSync))
	mux.HandleFunc("/v1/extensions/set", srv.authorized(srv.extensionsSet))
	mux.HandleFunc("/v1/audit", srv.authorized(srv.auditAppend))
	mux.HandleFunc("/v1/permissions/resolve", srv.authorized(srv.permissionResolve))
	mux.HandleFunc("/v1/knowledgebase/page-acl/resolve", srv.authorized(srv.pageAclResolve))
	mux.HandleFunc("/v1/search/snippets", srv.authorized(srv.searchSnippets))

	httpServer := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      30 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	platformService.StartHeartbeat(ctx)

	log.Printf("core service listening on %s", addr)
	if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatalf("server failed: %v", err)
	}
}

// newPlatformService assembles the licensing control plane from the environment.
// It never fails the process: without a public key the product simply reports the
// grandfather state, and without Postgres it falls back to file state.
func newPlatformService() *platform.Service {
	cfg := platform.Config{
		StateDir:          envOr("CORE_STATE_DIR", "/var/lib/core"),
		DatabaseURL:       os.Getenv("CORE_DATABASE_URL"),
		PublicKeyBase64:   os.Getenv("LICENSE_PUBLIC_KEY"),
		ClusterID:         os.Getenv("CORE_CLUSTER_ID"),
		AllowGrandfather:  envBool("LICENSE_ALLOW_GRANDFATHER", true),
		LicenseServerURL:  os.Getenv("LICENSE_SERVER_URL"),
		HeartbeatInterval: time.Duration(envInt("LICENSE_HEARTBEAT_HOURS", 6)) * time.Hour,
		AppKey:            os.Getenv("APP_KEY"),
	}

	service, err := platform.New(context.Background(), cfg)
	if err != nil {
		log.Fatalf("platform core failed to start: %v", err)
	}

	return service
}

func envInt(key string, fallback int) int {
	if value := os.Getenv(key); value != "" {
		if parsed, err := strconv.Atoi(value); err == nil {
			return parsed
		}
	}

	return fallback
}

func envOr(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}

	return fallback
}

func envBool(key string, fallback bool) bool {
	switch os.Getenv(key) {
	case "1", "true", "yes":
		return true
	case "0", "false", "no":
		return false
	default:
		return fallback
	}
}

// authorized rejects requests without the shared token, comparing in constant
// time so the token cannot be probed byte by byte.
func (s *server) authorized(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		provided := r.Header.Get("X-Core-Token")
		if len(provided) != len(s.token) || subtle.ConstantTimeCompare([]byte(provided), []byte(s.token)) != 1 {
			writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})

			return
		}

		next(w, r)
	}
}

func (s *server) permissionResolve(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var request permissions.Request
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&request); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	if request.Permission == "" {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "permission is required"})

		return
	}

	writeJSON(w, http.StatusOK, permissions.Resolve(request))
}

func (s *server) pageAclResolve(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var request pageacl.Request
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&request); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"permission": pageacl.Resolve(request)})
}

func (s *server) searchSnippets(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload struct {
		Query  string `json:"query"`
		Length int    `json:"length"`
		Texts  []struct {
			Key  string `json:"key"`
			Text string `json:"text"`
		} `json:"texts"`
		Window struct {
			PerPage int `json:"per_page"`
			Page    int `json:"page"`
		} `json:"window"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 8<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	snippets := make(map[string]string, len(payload.Texts))
	for _, item := range payload.Texts {
		snippets[item.Key] = searchtext.Snippet(item.Text, payload.Query, payload.Length)
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"snippets": snippets,
		"window":   searchtext.Window(payload.Window.PerPage, payload.Window.Page),
	})
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)

	if err := json.NewEncoder(w).Encode(body); err != nil {
		log.Printf("write response: %v", err)
	}
}

// ── Licensing control plane ────────────────────────────────────────────────

func (s *server) licenseStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"fingerprint":  s.platform.Fingerprint(),
		"status":       s.platform.Status(),
		"clock_tamper": s.platform.ClockTamper(),
		"revoked":      s.platform.Revoked(),
		"active_users": s.platform.ActiveUsers(),
	})
}

func (s *server) licenseRequest(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost && r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	code, err := s.platform.RequestCode()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "cannot build request code"})

		return
	}

	writeJSON(w, http.StatusOK, code)
}

func (s *server) licenseActivate(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	body, ok := readBody(w, r, 1<<20)
	if !ok {
		return
	}

	status, err := s.platform.Activate(body)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"status": status})
}

func (s *server) licenseCRL(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	body, ok := readBody(w, r, 4<<20)
	if !ok {
		return
	}

	if err := s.platform.ApplyCRL(body); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})

		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *server) entitlementPubKey(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]string{
		"public_key": entitlement.PublicKeyBase64(s.platform.TicketKey()),
	})
}

func (s *server) entitlementToken(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	token, err := s.platform.Ticket()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "cannot issue ticket"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"token": token})
}

func (s *server) extensionsList(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	extensions := s.platform.Extensions()
	writeJSON(w, http.StatusOK, map[string]any{
		"extensions": extensions,
		"enabled":    registry.EnabledIDs(extensions),
	})
}

func (s *server) extensionsSync(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	body, ok := readBody(w, r, 4<<20)
	if !ok {
		return
	}

	var payload struct {
		Extensions []store.Extension `json:"extensions"`
	}

	if err := json.Unmarshal(body, &payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	if err := s.platform.SyncExtensions(payload.Extensions); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})

		return
	}

	extensions := s.platform.Extensions()
	writeJSON(w, http.StatusOK, map[string]any{
		"extensions": extensions,
		"enabled":    registry.EnabledIDs(extensions),
	})
}

func (s *server) extensionsSet(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	body, ok := readBody(w, r, 1<<20)
	if !ok {
		return
	}

	var payload struct {
		ID      string `json:"id"`
		Enabled bool   `json:"enabled"`
	}

	if err := json.Unmarshal(body, &payload); err != nil || payload.ID == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "id is required"})

		return
	}

	extensions, err := s.platform.SetExtension(payload.ID, payload.Enabled)
	if err != nil {
		writeJSON(w, http.StatusConflict, map[string]string{"error": err.Error()})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"extensions": extensions,
		"enabled":    registry.EnabledIDs(extensions),
	})
}

// licenseUsage stores the active-user figure the application reports; the core
// sends it in the heartbeat to the vendor server.
func (s *server) licenseUsage(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	body, ok := readBody(w, r, 1<<16)
	if !ok {
		return
	}

	var payload struct {
		ActiveUsers int `json:"active_users"`
	}

	if err := json.Unmarshal(body, &payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	if err := s.platform.SetActiveUsers(payload.ActiveUsers); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})

		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// auditAppend records an application-side action (module install/uninstall) in
// the core audit trail.
func (s *server) auditAppend(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	body, ok := readBody(w, r, 1<<20)
	if !ok {
		return
	}

	var payload struct {
		Action string `json:"action"`
		Detail string `json:"detail"`
		Actor  string `json:"actor"`
	}

	if err := json.Unmarshal(body, &payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	if err := s.platform.Audit(payload.Action, payload.Detail, payload.Actor); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})

		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// readBody reads a bounded request body, answering 400 on failure.
func readBody(w http.ResponseWriter, r *http.Request, limit int64) ([]byte, bool) {
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, limit))
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "cannot read body"})

		return nil, false
	}

	return body, true
}
