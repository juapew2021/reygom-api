BEGIN;
ALTER TABLE office_audit ALTER COLUMN actor_id DROP NOT NULL;
ALTER TABLE office_audit ADD COLUMN IF NOT EXISTS actor_name text;
ALTER TABLE office_audit ADD COLUMN IF NOT EXISTS office_name text;
ALTER TABLE office_audit ADD COLUMN IF NOT EXISTS subject_type text;
ALTER TABLE office_audit ADD COLUMN IF NOT EXISTS subject_ref text;
ALTER TABLE office_audit ADD COLUMN IF NOT EXISTS metadata jsonb NOT NULL DEFAULT '{}';
ALTER TABLE office_audit ADD COLUMN IF NOT EXISTS source text NOT NULL DEFAULT 'legacy';
CREATE INDEX IF NOT EXISTS audit_time_idx ON office_audit(created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS audit_actor_idx ON office_audit(actor_id,created_at DESC);
CREATE INDEX IF NOT EXISTS audit_subject_idx ON office_audit(subject_id,created_at DESC);
CREATE INDEX IF NOT EXISTS audit_office_idx ON office_audit(organization_id,created_at DESC);
CREATE INDEX IF NOT EXISTS audit_action_idx ON office_audit(action,created_at DESC);
CREATE TABLE IF NOT EXISTS revoked_sessions (
 token_hash char(64) PRIMARY KEY, expires_at timestamptz NOT NULL
);

CREATE OR REPLACE FUNCTION reygom_audit_change() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE
 before_row jsonb := CASE WHEN TG_OP='INSERT' THEN '{}'::jsonb ELSE to_jsonb(OLD) END;
 after_row jsonb := CASE WHEN TG_OP='DELETE' THEN '{}'::jsonb ELSE to_jsonb(NEW) END;
 row_data jsonb; actor uuid; office uuid; subject uuid; kind text; ref text;
 username_snapshot text; office_snapshot text; fields jsonb; changes jsonb := '{}';
 key text; allowed text[];
BEGIN
 row_data := CASE WHEN TG_OP='DELETE' THEN before_row ELSE after_row END;
 actor := NULLIF(current_setting('reygom.user_id',true),'')::uuid;
 subject := (row_data->>'id')::uuid;
 office := (row_data->>'organization_id')::uuid;
 kind := CASE TG_TABLE_NAME WHEN 'properties' THEN 'property' WHEN 'leads' THEN 'lead'
  WHEN 'users' THEN 'user' WHEN 'organizations' THEN 'office' WHEN 'property_images' THEN 'image'
  WHEN 'property_submissions' THEN 'submission' END;
 IF kind='office' THEN office:=subject; END IF;
 IF kind='image' THEN
  SELECT p.organization_id,p.reference_id INTO office,ref FROM public.properties p WHERE p.id=(row_data->>'property_id')::uuid;
  office:=COALESCE(office,NULLIF(current_setting('reygom.organization_id',true),'')::uuid);
 END IF;
 IF kind='property' THEN ref:=row_data->>'reference_id';
 ELSIF kind='user' THEN ref:=row_data->>'username';
 ELSIF kind='office' THEN ref:=row_data->>'name'; END IF;
 SELECT u.username INTO username_snapshot FROM public.users u WHERE u.id=actor;
 SELECT o.name INTO office_snapshot FROM public.organizations o WHERE o.id=office;
 IF kind='office' THEN office_snapshot:=row_data->>'name'; END IF;
 SELECT COALESCE(jsonb_agg(k ORDER BY k),'[]') INTO fields FROM (
  SELECT k FROM jsonb_object_keys(before_row||after_row) k
  WHERE (before_row->k) IS DISTINCT FROM (after_row->k)
   AND k NOT IN ('updated_at','created_at','version')
 ) changed;
 IF TG_OP='UPDATE' AND fields='[]'::jsonb THEN RETURN NEW; END IF;
 allowed:=CASE kind WHEN 'user' THEN ARRAY['role','is_active'] WHEN 'office' THEN ARRAY['status','country_code']
  WHEN 'property' THEN ARRAY['price','status','operation_type','property_type']
  WHEN 'submission' THEN ARRAY['status'] ELSE ARRAY[]::text[] END;
 IF TG_OP='UPDATE' THEN
  FOREACH key IN ARRAY allowed LOOP
   IF (before_row->key) IS DISTINCT FROM (after_row->key) THEN
    changes:=changes||jsonb_build_object(key,jsonb_build_object('before',before_row->key,'after',after_row->key));
   END IF;
  END LOOP;
 END IF;
 INSERT INTO public.office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,subject_ref,metadata,source)
 VALUES(actor,COALESCE(username_snapshot,'Actor no identificado'),office,office_snapshot,
  kind||'.'||CASE TG_OP WHEN 'INSERT' THEN 'created' WHEN 'UPDATE' THEN 'updated' ELSE 'deleted' END,
  subject,kind,ref,jsonb_build_object('changed_fields',fields,'changes',changes),'database');
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;

DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['users','organizations','properties','leads','property_images','property_submissions'] LOOP
  EXECUTE format('DROP TRIGGER IF EXISTS reygom_history ON %I',t);
  EXECUTE format('CREATE TRIGGER reygom_history AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION reygom_audit_change()',t);
 END LOOP;
END $$;

CREATE OR REPLACE FUNCTION reygom_protect_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT (current_user=pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid='public.office_audit'::regclass))
   AND COALESCE(current_setting('reygom.audit_maintenance',true),'')='on') THEN
  RAISE EXCEPTION 'Audit history cannot be modified or deleted';
 END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
DROP TRIGGER IF EXISTS protect_audit ON office_audit;
CREATE TRIGGER protect_audit BEFORE UPDATE OR DELETE ON office_audit FOR EACH ROW EXECUTE FUNCTION reygom_protect_audit();
REVOKE UPDATE,DELETE,TRUNCATE ON office_audit FROM reygom_office_runtime;
INSERT INTO office_audit(actor_name,action,subject_type,metadata,source)
 SELECT 'Sistema','audit.enabled','system','{"note":"Inicio del registro ampliado de actividad"}'::jsonb,'application'
 WHERE NOT EXISTS(SELECT 1 FROM office_audit WHERE action='audit.enabled');
CREATE OR REPLACE FUNCTION reygom_set_office() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE office uuid := NULLIF(current_setting('reygom.organization_id',true),'')::uuid;
BEGIN
 IF current_user='reygom_office_runtime' THEN
  IF office IS NULL THEN RAISE EXCEPTION 'Office context required'; END IF;
  IF TG_OP='INSERT' THEN NEW.organization_id:=office; END IF;
  IF NEW.organization_id IS DISTINCT FROM office THEN RAISE EXCEPTION 'Office mismatch'; END IF;
  IF TG_TABLE_NAME='properties' THEN
   -- Deactivation must not prevent colleagues managing an existing assignment.
   IF TG_OP='INSERT' OR NEW.agent_id IS DISTINCT FROM OLD.agent_id THEN
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.agent_id AND organization_id=office AND is_active) THEN
     IF NEW.agent_id::text IS DISTINCT FROM current_setting('reygom.user_id',true) OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.agent_id AND role='superadmin' AND is_active) THEN RAISE EXCEPTION 'Agent outside office'; END IF;
    END IF;
   END IF;
  ELSIF TG_TABLE_NAME='leads' THEN
   IF TG_OP='INSERT' OR NEW.assigned_to IS DISTINCT FROM OLD.assigned_to THEN
    IF NEW.assigned_to IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.assigned_to AND organization_id=office AND is_active) THEN RAISE EXCEPTION 'Agent outside office'; END IF;
   END IF;
   IF NEW.property_interest_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM properties WHERE id=NEW.property_interest_id AND organization_id=office) THEN RAISE EXCEPTION 'Property outside office'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;

COMMIT;
