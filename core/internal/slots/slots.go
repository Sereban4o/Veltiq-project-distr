// Package slots computes free meeting windows inside a bounded time window.
//
// This is the reference implementation of a "protected core": the algorithm
// lives in a compiled binary (shipped without sources), while the caller keeps
// database access and access-control decisions.
package slots

import (
	"sort"
	"time"
)

// Step is the candidate granularity for slot starts.
const Step = 15 * time.Minute

// DefaultEvent is used for an event without an explicit end.
const DefaultEvent = 30 * time.Minute

// Interval is a half-open [Start, End) range.
type Interval struct {
	Start time.Time
	End   time.Time
}

// Meeting is one busy block; a nil End means "unknown", and an all-day meeting
// blocks the whole window.
type Meeting struct {
	Start  time.Time
	End    *time.Time
	AllDay bool
}

// Slot is a suggested meeting window.
type Slot struct {
	Start time.Time
	End   time.Time
}

// Find returns non-overlapping slots of the requested duration, aligned to Step.
//
// Meetings are clipped to the window and merged, so overlapping entries cannot
// produce false negatives.
func Find(windowStart, windowEnd time.Time, meetings []Meeting, durationMinutes int) []Slot {
	busy := merge(clip(meetings, windowStart, windowEnd))

	duration := time.Duration(durationMinutes) * time.Minute
	if duration < Step {
		duration = Step
	}

	var result []Slot
	for cursor := windowStart; !cursor.Add(duration).After(windowEnd); cursor = cursor.Add(Step) {
		end := cursor.Add(duration)
		if !overlaps(busy, cursor, end) {
			result = append(result, Slot{Start: cursor, End: end})
		}
	}

	return result
}

func clip(meetings []Meeting, windowStart, windowEnd time.Time) []Interval {
	var intervals []Interval

	for _, meeting := range meetings {
		if meeting.AllDay || meeting.Start.IsZero() {
			// An all-day (or undated) entry blocks the whole window.
			intervals = append(intervals, Interval{Start: windowStart, End: windowEnd})

			continue
		}

		start := meeting.Start
		end := start.Add(DefaultEvent)
		if meeting.End != nil {
			end = *meeting.End
		}

		if start.Before(windowStart) {
			start = windowStart
		}
		if end.After(windowEnd) {
			end = windowEnd
		}

		if end.After(start) {
			intervals = append(intervals, Interval{Start: start, End: end})
		}
	}

	return intervals
}

func merge(intervals []Interval) []Interval {
	if len(intervals) == 0 {
		return nil
	}

	sort.Slice(intervals, func(i, j int) bool {
		return intervals[i].Start.Before(intervals[j].Start)
	})

	merged := []Interval{intervals[0]}
	for _, current := range intervals[1:] {
		last := &merged[len(merged)-1]

		if !current.Start.After(last.End) {
			if current.End.After(last.End) {
				last.End = current.End
			}

			continue
		}

		merged = append(merged, current)
	}

	return merged
}

func overlaps(busy []Interval, start, end time.Time) bool {
	for _, interval := range busy {
		if start.Before(interval.End) && end.After(interval.Start) {
			return true
		}
	}

	return false
}
