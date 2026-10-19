BEGIN;
CREATE TABLE IF NOT EXISTS office_branding (
 organization_id uuid PRIMARY KEY REFERENCES organizations(id),
 display_name varchar(150) NOT NULL DEFAULT '',
 primary_color char(7) NOT NULL DEFAULT '#e02424' CHECK(primary_color ~ '^#[0-9a-fA-F]{6}$'),
 sidebar_color char(7) NOT NULL DEFAULT '#000000' CHECK(sidebar_color ~ '^#[0-9a-fA-F]{6}$'),
 logo text CHECK(logo IS NULL OR (length(logo)<=196608 AND logo LIKE 'data:image/webp;base64,%')),
 version integer NOT NULL DEFAULT 1,updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS user_profiles (
 user_id uuid PRIMARY KEY REFERENCES users(id),organization_id uuid NOT NULL REFERENCES organizations(id),
 photo text CHECK(photo IS NULL OR (length(photo)<=196608 AND photo LIKE 'data:image/webp;base64,%')),
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE office_branding ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_profiles ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS branding_read ON office_branding;
DROP POLICY IF EXISTS branding_write ON office_branding;
DROP POLICY IF EXISTS profile_read ON user_profiles;
DROP POLICY IF EXISTS profile_write ON user_profiles;
CREATE POLICY branding_read ON office_branding FOR SELECT TO reygom_office_runtime USING(organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid);
CREATE POLICY branding_write ON office_branding FOR ALL TO reygom_office_runtime USING(organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid AND EXISTS(SELECT 1 FROM users WHERE id=nullif(current_setting('reygom.user_id',true),'')::uuid AND role IN ('admin','superadmin') AND is_active)) WITH CHECK(organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid AND EXISTS(SELECT 1 FROM users WHERE id=nullif(current_setting('reygom.user_id',true),'')::uuid AND role IN ('admin','superadmin') AND is_active));
CREATE POLICY profile_read ON user_profiles FOR SELECT TO reygom_office_runtime USING(user_id=nullif(current_setting('reygom.user_id',true),'')::uuid);
CREATE POLICY profile_write ON user_profiles FOR ALL TO reygom_office_runtime USING(user_id=nullif(current_setting('reygom.user_id',true),'')::uuid) WITH CHECK(user_id=nullif(current_setting('reygom.user_id',true),'')::uuid AND EXISTS(SELECT 1 FROM users WHERE id=user_id AND organization_id=user_profiles.organization_id AND is_active));
GRANT SELECT,INSERT,UPDATE ON office_branding,user_profiles TO reygom_office_runtime;
CREATE OR REPLACE FUNCTION reygom_branding_history() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE actor uuid:=nullif(current_setting('reygom.user_id',true),'')::uuid; office uuid:=NEW.organization_id; kind text;
BEGIN
 kind:=CASE TG_TABLE_NAME WHEN 'office_branding' THEN 'office.branding_updated' ELSE 'user.photo_updated' END;
 INSERT INTO public.office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,metadata,source)
 SELECT actor,COALESCE((SELECT username FROM public.users WHERE id=actor),'Actor no identificado'),office,(SELECT name FROM public.organizations WHERE id=office),kind,
 CASE WHEN TG_TABLE_NAME='user_profiles' THEN actor ELSE office END,CASE WHEN TG_TABLE_NAME='user_profiles' THEN 'user' ELSE 'office' END,'{}','database';
 RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS branding_history ON office_branding;
DROP TRIGGER IF EXISTS profile_history ON user_profiles;
CREATE TRIGGER branding_history AFTER INSERT OR UPDATE ON office_branding FOR EACH ROW EXECUTE FUNCTION reygom_branding_history();
CREATE TRIGGER profile_history AFTER INSERT OR UPDATE ON user_profiles FOR EACH ROW EXECUTE FUNCTION reygom_branding_history();
COMMIT;
