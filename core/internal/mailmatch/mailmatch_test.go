package mailmatch

import "testing"

func message() Message {
	return Message{
		FromName:  "Boss",
		FromEmail: "boss@acme.io",
		To:        []Recipient{{Name: "Me", Email: "me@acme.io"}},
		Subject:   "Quarterly report",
		BodyText:  "Please review the numbers",
		Preview:   "preview",
	}
}

func rule(id int, conditions any) Rule {
	return Rule{ID: id, Conditions: conditions}
}

func conditions(root string, group string, list ...map[string]any) map[string]any {
	items := make([]any, 0, len(list))
	for _, condition := range list {
		items = append(items, condition)
	}

	return map[string]any{
		"match": root,
		"groups": []any{
			map[string]any{"match": group, "conditions": items},
		},
	}
}

func cond(field, op, value string) map[string]any {
	return map[string]any{"field": field, "op": op, "value": value}
}

func TestContainsMatchesBodyAndSubject(t *testing.T) {
	subjectRule := rule(1, conditions("all", "all", cond("subject", "contains", "quarterly")))
	if id := Resolve([]Rule{subjectRule}, message()); id == nil || *id != 1 {
		t.Fatalf("expected rule 1 to match the subject")
	}

	bodyRule := rule(2, conditions("all", "all", cond("body", "contains", "review")))
	if id := Resolve([]Rule{bodyRule}, message()); id == nil || *id != 2 {
		t.Fatalf("expected rule 2 to match the body")
	}
}

func TestContainsIsCaseInsensitive(t *testing.T) {
	matches := Matches(message(), conditions("all", "all", cond("subject", "contains", "QUARTERLY")))
	if !matches {
		t.Fatalf("expected a case-insensitive match")
	}
}

func TestFromAndToFields(t *testing.T) {
	if !Matches(message(), conditions("all", "all", cond("from", "contains", "boss@"))) {
		t.Fatalf("expected the from field to match")
	}
	if !Matches(message(), conditions("all", "all", cond("to", "contains", "me@acme.io"))) {
		t.Fatalf("expected the to field to match")
	}
	if Matches(message(), conditions("all", "all", cond("to", "contains", "nobody@"))) {
		t.Fatalf("unexpected match for an absent recipient")
	}
}

func TestEqualsUsesExactAddresses(t *testing.T) {
	if !Matches(message(), conditions("all", "all", cond("from", "equals", "boss@acme.io"))) {
		t.Fatalf("expected an equals match on the address")
	}
	if Matches(message(), conditions("all", "all", cond("from", "equals", "boss@"))) {
		t.Fatalf("equals must not be a prefix match")
	}
}

func TestHasAttachment(t *testing.T) {
	plain := message()
	if Matches(plain, conditions("all", "all", cond("has_attachment", "is_true", ""))) {
		t.Fatalf("expected no match without attachments")
	}

	withFile := message()
	withFile.HasAttachments = true
	if !Matches(withFile, conditions("all", "all", cond("has_attachment", "is_true", ""))) {
		t.Fatalf("expected a match with attachments")
	}

	if Matches(withFile, conditions("all", "all", cond("has_attachment", "equals", "false"))) {
		t.Fatalf("equals false must not match a message with attachments")
	}
}

func TestAllAndAnySemantics(t *testing.T) {
	both := conditions("all", "all",
		cond("subject", "contains", "quarterly"),
		cond("body", "contains", "missing"),
	)
	if Matches(message(), both) {
		t.Fatalf("all-groups must require every condition")
	}

	either := conditions("all", "any",
		cond("subject", "contains", "quarterly"),
		cond("body", "contains", "missing"),
	)
	if !Matches(message(), either) {
		t.Fatalf("any-group must match on the first hit")
	}
}

func TestRootAnyMatchesAcrossGroups(t *testing.T) {
	payload := map[string]any{
		"match": "any",
		"groups": []any{
			map[string]any{"match": "all", "conditions": []any{cond("subject", "contains", "nope")}},
			map[string]any{"match": "all", "conditions": []any{cond("subject", "contains", "quarterly")}},
		},
	}

	if !Matches(message(), payload) {
		t.Fatalf("expected the second group to satisfy the root any")
	}
}

func TestEmptyRulesAndGroups(t *testing.T) {
	if Matches(message(), nil) {
		t.Fatalf("nil conditions must not match")
	}
	if Matches(message(), map[string]any{"match": "all", "groups": []any{}}) {
		t.Fatalf("empty groups must not match")
	}
	if Matches(message(), map[string]any{"match": "all", "groups": []any{
		map[string]any{"match": "all", "conditions": []any{}},
	}}) {
		t.Fatalf("groups without conditions must be skipped")
	}
}

func TestResolveReturnsFirstMatchingRule(t *testing.T) {
	rules := []Rule{
		rule(5, conditions("all", "all", cond("subject", "contains", "nope"))),
		rule(6, conditions("all", "all", cond("subject", "contains", "quarterly"))),
		rule(7, conditions("all", "all", cond("subject", "contains", "quarterly"))),
	}

	id := Resolve(rules, message())
	if id == nil || *id != 6 {
		t.Fatalf("expected rule 6, got %v", id)
	}
}

func TestHtmlBodyIsStrippedForMatching(t *testing.T) {
	html := Message{BodyHTML: "<p>Hello <b>world</b></p>"}
	if !Matches(html, conditions("all", "all", cond("body", "contains", "hello world"))) {
		t.Fatalf("expected the html body to be stripped and matched")
	}
}
