// Package csvmap resolves CSV column mappings for CRM imports.
//
// Matching a supplied mapping (column index or header name) and auto-mapping by
// Russian labels is product logic, so it lives in the compiled service; the
// caller keeps file reading, delimiter detection and row extraction.
package csvmap

import (
	"strconv"
	"strings"
)

// Request is the mapping input. Mapping values are column indexes or header
// names, as sent by the admin form.
type Request struct {
	Header  []string          `json:"header"`
	Labels  map[string]string `json:"labels"`
	Mapping map[string]any    `json:"mapping"`
}

// Resolve returns the field → column index map. Fields that cannot be resolved
// are omitted, mirroring the PHP implementation.
func Resolve(request Request) map[string]int {
	index := map[string]int{}

	for field, label := range request.Labels {
		if candidate, ok := request.Mapping[field]; ok && candidate != nil && candidate != "" {
			if numeric, isNumber := toNumber(candidate); isNumber {
				index[field] = numeric

				continue
			}

			if position := positionOf(request.Header, asString(candidate)); position >= 0 {
				index[field] = position
			}

			continue
		}

		if position := positionOf(request.Header, label); position >= 0 {
			index[field] = position
		}
	}

	return index
}

// toNumber mirrors PHP's is_numeric() + (int) cast for the values we accept.
func toNumber(value any) (int, bool) {
	switch typed := value.(type) {
	case float64:
		return int(typed), true
	case string:
		trimmed := strings.TrimSpace(typed)
		if trimmed == "" {
			return 0, false
		}

		parsed, err := strconv.ParseFloat(trimmed, 64)
		if err != nil {
			return 0, false
		}

		return int(parsed), true
	default:
		return 0, false
	}
}

func asString(value any) string {
	if text, ok := value.(string); ok {
		return text
	}

	return ""
}

func positionOf(header []string, target string) int {
	for position, value := range header {
		if value == target {
			return position
		}
	}

	return -1
}
