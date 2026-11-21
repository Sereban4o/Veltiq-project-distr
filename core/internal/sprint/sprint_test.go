package sprint

import "testing"

func intPtr(value int) *int { return &value }

func TestBuildIdealLineWithoutSnapshots(t *testing.T) {
	series := Build("2026-01-01", "2026-01-03", 10, nil)

	if len(series) != 3 {
		t.Fatalf("expected 3 points, got %d", len(series))
	}

	want := []float64{10, 5, 0}
	for index, point := range series {
		if point.Ideal != want[index] {
			t.Fatalf("point %d: ideal %v, want %v", index, point.Ideal, want[index])
		}
		if point.Remaining != nil {
			t.Fatalf("point %d: expected no remaining points", index)
		}
	}
}

func TestBuildUsesSnapshotForRemaining(t *testing.T) {
	series := Build("2026-01-01", "2026-01-02", 20, []Snapshot{
		{
			Date:        "2026-01-02",
			PointsTotal: 20,
			PointsDone:  7.5,
			IssuesTotal: intPtr(10),
			IssuesDone:  intPtr(3),
		},
	})

	if len(series) != 2 {
		t.Fatalf("expected 2 points, got %d", len(series))
	}

	if series[1].Remaining == nil || *series[1].Remaining != 12.5 {
		t.Fatalf("remaining %v, want 12.5", series[1].Remaining)
	}
	if series[1].IssuesTotal == nil || *series[1].IssuesTotal != 10 {
		t.Fatalf("issues_total %v, want 10", series[1].IssuesTotal)
	}
	if series[1].IssuesDone == nil || *series[1].IssuesDone != 3 {
		t.Fatalf("issues_done %v, want 3", series[1].IssuesDone)
	}
}

func TestBuildClampsNegativeRemaining(t *testing.T) {
	series := Build("2026-01-01", "2026-01-01", 5, []Snapshot{
		{Date: "2026-01-01", PointsTotal: 5, PointsDone: 8},
	})

	if series[0].Remaining == nil || *series[0].Remaining != 0 {
		t.Fatalf("remaining %v, want 0", series[0].Remaining)
	}
}

func TestBuildSameDaySprint(t *testing.T) {
	series := Build("2026-02-10", "2026-02-10", 3, nil)

	if len(series) != 1 {
		t.Fatalf("expected 1 point, got %d", len(series))
	}
	if series[0].Ideal != 3 {
		t.Fatalf("ideal %v, want 3", series[0].Ideal)
	}
}

func TestBuildSwapsReversedRange(t *testing.T) {
	series := Build("2026-03-05", "2026-03-01", 4, nil)

	if len(series) != 1 || series[0].Date != "2026-03-05" {
		t.Fatalf("expected a single point at start date, got %+v", series)
	}
}

func TestBuildRejectsInvalidDates(t *testing.T) {
	if series := Build("nope", "2026-01-02", 1, nil); len(series) != 0 {
		t.Fatalf("expected empty series, got %d", len(series))
	}
}

func TestVelocityAverage(t *testing.T) {
	cases := []struct {
		name   string
		points []float64
		want   float64
	}{
		{"empty", nil, 0},
		{"single", []float64{8}, 8},
		{"rounded", []float64{8, 5, 4}, 5.67},
	}

	for _, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			if got := VelocityAverage(test.points); got != test.want {
				t.Fatalf("average %v, want %v", got, test.want)
			}
		})
	}
}
