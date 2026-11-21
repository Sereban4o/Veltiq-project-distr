// Package sprint builds burndown series and velocity averages for sprints.
//
// The arithmetic (ideal line, remaining points, rounding) is product logic, so
// it lives in the compiled service; the caller keeps database access and
// permission checks and falls back to its own implementation when the service
// is unavailable.
package sprint

import "time"

// Snapshot is one day's captured sprint scope.
type Snapshot struct {
	Date        string  `json:"date"`
	PointsTotal float64 `json:"points_total"`
	PointsDone  float64 `json:"points_done"`
	IssuesTotal *int    `json:"issues_total"`
	IssuesDone  *int    `json:"issues_done"`
}

// Point is one day of the burndown series.
type Point struct {
	Date        string   `json:"date"`
	Ideal       float64  `json:"ideal"`
	Remaining   *float64 `json:"remaining"`
	IssuesTotal *int     `json:"issues_total"`
	IssuesDone  *int     `json:"issues_done"`
}

// Build returns the burndown series from startDate (inclusive) to endDate
// (inclusive), mirroring the PHP implementation: an ideal line from the starting
// scope down to zero, actual remaining points on days that have a snapshot, and
// a hard cap of 400 points.
func Build(startDate, endDate string, totalPoints float64, snapshots []Snapshot) []Point {
	start, err := time.Parse("2006-01-02", startDate)
	if err != nil {
		return []Point{}
	}

	end, err := time.Parse("2006-01-02", endDate)
	if err != nil {
		return []Point{}
	}

	if end.Before(start) {
		end = start
	}

	byDate := make(map[string]Snapshot, len(snapshots))
	for _, snapshot := range snapshots {
		byDate[snapshot.Date] = snapshot
	}

	dayCount := int(end.Sub(start).Hours() / 24)
	if dayCount < 1 {
		dayCount = 1
	}

	series := []Point{}
	cursor := start
	index := 0

	for !cursor.After(end) && index <= 400 {
		date := cursor.Format("2006-01-02")
		ideal := totalPoints - (totalPoints/float64(dayCount))*float64(index)
		if ideal < 0 {
			ideal = 0
		}

		point := Point{
			Date:  date,
			Ideal: round2(ideal),
		}

		if snapshot, ok := byDate[date]; ok {
			remaining := round2(snapshot.PointsTotal - snapshot.PointsDone)
			if remaining < 0 {
				remaining = 0
			}
			point.Remaining = &remaining
			point.IssuesTotal = snapshot.IssuesTotal
			point.IssuesDone = snapshot.IssuesDone
		}

		series = append(series, point)

		cursor = cursor.AddDate(0, 0, 1)
		index++
	}

	return series
}

// VelocityAverage returns the mean of the completed points, rounded to two
// decimals, or 0 when there is nothing to average.
func VelocityAverage(points []float64) float64 {
	if len(points) == 0 {
		return 0
	}

	sum := 0.0
	for _, point := range points {
		sum += point
	}

	return round2(sum / float64(len(points)))
}

func round2(value float64) float64 {
	return float64(int64(value*100+0.5)) / 100
}
