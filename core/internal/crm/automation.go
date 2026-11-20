// Package crm decides which CRM automation rules fire for a deal.
//
// Only the decision lives here; creating tasks and sending notifications stays
// in PHP, together with database access and permissions.
package crm

// Rule is one admin-defined CRM automation rule.
type Rule struct {
	ID        int    `json:"id"`
	Position  int    `json:"position"`
	Conditions any   `json:"conditions"`
	Action    string `json:"action"`
	Payload   any    `json:"action_payload"`
}

// Deal is the snapshot the conditions are evaluated against.
type Deal struct {
	ID        int     `json:"id"`
	StageID   int     `json:"stage_id"`
	Status    string  `json:"status"`
	Amount    float64 `json:"amount"`
	CompanyID *int    `json:"company_id"`
}

// Context carries trigger-specific values.
type Context struct {
	ToStageID *int `json:"to_stage_id"`
}

// Match is a fired rule with the action the caller should perform.
type Match struct {
	RuleID  int    `json:"rule_id"`
	Action  string `json:"action"`
	Payload any    `json:"action_payload"`
}

var allowedActions = map[string]bool{
	"create_task":        true,
	"send_notification":  true,
}

// Evaluate returns the rules that fire, in the caller's order.
func Evaluate(rules []Rule, deal Deal, context Context) []Match {
	matches := make([]Match, 0, len(rules))

	for _, rule := range rules {
		if !allowedActions[rule.Action] {
			continue
		}

		if !ruleMatches(rule, deal, context) {
			continue
		}

		matches = append(matches, Match{RuleID: rule.ID, Action: rule.Action, Payload: rule.Payload})
	}

	return matches
}

func ruleMatches(rule Rule, deal Deal, context Context) bool {
	conditions := asMap(rule.Conditions)

	if stageID, ok := intCondition(conditions, "stage_id"); ok {
		target := deal.StageID
		if context.ToStageID != nil {
			target = *context.ToStageID
		}
		if stageID != target {
			return false
		}
	}

	if status, ok := stringCondition(conditions, "status"); ok && status != deal.Status {
		return false
	}

	if min, ok := floatCondition(conditions, "min_amount"); ok && deal.Amount < min {
		return false
	}

	if max, ok := floatCondition(conditions, "max_amount"); ok && deal.Amount > max {
		return false
	}

	if companyID, ok := intCondition(conditions, "company_id"); ok {
		if deal.CompanyID == nil || companyID != *deal.CompanyID {
			return false
		}
	}

	return true
}

// asMap tolerates the shapes an empty PHP associative array can take.
func asMap(value any) map[string]any {
	if typed, ok := value.(map[string]any); ok {
		return typed
	}

	return nil
}

func intCondition(conditions map[string]any, key string) (int, bool) {
	if conditions == nil {
		return 0, false
	}

	switch typed := conditions[key].(type) {
	case float64:
		return int(typed), true
	case int:
		return typed, true
	default:
		return 0, false
	}
}

func floatCondition(conditions map[string]any, key string) (float64, bool) {
	if conditions == nil {
		return 0, false
	}

	switch typed := conditions[key].(type) {
	case float64:
		return typed, true
	case int:
		return float64(typed), true
	default:
		return 0, false
	}
}

func stringCondition(conditions map[string]any, key string) (string, bool) {
	if conditions == nil {
		return "", false
	}

	typed, ok := conditions[key].(string)

	return typed, ok
}
