// Package mailmatch evaluates user mail filter rules against a message.
//
// Only matching lives here; loading rules, resolving folders and moving messages
// stays in PHP. Conditions using `regex` are handled by the PHP fallback because
// Go's RE2 differs from PCRE.
package mailmatch

import (
	"regexp"
	"strings"
)

// Message is the snapshot the conditions are evaluated against.
type Message struct {
	FromName       string      `json:"from_name"`
	FromEmail      string      `json:"from_email"`
	To             []Recipient `json:"to"`
	Subject        string      `json:"subject"`
	BodyText       string      `json:"body_text"`
	BodyHTML       string      `json:"body_html"`
	Preview        string      `json:"preview"`
	HasAttachments bool        `json:"has_attachments"`
}

// Recipient is one addressee of the message.
type Recipient struct {
	Name  string `json:"name"`
	Email string `json:"email"`
}

// Rule is one filter rule; only the id and conditions matter here.
type Rule struct {
	ID         int    `json:"id"`
	Conditions any    `json:"conditions"`
}

// Resolve returns the id of the first rule that matches, or nil.
func Resolve(rules []Rule, message Message) *int {
	for _, rule := range rules {
		if Matches(message, rule.Conditions) {
			id := rule.ID

			return &id
		}
	}

	return nil
}

// Matches evaluates the root/group/condition structure of a rule.
func Matches(message Message, conditions any) bool {
	root := asMap(conditions)
	if root == nil {
		return false
	}

	groups := normalizeGroups(root["groups"])
	if len(groups) == 0 {
		return false
	}

	rootAny := asString(root["match"]) == "any"

	for _, group := range groups {
		groupMatched := evaluateGroup(message, group)
		if rootAny && groupMatched {
			return true
		}
		if !rootAny && !groupMatched {
			return false
		}
	}

	return !rootAny
}

type group struct {
	any        bool
	conditions []map[string]any
}

func normalizeGroups(value any) []group {
	list, ok := value.([]any)
	if !ok {
		return nil
	}

	result := make([]group, 0, len(list))
	for _, item := range list {
		entry := asMap(item)
		if entry == nil {
			continue
		}

		raw, ok := entry["conditions"].([]any)
		if !ok {
			continue
		}

		conditions := make([]map[string]any, 0, len(raw))
		for _, condition := range raw {
			if parsed := asMap(condition); parsed != nil {
				conditions = append(conditions, parsed)
			}
		}

		if len(conditions) == 0 {
			continue
		}

		result = append(result, group{
			any:        asString(entry["match"]) == "any",
			conditions: conditions,
		})
	}

	return result
}

func evaluateGroup(message Message, current group) bool {
	for _, condition := range current.conditions {
		matched := evaluateCondition(message, condition)
		if current.any && matched {
			return true
		}
		if !current.any && !matched {
			return false
		}
	}

	return !current.any
}

func evaluateCondition(message Message, condition map[string]any) bool {
	field := asString(condition["field"])
	op := asString(condition["op"])
	value := asString(condition["value"])

	if field == "has_attachment" {
		has := message.HasAttachments
		if op == "equals" {
			return has == parseBool(value)
		}

		return has
	}

	if value == "" {
		return false
	}

	haystack := haystackFor(message, field)

	switch op {
	case "regex":
		// PCRE-regex is evaluated by the PHP fallback; RE2 here would silently
		// change semantics, so the caller never sends such conditions.
		return safeRegex(value, haystack)
	case "equals":
		return equalsField(message, field, value)
	default:
		return containsCI(haystack, value)
	}
}

func haystackFor(message Message, field string) string {
	switch field {
	case "from":
		return strings.TrimSpace(message.FromName + " <" + message.FromEmail + "> " + message.FromEmail)
	case "to":
		parts := make([]string, 0, len(message.To))
		for _, recipient := range message.To {
			name := strings.TrimSpace(recipient.Name)
			if name != "" {
				parts = append(parts, name+" <"+recipient.Email+">")

				continue
			}
			parts = append(parts, recipient.Email)
		}

		return strings.Join(parts, ", ")
	case "subject":
		return message.Subject
	case "body":
		if message.BodyText != "" {
			return message.BodyText
		}
		if message.BodyHTML != "" {
			return stripTags(message.BodyHTML)
		}

		return message.Preview
	default:
		return ""
	}
}

func equalsField(message Message, field, value string) bool {
	needle := lower(strings.TrimSpace(value))

	var candidates []string
	switch field {
	case "from":
		candidates = []string{
			message.FromEmail,
			strings.TrimSpace(message.FromName + " <" + message.FromEmail + ">"),
		}
	case "to":
		for _, recipient := range message.To {
			candidates = append(candidates, recipient.Email)
			candidates = append(candidates, strings.TrimSpace(recipient.Name+" <"+recipient.Email+">"))
		}
	default:
		candidates = []string{haystackFor(message, field)}
	}

	for _, candidate := range candidates {
		trimmed := strings.TrimSpace(candidate)
		if trimmed != "" && lower(trimmed) == needle {
			return true
		}
	}

	return false
}

func containsCI(haystack, needle string) bool {
	if needle == "" {
		return false
	}

	return strings.Contains(lower(haystack), lower(needle))
}

func safeRegex(pattern, haystack string) bool {
	compiled, err := regexp.Compile(pattern)
	if err != nil {
		return false
	}

	return compiled.MatchString(haystack)
}

// stripTags approximates PHP's strip_tags for the mail body preview.
func stripTags(value string) string {
	re := regexp.MustCompile(`(?s)<[^>]*>`)

	return re.ReplaceAllString(value, "")
}

func parseBool(value string) bool {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

func lower(value string) string {
	return strings.ToLower(value)
}

func asMap(value any) map[string]any {
	if typed, ok := value.(map[string]any); ok {
		return typed
	}

	return nil
}

func asString(value any) string {
	if typed, ok := value.(string); ok {
		return typed
	}

	return ""
}
