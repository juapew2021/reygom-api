CREATE TABLE IF NOT EXISTS property_submissions (
    id uuid PRIMARY KEY,
    payload jsonb NOT NULL,
    email varchar(254) NOT NULL,
    verification_hash char(64) UNIQUE,
    verification_expires_at timestamptz NOT NULL,
    verified_at timestamptz,
    status varchar(24) NOT NULL DEFAULT 'awaiting_email'
        CHECK (status IN ('awaiting_email','pending_review','approved','rejected')),
    images jsonb NOT NULL DEFAULT '[]',
    property_id uuid REFERENCES properties(id),
    reviewed_by uuid REFERENCES users(id),
    review_note text,
    reviewed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (status NOT IN ('pending_review','approved') OR verified_at IS NOT NULL),
    CHECK (status <> 'approved' OR property_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS property_submissions_email_created ON property_submissions(email, created_at);
CREATE INDEX IF NOT EXISTS property_submissions_status_created ON property_submissions(status, created_at);
