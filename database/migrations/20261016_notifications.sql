BEGIN;
CREATE TABLE IF NOT EXISTS notification_batches(
 id uuid PRIMARY KEY DEFAULT uuid_generate_v4(),sender_id uuid REFERENCES users(id) ON DELETE SET NULL,
 request_key uuid NOT NULL,payload_hash char(64) NOT NULL,created_at timestamptz NOT NULL DEFAULT now(),UNIQUE(sender_id,request_key)
);
CREATE TABLE IF NOT EXISTS internal_notifications(
 id uuid PRIMARY KEY DEFAULT uuid_generate_v4(),batch_id uuid NOT NULL REFERENCES notification_batches(id) ON DELETE CASCADE,
 sender_id uuid REFERENCES users(id) ON DELETE SET NULL,sender_name varchar(100) NOT NULL,sender_role varchar(20) NOT NULL,
 recipient_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,recipient_office_id uuid NOT NULL REFERENCES organizations(id),
 title varchar(160) NOT NULL,body text NOT NULL CHECK(length(body)<=4000),created_at timestamptz NOT NULL DEFAULT now(),read_at timestamptz,
 UNIQUE(batch_id,recipient_id)
);
CREATE INDEX IF NOT EXISTS notification_inbox ON internal_notifications(recipient_id,created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS notification_unread ON internal_notifications(recipient_id) WHERE read_at IS NULL;
CREATE INDEX IF NOT EXISTS notification_outbox ON internal_notifications(sender_id,batch_id);
ALTER TABLE notification_batches ENABLE ROW LEVEL SECURITY;
ALTER TABLE internal_notifications ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS batch_owner ON notification_batches;
CREATE POLICY batch_owner ON notification_batches TO reygom_office_runtime USING(sender_id=nullif(current_setting('reygom.user_id',true),'')::uuid) WITH CHECK(sender_id=nullif(current_setting('reygom.user_id',true),'')::uuid);
DROP POLICY IF EXISTS notification_visible ON internal_notifications;
DROP POLICY IF EXISTS notification_send ON internal_notifications;
DROP POLICY IF EXISTS notification_read ON internal_notifications;
CREATE POLICY notification_visible ON internal_notifications FOR SELECT TO reygom_office_runtime USING(recipient_id=nullif(current_setting('reygom.user_id',true),'')::uuid OR sender_id=nullif(current_setting('reygom.user_id',true),'')::uuid);
CREATE POLICY notification_send ON internal_notifications FOR INSERT TO reygom_office_runtime WITH CHECK(
 sender_id=nullif(current_setting('reygom.user_id',true),'')::uuid AND EXISTS(SELECT 1 FROM notification_batches b WHERE b.id=batch_id AND b.sender_id=internal_notifications.sender_id) AND EXISTS(
 SELECT 1 FROM users s,users r,organizations o WHERE s.id=sender_id AND s.is_active AND r.id=recipient_id AND r.is_active AND r.organization_id=recipient_office_id AND o.id=r.organization_id AND o.status='active' AND o.deleted_at IS NULL
 AND (s.role='superadmin' OR (s.organization_id=r.organization_id AND ((s.role='admin' AND r.role IN ('agent','agente')) OR (s.role IN ('agent','agente') AND r.role IN ('admin','agent','agente')))))
 ));
CREATE POLICY notification_read ON internal_notifications FOR UPDATE TO reygom_office_runtime USING(recipient_id=nullif(current_setting('reygom.user_id',true),'')::uuid) WITH CHECK(recipient_id=nullif(current_setting('reygom.user_id',true),'')::uuid);
GRANT SELECT,INSERT ON notification_batches,internal_notifications TO reygom_office_runtime;
GRANT UPDATE(read_at) ON internal_notifications TO reygom_office_runtime;
CREATE OR REPLACE FUNCTION reygom_notification_audit(batch uuid) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE actor uuid:=nullif(current_setting('reygom.user_id',true),'')::uuid;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.notification_batches WHERE id=batch AND sender_id=actor) THEN RAISE EXCEPTION 'Notification batch not owned'; END IF;
 INSERT INTO public.office_audit(actor_id,actor_name,organization_id,office_name,action,subject_id,subject_type,metadata,source)
 SELECT u.id,u.username,u.organization_id,o.name,'notification.sent',batch,'notification',jsonb_build_object('recipients',(SELECT count(*) FROM public.internal_notifications WHERE batch_id=batch)),'application'
 FROM public.users u JOIN public.organizations o ON o.id=u.organization_id WHERE u.id=actor
 AND NOT EXISTS(SELECT 1 FROM public.office_audit WHERE subject_id=batch AND action='notification.sent');
END $$;
REVOKE ALL ON FUNCTION reygom_notification_audit(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION reygom_notification_audit(uuid) TO reygom_office_runtime;
COMMIT;
