package store

import (
	"context"
	"database/sql"
	"encoding/json"
	"time"

	_ "github.com/jackc/pgx/v5/stdlib"
)

// PostgresStore keeps the snapshot in the core's OWN tables. It never touches
// application tables: the license, extension flags and the audit log are the only
// things the core is allowed to persist.
type PostgresStore struct {
	db *sql.DB
}

// NewPostgresStore opens the pool, verifies connectivity and creates the core's
// tables if they do not exist yet.
func NewPostgresStore(ctx context.Context, dsn string) (*PostgresStore, error) {
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return nil, err
	}

	db.SetMaxOpenConns(4)
	db.SetMaxIdleConns(2)
	db.SetConnMaxIdleTime(5 * time.Minute)

	connCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	if err := db.PingContext(connCtx); err != nil {
		_ = db.Close()

		return nil, err
	}

	pg := &PostgresStore{db: db}
	if err := pg.ensureSchema(connCtx); err != nil {
		_ = db.Close()

		return nil, err
	}

	return pg, nil
}

const schemaSQL = `
CREATE TABLE IF NOT EXISTS core_state (
    id          integer PRIMARY KEY,
    data        jsonb NOT NULL,
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS core_audit (
    id      bigserial PRIMARY KEY,
    at      timestamptz NOT NULL DEFAULT now(),
    action  text NOT NULL,
    detail  text NOT NULL DEFAULT '',
    actor   text NOT NULL DEFAULT ''
);
`

func (p *PostgresStore) ensureSchema(ctx context.Context) error {
	_, err := p.db.ExecContext(ctx, schemaSQL)

	return err
}

// Load returns the snapshot; no row yet yields an empty state.
func (p *PostgresStore) Load() (State, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	state := State{Extensions: map[string]Extension{}}

	var data []byte
	err := p.db.QueryRowContext(ctx, `SELECT data FROM core_state WHERE id = 1`).Scan(&data)
	if err == sql.ErrNoRows {
		return state, nil
	}

	if err != nil {
		return state, err
	}

	if err := json.Unmarshal(data, &state); err != nil {
		return State{Extensions: map[string]Extension{}}, err
	}

	if state.Extensions == nil {
		state.Extensions = map[string]Extension{}
	}

	return state, nil
}

// Save upserts the single snapshot row.
func (p *PostgresStore) Save(state State) error {
	if state.Extensions == nil {
		state.Extensions = map[string]Extension{}
	}

	data, err := json.Marshal(state)
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_, err = p.db.ExecContext(ctx,
		`INSERT INTO core_state (id, data) VALUES (1, $1)
		 ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = now()`,
		data,
	)

	return err
}

// AppendAudit inserts one audit row.
func (p *PostgresStore) AppendAudit(entry Audit) error {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_, err := p.db.ExecContext(ctx,
		`INSERT INTO core_audit (action, detail, actor) VALUES ($1, $2, $3)`,
		entry.Action, entry.Detail, entry.Actor,
	)

	return err
}

// Close releases the pool.
func (p *PostgresStore) Close() error { return p.db.Close() }
