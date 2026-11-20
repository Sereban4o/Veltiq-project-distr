package recurrence

import (
	"errors"
	"testing"
	"time"
)

func at(value string) time.Time {
	parsed, err := time.Parse(time.RFC3339, value)
	if err != nil {
		panic(err)
	}

	return parsed
}

func request(rrule string, from, to string) Request {
	return Request{
		Start:    at("2026-12-01T09:00:00Z"),
		Duration: 30 * time.Minute,
		Rrule:    rrule,
		From:     at(from),
		To:       at(to),
		Limit:    100,
		Timezone: "UTC",
	}
}

func starts(result Result) []string {
	list := make([]string, 0, len(result.Items))
	for _, item := range result.Items {
		list = append(list, item.Start.Format(time.RFC3339))
	}

	return list
}

func TestDailyInterval(t *testing.T) {
	result, err := Expand(request("FREQ=DAILY;INTERVAL=2", "2026-12-01T00:00:00Z", "2026-12-07T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	got := starts(result)
	want := []string{"2026-12-01T09:00:00Z", "2026-12-03T09:00:00Z", "2026-12-05T09:00:00Z", "2026-12-07T09:00:00Z"}

	if len(got) != len(want) {
		t.Fatalf("unexpected occurrences: %v", got)
	}
	for index := range want {
		if got[index] != want[index] {
			t.Fatalf("unexpected occurrences: %v", got)
		}
	}
}

func TestDailyCount(t *testing.T) {
	result, err := Expand(request("FREQ=DAILY;COUNT=3", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(result.Items) != 3 {
		t.Fatalf("expected 3 occurrences, got %v", starts(result))
	}
}

func TestDailyUntil(t *testing.T) {
	result, err := Expand(request("FREQ=DAILY;UNTIL=20261203T090000Z", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(result.Items) != 3 {
		t.Fatalf("expected 3 occurrences, got %v", starts(result))
	}
}

func TestWeeklyByDay(t *testing.T) {
	result, err := Expand(Request{
		Start:    at("2026-12-01T09:00:00Z"), // Tuesday
		Duration: time.Hour,
		Rrule:    "FREQ=WEEKLY;BYDAY=MO,WE",
		From:     at("2026-12-01T00:00:00Z"),
		To:       at("2026-12-17T23:59:59Z"),
		Limit:    50,
		Timezone: "UTC",
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	got := starts(result)
	// DTSTART (Tuesday 12-01) is part of the series plus the MO/WE occurrences.
	want := []string{
		"2026-12-01T09:00:00Z", "2026-12-02T09:00:00Z", "2026-12-07T09:00:00Z",
		"2026-12-09T09:00:00Z", "2026-12-14T09:00:00Z", "2026-12-16T09:00:00Z",
	}

	if len(got) != len(want) {
		t.Fatalf("unexpected occurrences: %v", got)
	}
	for index := range want {
		if got[index] != want[index] {
			t.Fatalf("unexpected occurrences: %v", got)
		}
	}
}

func TestMonthlyByMonthDay(t *testing.T) {
	result, err := Expand(request("FREQ=MONTHLY;BYMONTHDAY=15", "2026-12-01T00:00:00Z", "2027-03-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	got := starts(result)
	want := []string{
		"2026-12-01T09:00:00Z",
		"2026-12-15T09:00:00Z", "2027-01-15T09:00:00Z", "2027-02-15T09:00:00Z", "2027-03-15T09:00:00Z",
	}

	if len(got) != len(want) {
		t.Fatalf("unexpected occurrences: %v", got)
	}
}

func TestMonthlyNegativeMonthDay(t *testing.T) {
	result, err := Expand(request("FREQ=MONTHLY;BYMONTHDAY=-1", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(result.Items) != 2 || result.Items[1].Start.Format("2006-01-02") != "2026-12-31" {
		t.Fatalf("unexpected occurrences: %v", starts(result))
	}
}

func TestMonthlyNthWeekday(t *testing.T) {
	result, err := Expand(request("FREQ=MONTHLY;BYDAY=2TU", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(result.Items) != 2 || result.Items[1].Start.Format("2006-01-02") != "2026-12-08" {
		t.Fatalf("unexpected occurrences: %v", starts(result))
	}

	last, err := Expand(request("FREQ=MONTHLY;BYDAY=-1FR", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(last.Items) != 2 || last.Items[1].Start.Format("2006-01-02") != "2026-12-25" {
		t.Fatalf("unexpected occurrences: %v", starts(last))
	}
}

func TestYearlyByMonth(t *testing.T) {
	result, err := Expand(request("FREQ=YEARLY;BYMONTH=3", "2026-12-01T00:00:00Z", "2028-12-31T23:59:59Z"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	got := starts(result)
	want := []string{"2026-12-01T09:00:00Z", "2027-03-01T09:00:00Z", "2028-03-01T09:00:00Z"}

	if len(got) != len(want) {
		t.Fatalf("unexpected occurrences: %v", got)
	}
}

func TestExdateIsExcluded(t *testing.T) {
	request := request("FREQ=DAILY;COUNT=3", "2026-12-01T00:00:00Z", "2026-12-10T23:59:59Z")
	request.Exdates = []time.Time{at("2026-12-02T09:00:00Z")}

	result, err := Expand(request)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	got := starts(result)
	if len(got) != 2 || got[0] != "2026-12-01T09:00:00Z" || got[1] != "2026-12-03T09:00:00Z" {
		t.Fatalf("unexpected occurrences: %v", got)
	}
}

func TestWindowFilteringAndDuration(t *testing.T) {
	request := request("FREQ=DAILY", "2026-12-05T00:00:00Z", "2026-12-06T23:59:59Z")
	request.Duration = 90 * time.Minute

	result, err := Expand(request)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(result.Items) != 2 {
		t.Fatalf("expected 2 occurrences, got %v", starts(result))
	}
	if result.Items[0].End.Sub(result.Items[0].Start) != 90*time.Minute {
		t.Fatalf("duration not applied: %v", result.Items[0])
	}
}

func TestLimitSetsTruncated(t *testing.T) {
	request := request("FREQ=DAILY", "2026-12-01T00:00:00Z", "2027-12-31T23:59:59Z")
	request.Limit = 10

	result, err := Expand(request)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(result.Items) != 10 || !result.Truncated {
		t.Fatalf("expected the limit to truncate: %d items, truncated=%v", len(result.Items), result.Truncated)
	}
}

func TestUnsupportedRulesAreReported(t *testing.T) {
	cases := []string{
		"FREQ=HOURLY",
		"FREQ=WEEKLY;BYSETPOS=1",
		"FREQ=YEARLY;BYYEARDAY=100",
		"FREQ=WEEKLY;BYWEEKNO=3",
		"FREQ=DAILY;BYHOUR=9",
		"",
	}

	for _, raw := range cases {
		_, err := Expand(request(raw, "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z"))

		var unsupported *UnsupportedError
		if !errors.As(err, &unsupported) {
			t.Fatalf("expected %q to be unsupported, got %v", raw, err)
		}
	}
}

func TestTimezoneIsHonoured(t *testing.T) {
	request := request("FREQ=DAILY;COUNT=1", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z")
	request.Start = at("2026-12-01T09:00:00Z")
	request.Timezone = "Europe/Moscow"

	result, err := Expand(request)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// 09:00Z is 12:00 in Moscow; the occurrence keeps the wall clock.
	if len(result.Items) != 1 || result.Items[0].Start != request.Start {
		t.Fatalf("unexpected occurrences: %v", starts(result))
	}
}

func TestRrulePrefixIsTolerated(t *testing.T) {
	if _, err := Expand(request("RRULE:FREQ=DAILY;COUNT=1", "2026-12-01T00:00:00Z", "2026-12-31T23:59:59Z")); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
}
