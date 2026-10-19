BEGIN;
CREATE TABLE IF NOT EXISTS agenda_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 organization_id uuid NOT NULL REFERENCES organizations(id),
 owner_id uuid NOT NULL REFERENCES users(id),
 property_id uuid REFERENCES properties(id) ON DELETE SET NULL,
 title varchar(160) NOT NULL CHECK(length(trim(title))>0),
 kind varchar(12) NOT NULL CHECK(kind IN('visit','meeting','task')),
 starts_at timestamptz NOT NULL,
 ends_at timestamptz NOT NULL,
 timezone varchar(40) NOT NULL CHECK(timezone IN('Europe/Madrid','Atlantic/Canary','America/Bogota')),
 location varchar(300) NOT NULL DEFAULT '',
 notes varchar(2000) NOT NULL DEFAULT '',
 status varchar(12) NOT NULL DEFAULT 'scheduled' CHECK(status IN('scheduled','completed','cancelled')),
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(ends_at>starts_at AND ends_at<=starts_at+interval '24 hours')
);
CREATE INDEX IF NOT EXISTS agenda_events_owner_date ON agenda_events(organization_id,owner_id,starts_at);
ALTER TABLE agenda_events ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS agenda_read ON agenda_events;
DROP POLICY IF EXISTS agenda_insert ON agenda_events;
DROP POLICY IF EXISTS agenda_update ON agenda_events;
CREATE POLICY agenda_read ON agenda_events FOR SELECT TO reygom_office_runtime USING(
 organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid AND
 (owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid OR EXISTS(SELECT 1 FROM users u WHERE u.id=nullif(current_setting('reygom.user_id',true),'')::uuid AND u.role IN('admin','superadmin') AND u.is_active))
);
CREATE POLICY agenda_insert ON agenda_events FOR INSERT TO reygom_office_runtime WITH CHECK(
 organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid AND owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid AND
 (property_id IS NULL OR EXISTS(SELECT 1 FROM properties p WHERE p.id=property_id))
);
CREATE POLICY agenda_update ON agenda_events FOR UPDATE TO reygom_office_runtime USING(
 organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid AND owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid
) WITH CHECK(
 organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid AND owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid AND
 (property_id IS NULL OR EXISTS(SELECT 1 FROM properties p WHERE p.id=property_id))
);
GRANT SELECT,INSERT,UPDATE ON agenda_events TO reygom_office_runtime;
COMMIT;
