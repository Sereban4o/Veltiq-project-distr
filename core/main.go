// Command core is the compiled "protected core" service.
//
// It exposes algorithmically valuable logic to the Laravel backend over HTTP so
// that the implementation ships as a binary instead of readable PHP. Access is
// restricted by a shared token; the service is meant to run on the internal
// Docker network only (never published to the host).
package main

import (
	"crypto/subtle"
	"encoding/json"
	"errors"
	"log"
	"net/http"
	"os"
	"time"

	"github.com/veltiq/core/internal/automation"
	"github.com/veltiq/core/internal/crm"
	"github.com/veltiq/core/internal/mailmatch"
	"github.com/veltiq/core/internal/permissions"
	"github.com/veltiq/core/internal/recurrence"
	"github.com/veltiq/core/internal/searchtext"
	"github.com/veltiq/core/internal/slots"
	"github.com/veltiq/core/internal/timesheet"
)

type server struct {
	token string
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
	srv := &server{token: token}

	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("/v1/calendar/free-slots", srv.authorized(srv.freeSlots))
	mux.HandleFunc("/v1/tasksbase/automation/match", srv.authorized(srv.automationMatch))
	mux.HandleFunc("/v1/tasksbase/timesheet", srv.authorized(srv.timesheetBuild))
	mux.HandleFunc("/v1/crm/automation/match", srv.authorized(srv.crmAutomationMatch))
	mux.HandleFunc("/v1/permissions/resolve", srv.authorized(srv.permissionResolve))
	mux.HandleFunc("/v1/mail/rules/resolve", srv.authorized(srv.mailRulesResolve))
	mux.HandleFunc("/v1/calendar/expand-recurrence", srv.authorized(srv.expandRecurrence))
	mux.HandleFunc("/v1/search/snippets", srv.authorized(srv.searchSnippets))

	httpServer := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      30 * time.Second,
	}

	log.Printf("core service listening on %s", addr)
	if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatalf("server failed: %v", err)
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

func (s *server) freeSlots(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload freeSlotsRequest
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	windowStart, err := time.Parse(time.RFC3339, payload.WindowStart)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid window_start"})

		return
	}

	windowEnd, err := time.Parse(time.RFC3339, payload.WindowEnd)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid window_end"})

		return
	}

	if !windowEnd.After(windowStart) {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "window_end must be after window_start"})

		return
	}

	meetings := make([]slots.Meeting, 0, len(payload.Meetings))
	for _, meeting := range payload.Meetings {
		var start time.Time
		if meeting.Start != nil {
			parsed, err := time.Parse(time.RFC3339, *meeting.Start)
			if err != nil {
				writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid meeting start"})

				return
			}
			start = parsed
		}

		var end *time.Time
		if meeting.End != nil {
			parsed, err := time.Parse(time.RFC3339, *meeting.End)
			if err != nil {
				writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid meeting end"})

				return
			}
			end = &parsed
		}

		meetings = append(meetings, slots.Meeting{Start: start, End: end, AllDay: meeting.AllDay})
	}

	found := slots.Find(windowStart, windowEnd, meetings, payload.Duration)

	result := make([]slotPayload, 0, len(found))
	for _, slot := range found {
		result = append(result, slotPayload{
			Start: slot.Start.UTC().Format(time.RFC3339),
			End:   slot.End.UTC().Format(time.RFC3339),
		})
	}

	writeJSON(w, http.StatusOK, map[string]any{"slots": result})
}

func (s *server) automationMatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload struct {
		Issue   automation.Issue   `json:"issue"`
		Context automation.Context `json:"context"`
		Rules   []automation.Rule  `json:"rules"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"matches": automation.Evaluate(payload.Rules, payload.Issue, payload.Context),
	})
}

func (s *server) timesheetBuild(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload struct {
		Entries []timesheet.Entry `json:"entries"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	writeJSON(w, http.StatusOK, timesheet.Build(payload.Entries))
}

func (s *server) crmAutomationMatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload struct {
		Deal    crm.Deal    `json:"deal"`
		Context crm.Context `json:"context"`
		Rules   []crm.Rule  `json:"rules"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"matches": crm.Evaluate(payload.Rules, payload.Deal, payload.Context),
	})
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

func (s *server) mailRulesResolve(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload struct {
		Rules   []mailmatch.Rule    `json:"rules"`
		Message mailmatch.Message  `json:"message"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"rule_id": mailmatch.Resolve(payload.Rules, payload.Message),
	})
}

func (s *server) expandRecurrence(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{"error": "method not allowed"})

		return
	}

	var payload struct {
		Start    string   `json:"start"`
		Duration int      `json:"duration_seconds"`
		Rrule    string   `json:"rrule"`
		Exdates  []string `json:"exdates"`
		From     string   `json:"from"`
		To       string   `json:"to"`
		Limit    int      `json:"limit"`
		Timezone string   `json:"timezone"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&payload); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON"})

		return
	}

	parse := func(value string) (time.Time, bool) {
		parsed, err := time.Parse(time.RFC3339, value)

		return parsed, err == nil
	}

	start, ok := parse(payload.Start)
	from, okFrom := parse(payload.From)
	to, okTo := parse(payload.To)
	if !ok || !okFrom || !okTo {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "invalid timestamps"})

		return
	}

	exdates := make([]time.Time, 0, len(payload.Exdates))
	for _, value := range payload.Exdates {
		if parsed, parsedOK := parse(value); parsedOK {
			exdates = append(exdates, parsed)
		}
	}

	result, err := recurrence.Expand(recurrence.Request{
		Start:    start,
		Duration: time.Duration(payload.Duration) * time.Second,
		Rrule:    payload.Rrule,
		Exdates:  exdates,
		From:     from,
		To:       to,
		Limit:    payload.Limit,
		Timezone: payload.Timezone,
	})

	var unsupported *recurrence.UnsupportedError
	if errors.As(err, &unsupported) {
		// The caller keeps its own engine; a distinct status avoids caching a
		// "successful" empty result.
		writeJSON(w, http.StatusNotImplemented, map[string]string{"error": unsupported.Error()})

		return
	}
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})

		return
	}

	items := make([]slotPayload, 0, len(result.Items))
	for _, item := range result.Items {
		items = append(items, slotPayload{
			Start: item.Start.UTC().Format(time.RFC3339),
			End:   item.End.UTC().Format(time.RFC3339),
		})
	}

	writeJSON(w, http.StatusOK, map[string]any{"items": items, "truncated": result.Truncated})
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
