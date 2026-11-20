package timesheet

import "testing"

func TestGroupsByDateAndTotals(t *testing.T) {
	report := Build([]Entry{
		{ID: 1, Date: "2026-12-01", Seconds: 3600, IssueKey: "KB-1"},
		{ID: 2, Date: "2026-12-01", Seconds: 1800, IssueKey: "KB-2"},
		{ID: 3, Date: "2026-12-02", Seconds: 900, IssueKey: "KB-1"},
	})

	if len(report.Days) != 2 {
		t.Fatalf("expected 2 days, got %d", len(report.Days))
	}
	if report.Days[0].Date != "2026-12-01" || report.Days[0].Seconds != 5400 {
		t.Fatalf("unexpected first day: %+v", report.Days[0])
	}
	if report.Days[1].Seconds != 900 {
		t.Fatalf("unexpected second day: %+v", report.Days[1])
	}
	if report.TotalSeconds != 6300 {
		t.Fatalf("unexpected total: %d", report.TotalSeconds)
	}
	if report.TotalHours != 1.75 {
		t.Fatalf("unexpected total hours: %v", report.TotalHours)
	}
}

func TestDaysAreSortedAscending(t *testing.T) {
	report := Build([]Entry{
		{ID: 1, Date: "2026-12-03", Seconds: 60},
		{ID: 2, Date: "2026-12-01", Seconds: 60},
		{ID: 3, Date: "2026-12-02", Seconds: 60},
	})

	got := []string{report.Days[0].Date, report.Days[1].Date, report.Days[2].Date}
	want := []string{"2026-12-01", "2026-12-02", "2026-12-03"}

	for index := range want {
		if got[index] != want[index] {
			t.Fatalf("days not sorted: %v", got)
		}
	}
}

func TestFallsBackToStartedAt(t *testing.T) {
	report := Build([]Entry{
		{ID: 1, StartedAt: "2026-12-05T10:15:00+00:00", Seconds: 3600},
		{ID: 2, StartedAt: "2026-12-05 11:00:00", Seconds: 1800},
	})

	if len(report.Days) != 1 || report.Days[0].Date != "2026-12-05" {
		t.Fatalf("unexpected days: %+v", report.Days)
	}
	if report.Days[0].Seconds != 5400 {
		t.Fatalf("unexpected seconds: %d", report.Days[0].Seconds)
	}
}

func TestEntriesWithoutDateAreSkipped(t *testing.T) {
	report := Build([]Entry{
		{ID: 1, Date: "", StartedAt: "", Seconds: 3600},
		{ID: 2, Date: "not-a-date", Seconds: 3600},
		{ID: 3, Date: "2026-12-01", Seconds: 60},
	})

	if len(report.Days) != 1 {
		t.Fatalf("expected one day, got %d", len(report.Days))
	}
	if report.TotalSeconds != 60 {
		t.Fatalf("unexpected total: %d", report.TotalSeconds)
	}
}

func TestHoursAreRoundedToTwoDecimals(t *testing.T) {
	report := Build([]Entry{{ID: 1, Date: "2026-12-01", Seconds: 1000}})

	if report.Days[0].Entries[0].Hours != 0.28 {
		t.Fatalf("unexpected hours: %v", report.Days[0].Entries[0].Hours)
	}
}

func TestEmptyInputProducesEmptyReport(t *testing.T) {
	report := Build(nil)

	if report.TotalSeconds != 0 || len(report.Days) != 0 {
		t.Fatalf("unexpected report: %+v", report)
	}
}

func TestEntryFieldsArePreserved(t *testing.T) {
	report := Build([]Entry{{
		ID: 7, IssueID: 42, IssueKey: "KB-42", Summary: "Do it", ProjectKey: "KB",
		Seconds: 1800, Comment: "work", Running: true, Date: "2026-12-01",
	}})

	entry := report.Days[0].Entries[0]
	if entry.ID != 7 || entry.IssueID != 42 || entry.IssueKey != "KB-42" || entry.Summary != "Do it" ||
		entry.ProjectKey != "KB" || entry.Seconds != 1800 || entry.Comment != "work" || !entry.IsRunning {
		t.Fatalf("unexpected entry: %+v", entry)
	}
	if entry.Hours != 0.5 {
		t.Fatalf("unexpected hours: %v", entry.Hours)
	}
}
