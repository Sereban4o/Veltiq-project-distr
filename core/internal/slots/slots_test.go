package slots

import (
	"testing"
	"time"
)

func mustTime(t *testing.T, value string) time.Time {
	t.Helper()

	parsed, err := time.Parse(time.RFC3339, value)
	if err != nil {
		t.Fatalf("parse %q: %v", value, err)
	}

	return parsed
}

func TestFindReturnsWholeWindowWithoutMeetings(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T10:00:00Z")

	got := Find(start, end, nil, 60)

	if len(got) != 1 {
		t.Fatalf("expected 1 slot, got %d", len(got))
	}
	if !got[0].Start.Equal(start) || !got[0].End.Equal(end) {
		t.Fatalf("unexpected slot %v - %v", got[0].Start, got[0].End)
	}
}

func TestFindSkipsBusyMeetings(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T11:00:00Z")

	busyStart := mustTime(t, "2026-12-01T09:30:00Z")
	busyEnd := mustTime(t, "2026-12-01T10:00:00Z")

	got := Find(start, end, []Meeting{{Start: busyStart, End: &busyEnd}}, 30)

	// Candidates every 15 minutes for a 30-minute meeting: 09:00 and 10:00 are
	// free, 09:15/09:30/09:45 overlap the busy block, then 10:00, 10:15 and
	// 10:30 all fit before the window ends at 11:00.
	if len(got) != 4 {
		t.Fatalf("expected 4 slots, got %d", len(got))
	}
	if !got[0].Start.Equal(start) {
		t.Fatalf("first slot starts at %v", got[0].Start)
	}
	if !got[1].Start.Equal(mustTime(t, "2026-12-01T10:00:00Z")) {
		t.Fatalf("second slot starts at %v", got[1].Start)
	}
}

func TestAllDayMeetingBlocksTheWindow(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T11:00:00Z")

	got := Find(start, end, []Meeting{{AllDay: true}}, 30)

	if len(got) != 0 {
		t.Fatalf("expected no slots, got %d", len(got))
	}
}

func TestMeetingWithoutEndUsesDefaultLength(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T10:00:00Z")

	got := Find(start, end, []Meeting{{Start: start}}, 30)

	// The open-ended meeting blocks 09:00-09:30 by default.
	if len(got) != 1 {
		t.Fatalf("expected 1 slot, got %d", len(got))
	}
	if !got[0].Start.Equal(mustTime(t, "2026-12-01T09:30:00Z")) {
		t.Fatalf("slot starts at %v", got[0].Start)
	}
}

func TestOverlappingMeetingsAreMerged(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T10:00:00Z")

	first := mustTime(t, "2026-12-01T09:00:00Z")
	firstEnd := mustTime(t, "2026-12-01T09:30:00Z")
	second := mustTime(t, "2026-12-01T09:15:00Z")
	secondEnd := mustTime(t, "2026-12-01T09:45:00Z")

	got := Find(start, end, []Meeting{
		{Start: first, End: &firstEnd},
		{Start: second, End: &secondEnd},
	}, 15)

	if len(got) != 1 {
		t.Fatalf("expected 1 slot, got %d", len(got))
	}
	if !got[0].Start.Equal(mustTime(t, "2026-12-01T09:45:00Z")) {
		t.Fatalf("slot starts at %v", got[0].Start)
	}
}

func TestMeetingsOutsideTheWindowAreIgnored(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T10:00:00Z")

	before := mustTime(t, "2026-12-01T07:00:00Z")
	beforeEnd := mustTime(t, "2026-12-01T08:00:00Z")

	got := Find(start, end, []Meeting{{Start: before, End: &beforeEnd}}, 60)

	if len(got) != 1 {
		t.Fatalf("expected the window to stay free, got %d slots", len(got))
	}
}

func TestDurationIsClampedToStep(t *testing.T) {
	start := mustTime(t, "2026-12-01T09:00:00Z")
	end := mustTime(t, "2026-12-01T09:10:00Z")

	// A 1-minute request would fit 6 times; the step clamps it to 15 minutes,
	// which does not fit the 10-minute window.
	got := Find(start, end, nil, 1)

	if len(got) != 0 {
		t.Fatalf("expected no slots, got %d", len(got))
	}
}
