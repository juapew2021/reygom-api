BEGIN;
CREATE TABLE user_mail_accounts (
 owner_id uuid PRIMARY KEY REFERENCES users(id),organization_id uuid NOT NULL REFERENCES organizations(id),
 address varchar(254) NOT NULL UNIQUE,resource_id varchar(100) NOT NULL UNIQUE,token_cipher text NOT NULL,
 display_name varchar(160) NOT NULL,enabled boolean NOT NULL DEFAULT true,created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE user_mail_accounts ENABLE ROW LEVEL SECURITY;
CREATE POLICY personal_mail_account ON user_mail_accounts FOR SELECT USING(
 owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid
 AND organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.id=owner_id AND u.organization_id=user_mail_accounts.organization_id AND u.is_active AND o.status='active' AND o.deleted_at IS NULL)
);
GRANT SELECT ON user_mail_accounts TO reygom_office_runtime;
CREATE TABLE personal_mail_deliveries (
 id bigserial PRIMARY KEY,organization_id uuid NOT NULL REFERENCES organizations(id),owner_id uuid NOT NULL REFERENCES users(id),
 request_key varchar(100) NOT NULL,payload_hash char(64) NOT NULL,actor_id uuid NOT NULL REFERENCES users(id),
 kind varchar(30) NOT NULL CHECK(kind='manual'),status varchar(20) NOT NULL CHECK(status IN ('sending','sent','unknown')),
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),UNIQUE(owner_id,request_key),CHECK(actor_id=owner_id)
);
ALTER TABLE personal_mail_deliveries ENABLE ROW LEVEL SECURITY;
CREATE POLICY personal_mail_delivery ON personal_mail_deliveries USING(
 owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid AND organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM user_mail_accounts a WHERE a.owner_id=personal_mail_deliveries.owner_id AND a.organization_id=personal_mail_deliveries.organization_id AND a.enabled)
) WITH CHECK(
 owner_id=nullif(current_setting('reygom.user_id',true),'')::uuid AND organization_id=nullif(current_setting('reygom.organization_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM user_mail_accounts a WHERE a.owner_id=personal_mail_deliveries.owner_id AND a.organization_id=personal_mail_deliveries.organization_id AND a.enabled)
);
GRANT SELECT,INSERT ON personal_mail_deliveries TO reygom_office_runtime;
GRANT UPDATE(status,updated_at) ON personal_mail_deliveries TO reygom_office_runtime;
GRANT USAGE,SELECT ON SEQUENCE personal_mail_deliveries_id_seq TO reygom_office_runtime;
CREATE TRIGGER reygom_personal_mail_history AFTER INSERT OR UPDATE OF status ON personal_mail_deliveries FOR EACH ROW EXECUTE FUNCTION reygom_mail_audit();
COMMIT;
