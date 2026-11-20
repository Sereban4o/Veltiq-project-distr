// Package automation decides which task-automation rules fire for an issue.
//
// The rule engine (the product's tuning logic) lives here so it ships as a
// binary; the caller keeps database access, permission checks and the side
// effects (assign, priority, notifications, audit).
package automation

// Rule is one admin-defined automation rule as sent by the backend.
type Rule struct {
	ID        int    `json:"id"`
	ProjectID *int   `json:"project_id"`
	Position  int    `json:"position"`
	// Conditions is decoded leniently: an empty PHP array arrives as `[]`, which
	// cannot be unmarshalled into a map.
	Conditions any      `json:"conditions"`
	Actions    []Action `json:"actions"`
}

// Action is a single effect of a matched rule.
type Action struct {
	Type     string `json:"type"`
	UserID   *int   `json:"user_id,omitempty"`
	Priority string `json:"priority,omitempty"`
	Message  string `json:"message,omitempty"`
	Title    string `json:"title,omitempty"`
}

// Issue is the snapshot the conditions are evaluated against.
type Issue struct {
	ID        int    `json:"id"`
	ProjectID int    `json:"project_id"`
	StatusID  int    `json:"status_id"`
	Priority  string `json:"priority"`
}

// Context carries trigger-specific values (e.g. the status transition).
type Context struct {
	From int `json:"from"`
	To   int `json:"to"`
}

// Match is a rule that fired, with the actions the caller should apply.
type Match struct {
	RuleID  int      `json:"rule_id"`
	Actions []Action `json:"actions"`
}

// allowedActions filters out anything the backend does not implement, so a
// malformed rule cannot smuggle an unexpected effect through.
var allowedActions = map[string]bool{
	"assign":       true,
	"set_priority": true,
	"notify":       true,
}

var allowedPriorities = map[string]bool{
	"lowest":  true,
	"low":     true,
	"medium":  true,
	"high":    true,
	"highest": true,
}

// Evaluate returns the rules that fire, in the order they should be applied.
//
// Rules arrive already filtered by trigger/project/active state (that is a data
// concern); here only the conditions are checked. The order is the caller's
// (position, id), which is preserved so later rules still win.
func Evaluate(rules []Rule, issue Issue, context Context) []Match {
	matches := make([]Match, 0, len(rules))

	for _, rule := range rules {
		if !ruleMatches(rule, issue, context) {
			continue
		}

		actions := filterActions(rule.Actions)
		if len(actions) == 0 {
			continue
		}

		matches = append(matches, Match{RuleID: rule.ID, Actions: actions})
	}

	return matches
}

func ruleMatches(rule Rule, issue Issue, context Context) bool {
	conditions := asMap(rule.Conditions)

	if toStatus, ok := intCondition(conditions, "to_status_id"); ok && toStatus != context.To {
		return false
	}

	if priority, ok := stringCondition(conditions, "priority"); ok && priority != issue.Priority {
		return false
	}

	return true
}

// asMap accepts the several shapes an empty PHP associative array can take:
// a missing value, an empty slice (`[]`) or an object.
func asMap(value any) map[string]any {
	if typed, ok := value.(map[string]any); ok {
		return typed
	}

	return nil
}

func filterActions(actions []Action) []Action {
	result := make([]Action, 0, len(actions))

	for _, action := range actions {
		if !allowedActions[action.Type] {
			continue
		}

		switch action.Type {
		case "assign":
			if action.UserID == nil || *action.UserID <= 0 {
				continue
			}
		case "set_priority":
			if !allowedPriorities[action.Priority] {
				continue
			}
		}

		result = append(result, action)
	}

	return result
}

func intCondition(conditions map[string]any, key string) (int, bool) {
	if conditions == nil {
		return 0, false
	}

	value, ok := conditions[key]
	if !ok || value == nil {
		return 0, false
	}

	switch typed := value.(type) {
	case float64:
		return int(typed), true
	case int:
		return typed, true
	default:
		return 0, false
	}
}

func stringCondition(conditions map[string]any, key string) (string, bool) {
	if conditions == nil {
		return "", false
	}

	value, ok := conditions[key]
	if !ok || value == nil {
		return "", false
	}

	typed, ok := value.(string)

	return typed, ok
}
