package automation

import "testing"

func rule(id int, conditions map[string]any, actions ...Action) Rule {
	var conds any
	if conditions != nil {
		conds = conditions
	}

	return Rule{ID: id, Position: id, Conditions: conds, Actions: actions}
}

func userID(value int) *int {
	return &value
}

func TestRuleWithoutConditionsAlwaysMatches(t *testing.T) {
	matches := Evaluate(
		[]Rule{rule(1, nil, Action{Type: "set_priority", Priority: "high"})},
		Issue{ID: 5, Priority: "low"},
		Context{},
	)

	if len(matches) != 1 || matches[0].RuleID != 1 {
		t.Fatalf("expected rule 1 to match, got %+v", matches)
	}
}

func TestTargetStatusCondition(t *testing.T) {
	rules := []Rule{
		rule(1, map[string]any{"to_status_id": float64(3)}, Action{Type: "set_priority", Priority: "high"}),
		rule(2, nil, Action{Type: "set_priority", Priority: "low"}),
	}

	matches := Evaluate(rules, Issue{Priority: "medium"}, Context{From: 1, To: 3})

	if len(matches) != 2 {
		t.Fatalf("expected both rules, got %d", len(matches))
	}

	matches = Evaluate(rules, Issue{Priority: "medium"}, Context{From: 1, To: 4})

	if len(matches) != 1 || matches[0].RuleID != 2 {
		t.Fatalf("expected only rule 2, got %+v", matches)
	}
}

func TestPriorityCondition(t *testing.T) {
	rules := []Rule{
		rule(1, map[string]any{"priority": "high"}, Action{Type: "notify"}),
	}

	if got := Evaluate(rules, Issue{Priority: "high"}, Context{}); len(got) != 1 {
		t.Fatalf("expected a match for high priority")
	}

	if got := Evaluate(rules, Issue{Priority: "low"}, Context{}); len(got) != 0 {
		t.Fatalf("expected no match for low priority, got %d", len(got))
	}
}

func TestUnknownActionsAreDropped(t *testing.T) {
	rules := []Rule{
		rule(1, nil,
			Action{Type: "delete_issue"},
			Action{Type: "set_priority", Priority: "urgent"},
			Action{Type: "assign", UserID: userID(0)},
		),
	}

	if got := Evaluate(rules, Issue{}, Context{}); len(got) != 0 {
		t.Fatalf("expected the rule to be dropped, got %+v", got)
	}
}

func TestValidActionsSurviveFiltering(t *testing.T) {
	rules := []Rule{
		rule(1, nil,
			Action{Type: "delete_issue"},
			Action{Type: "assign", UserID: userID(7)},
			Action{Type: "set_priority", Priority: "highest"},
			Action{Type: "notify", Message: "hi"},
		),
	}

	got := Evaluate(rules, Issue{}, Context{})
	if len(got) != 1 {
		t.Fatalf("expected one match, got %d", len(got))
	}
	if len(got[0].Actions) != 3 {
		t.Fatalf("expected 3 actions after filtering, got %d", len(got[0].Actions))
	}
}

func TestOrderIsPreserved(t *testing.T) {
	rules := []Rule{
		{ID: 10, Position: 1, Actions: []Action{{Type: "notify"}}},
		{ID: 20, Position: 2, Actions: []Action{{Type: "notify"}}},
	}

	got := Evaluate(rules, Issue{}, Context{})
	if len(got) != 2 || got[0].RuleID != 10 || got[1].RuleID != 20 {
		t.Fatalf("unexpected order: %+v", got)
	}
}

// PHP sends an empty associative array as `[]`, which does not unmarshal into a
// map; such a rule must simply have no conditions.
func TestConditionsAsEmptySliceAreTolerated(t *testing.T) {
	rules := []Rule{
		{ID: 1, Conditions: []any{}, Actions: []Action{{Type: "notify"}}},
	}

	if got := Evaluate(rules, Issue{}, Context{To: 5}); len(got) != 1 {
		t.Fatalf("expected the rule to match, got %+v", got)
	}
}
