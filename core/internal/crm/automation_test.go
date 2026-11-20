package crm

import "testing"

func money(value float64) *float64 { return &value }

func rule(id int, conditions map[string]any, action string) Rule {
	var conds any
	if conditions != nil {
		conds = conditions
	}

	return Rule{ID: id, Position: id, Conditions: conds, Action: action}
}

func TestMatchWithoutConditions(t *testing.T) {
	matches := Evaluate([]Rule{rule(1, nil, "create_task")}, Deal{Amount: 100}, Context{})

	if len(matches) != 1 || matches[0].RuleID != 1 || matches[0].Action != "create_task" {
		t.Fatalf("unexpected matches: %+v", matches)
	}
}

func TestStageConditionUsesContextTargetFirst(t *testing.T) {
	rules := []Rule{rule(1, map[string]any{"stage_id": float64(3)}, "create_task")}

	if got := Evaluate(rules, Deal{StageID: 1}, Context{}); len(got) != 0 {
		t.Fatalf("expected no match for deal stage 1, got %+v", got)
	}

	target := 3
	if got := Evaluate(rules, Deal{StageID: 1}, Context{ToStageID: &target}); len(got) != 1 {
		t.Fatalf("expected a match for the target stage, got %+v", got)
	}
}

func TestStatusCondition(t *testing.T) {
	rules := []Rule{rule(1, map[string]any{"status": "won"}, "send_notification")}

	if got := Evaluate(rules, Deal{Status: "open"}, Context{}); len(got) != 0 {
		t.Fatalf("expected no match, got %+v", got)
	}
	if got := Evaluate(rules, Deal{Status: "won"}, Context{}); len(got) != 1 {
		t.Fatalf("expected a match, got %+v", got)
	}
}

func TestAmountRange(t *testing.T) {
	rules := []Rule{rule(1, map[string]any{"min_amount": float64(100)}, "create_task")}
	if got := Evaluate(rules, Deal{Amount: 99.99}, Context{}); len(got) != 0 {
		t.Fatalf("expected no match below the minimum")
	}
	if got := Evaluate(rules, Deal{Amount: 100}, Context{}); len(got) != 1 {
		t.Fatalf("expected a match at the minimum")
	}

	rules = []Rule{rule(1, map[string]any{"max_amount": float64(50)}, "create_task")}
	if got := Evaluate(rules, Deal{Amount: 51}, Context{}); len(got) != 0 {
		t.Fatalf("expected no match above the maximum")
	}
}

func TestCompanyCondition(t *testing.T) {
	rules := []Rule{rule(1, map[string]any{"company_id": float64(7)}, "create_task")}

	if got := Evaluate(rules, Deal{CompanyID: nil}, Context{}); len(got) != 0 {
		t.Fatalf("expected no match without a company")
	}

	company := 7
	if got := Evaluate(rules, Deal{CompanyID: &company}, Context{}); len(got) != 1 {
		t.Fatalf("expected a match for company 7")
	}
}

func TestUnknownActionsAreDropped(t *testing.T) {
	matches := Evaluate([]Rule{rule(1, nil, "delete_deal")}, Deal{}, Context{})

	if len(matches) != 0 {
		t.Fatalf("expected the rule to be dropped, got %+v", matches)
	}
}

func TestOrderIsPreserved(t *testing.T) {
	matches := Evaluate([]Rule{
		{ID: 10, Position: 1, Action: "create_task"},
		{ID: 20, Position: 2, Action: "send_notification"},
	}, Deal{}, Context{})

	if len(matches) != 2 || matches[0].RuleID != 10 || matches[1].RuleID != 20 {
		t.Fatalf("unexpected order: %+v", matches)
	}
}

func TestEmptyConditionsSliceIsTolerated(t *testing.T) {
	matches := Evaluate([]Rule{{ID: 1, Conditions: []any{}, Action: "create_task"}}, Deal{}, Context{})

	if len(matches) != 1 {
		t.Fatalf("expected a match, got %+v", matches)
	}
}
