BEGIN;
CREATE TABLE property_visits (
 id uuid PRIMARY KEY,
 property_id uuid NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
 token_hash char(64) NOT NULL,
 started_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 active_seconds integer NOT NULL DEFAULT 0 CHECK(active_seconds BETWEEN 0 AND 1800),
 source varchar(32) NOT NULL,
 country varchar(100),
 region varchar(120),
 city varchar(120)
);
CREATE INDEX property_visits_property_date ON property_visits(property_id,started_at DESC);
CREATE INDEX property_visits_expiry ON property_visits(started_at);
ALTER TABLE property_visits ENABLE ROW LEVEL SECURITY;
GRANT SELECT ON property_visits TO reygom_office_runtime;
CREATE POLICY property_visits_read ON property_visits FOR SELECT TO reygom_office_runtime
 USING(EXISTS(SELECT 1 FROM properties p WHERE p.id=property_id));
CREATE TABLE property_visit_limits (
 bucket char(64) PRIMARY KEY,
 hits integer NOT NULL,
 expires_at timestamptz NOT NULL
);
CREATE INDEX property_visit_limits_expiry ON property_visit_limits(expires_at);
COMMIT;
