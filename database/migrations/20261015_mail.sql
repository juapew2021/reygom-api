BEGIN;
CREATE TABLE IF NOT EXISTS mail_deliveries (
 id bigserial PRIMARY KEY,organization_id uuid NOT NULL REFERENCES organizations(id),request_key varchar(100) NOT NULL,payload_hash char(64) NOT NULL,
 actor_id uuid REFERENCES users(id),kind varchar(30) NOT NULL,status varchar(20) NOT NULL CHECK(status IN ('sending','sent','unknown')),
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),UNIQUE(organization_id,request_key)
);
ALTER TABLE mail_deliveries ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS mail_office ON mail_deliveries;
CREATE POLICY mail_office ON mail_deliveries USING(organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid) WITH CHECK(organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid);
GRANT SELECT,INSERT,UPDATE ON mail_deliveries TO reygom_office_runtime;
GRANT USAGE,SELECT ON SEQUENCE mail_deliveries_id_seq TO reygom_office_runtime;
CREATE OR REPLACE FUNCTION reygom_mail_audit() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 INSERT INTO public.office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,subject_ref,metadata,source)
 SELECT NEW.actor_id,COALESCE((SELECT username FROM public.users WHERE id=NEW.actor_id),'Envío automático'),NEW.organization_id,(SELECT name FROM public.organizations WHERE id=NEW.organization_id),
 'mail.'||NEW.status,NULL,'mail',NEW.id::text,jsonb_build_object('kind',NEW.kind,'status',NEW.status),'database';
 RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS reygom_mail_history ON mail_deliveries;
CREATE TRIGGER reygom_mail_history AFTER INSERT OR UPDATE OF status ON mail_deliveries FOR EACH ROW EXECUTE FUNCTION reygom_mail_audit();

COMMIT;
