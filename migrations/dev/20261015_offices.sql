BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true;
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_version integer NOT NULL DEFAULT 1;
ALTER TABLE property_submissions ADD COLUMN IF NOT EXISTS organization_id uuid REFERENCES organizations(id);
CREATE TABLE IF NOT EXISTS office_audit (
 id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), actor_id uuid NOT NULL,
 action text NOT NULL, organization_id uuid, subject_id uuid,
 created_at timestamptz NOT NULL DEFAULT now()
);
DO $$ BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='reygom_office_runtime') THEN
  CREATE ROLE reygom_office_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
 END IF;
END $$;
GRANT USAGE ON SCHEMA public TO reygom_office_runtime;
GRANT SELECT,INSERT,UPDATE,DELETE ON properties,leads,property_images,property_submissions TO reygom_office_runtime;
GRANT SELECT (id,username,role,organization_id,is_active) ON users TO reygom_office_runtime;
GRANT SELECT ON organizations TO reygom_office_runtime;
GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO reygom_office_runtime;

-- Existing DEV data belongs to ReyGom, explicitly confirmed by the owner.
DO $$ DECLARE office uuid; BEGIN
 SELECT organization_id INTO office FROM users WHERE username='admin';
 IF office IS NULL THEN RAISE EXCEPTION 'admin office missing'; END IF;
 UPDATE properties SET organization_id=office WHERE organization_id IS NULL;
 UPDATE leads SET organization_id=office WHERE organization_id IS NULL;
 UPDATE property_submissions SET organization_id=office WHERE organization_id IS NULL;
 EXECUTE format('ALTER TABLE property_submissions ALTER COLUMN organization_id SET DEFAULT %L::uuid',office);
 UPDATE users SET role='superadmin' WHERE username='admin';
END $$;

CREATE OR REPLACE FUNCTION reygom_set_office() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE office uuid := NULLIF(current_setting('reygom.organization_id',true),'')::uuid;
BEGIN
 IF current_user='reygom_office_runtime' THEN
  IF office IS NULL THEN RAISE EXCEPTION 'Office context required'; END IF;
  IF TG_OP='INSERT' THEN NEW.organization_id:=office; END IF;
  IF NEW.organization_id IS DISTINCT FROM office THEN RAISE EXCEPTION 'Office mismatch'; END IF;
  IF TG_TABLE_NAME='properties' THEN
   IF NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.agent_id AND organization_id=office AND is_active) THEN
    -- A platform administrator may create a property while working inside an office.
    IF NEW.agent_id::text IS DISTINCT FROM current_setting('reygom.user_id',true) OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.agent_id AND role='superadmin' AND is_active) THEN RAISE EXCEPTION 'Agent outside office'; END IF;
   END IF;
  ELSIF TG_TABLE_NAME='leads' THEN
   IF NEW.assigned_to IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.assigned_to AND organization_id=office AND is_active) THEN RAISE EXCEPTION 'Agent outside office'; END IF;
   IF NEW.property_interest_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM properties WHERE id=NEW.property_interest_id AND organization_id=office) THEN RAISE EXCEPTION 'Property outside office'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['properties','leads','property_submissions'] LOOP
  EXECUTE format('DROP TRIGGER IF EXISTS reygom_office_guard ON %I',t);
  EXECUTE format('CREATE TRIGGER reygom_office_guard BEFORE INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION reygom_set_office()',t);
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('DROP POLICY IF EXISTS office_isolation ON %I',t);
  EXECUTE format('CREATE POLICY office_isolation ON %I TO reygom_office_runtime USING (organization_id = NULLIF(current_setting(''reygom.organization_id'',true),'''')::uuid) WITH CHECK (organization_id = NULLIF(current_setting(''reygom.organization_id'',true),'''')::uuid)',t);
 END LOOP;
END $$;
ALTER TABLE property_images ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS office_isolation ON property_images;
CREATE POLICY office_isolation ON property_images TO reygom_office_runtime
 USING (EXISTS(SELECT 1 FROM properties WHERE id=property_id))
 WITH CHECK (EXISTS(SELECT 1 FROM properties WHERE id=property_id));
CREATE INDEX IF NOT EXISTS properties_office_idx ON properties(organization_id);
CREATE INDEX IF NOT EXISTS leads_office_idx ON leads(organization_id);
CREATE INDEX IF NOT EXISTS submissions_office_idx ON property_submissions(organization_id);
CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower_idx ON users(lower(username));
CREATE UNIQUE INDEX IF NOT EXISTS organizations_slug_lower_idx ON organizations(lower(slug));
COMMIT;
