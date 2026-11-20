// Package timesheet aggregates worklog entries into a per-day report.
//
// Grouping, totals and rounding are product logic, so they live in the compiled
// service; the caller keeps database access and permission checks.
package timesheet

import (
	"sort"
	"time"
)

// Entry is one worklog as sent by the backend.
type Entry struct {
	ID        int    `json:"id"`
	IssueID   int    `json:"issue_id"`
	IssueKey  string `json:"issue_key"`
	Summary   string `json:"summary"`
	ProjectKey string `json:"project_key"`
	Seconds   int    `json:"seconds"`
	Comment   string `json:"comment"`
	Running   bool   `json:"is_running"`
	StartedAt string `json:"started_at"`
	Date      string `json:"date"`
}

// EntryReport is a worklog inside a day, with the derived hours.
type EntryReport struct {
	ID         int    `json:"id"`
	IssueID    int    `json:"issue_id"`
	IssueKey   string `json:"issue_key"`
	Summary    string `json:"summary"`
	ProjectKey string `json:"project_key"`
	Seconds    int    `json:"seconds"`
	Hours      float64 `json:"hours"`
	Comment    string `json:"comment"`
	IsRunning  bool   `json:"is_running"`
}

// Day is one calendar day of the report.
type Day struct {
	Date    string        `json:"date"`
	Seconds int           `json:"seconds"`
	Entries []EntryReport `json:"entries"`
}

// Report is the aggregated timesheet.
type Report struct {
	TotalSeconds int     `json:"total_seconds"`
	TotalHours   float64 `json:"total_hours"`
	Days         []Day   `json:"days"`
}

// Build aggregates entries by their reporting date (explicit `date`, otherwise
// the date part of `started_at`). Entries without a usable date are skipped, and
// days are returned in ascending order.
func Build(entries []Entry) Report {
	byDate := map[string]*Day{}
	total := 0

	for _, entry := range entries {
		date := dateOf(entry.Date)
		if date == "" {
			date = dateOf(entry.StartedAt)
		}
		if date == "" {
			continue
		}

		day, ok := byDate[date]
		if !ok {
			day = &Day{Date: date, Entries: []EntryReport{}}
			byDate[date] = day
		}

		day.Seconds += entry.Seconds
		total += entry.Seconds

		day.Entries = append(day.Entries, EntryReport{
			ID:         entry.ID,
			IssueID:    entry.IssueID,
			IssueKey:   entry.IssueKey,
			Summary:    entry.Summary,
			ProjectKey: entry.ProjectKey,
			Seconds:    entry.Seconds,
			Hours:      hours(entry.Seconds),
			Comment:    entry.Comment,
			IsRunning:  entry.Running,
		})
	}

	dates := make([]string, 0, len(byDate))
	for date := range byDate {
		dates = append(dates, date)
	}
	sort.Strings(dates)

	days := make([]Day, 0, len(dates))
	for _, date := range dates {
		days = append(days, *byDate[date])
	}

	return Report{
		TotalSeconds: total,
		TotalHours:   hours(total),
		Days:         days,
	}
}

// hours rounds to two decimals like the PHP implementation.
func hours(seconds int) float64 {
	return round2(float64(seconds) / 3600)
}

func round2(value float64) float64 {
	return float64(int64(value*100+0.5)) / 100
}

// dateOf extracts the leading YYYY-MM-DD from a timestamp, tolerating both
// ISO-8601 and date-time strings with a space separator.
func dateOf(value string) string {
	if len(value) < 10 {
		return ""
	}

	candidate := value[:10]
	if _, err := time.Parse("2006-01-02", candidate); err != nil {
		return ""
	}

	return candidate
}
