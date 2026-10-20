BEGIN;
ALTER TABLE public.internal_notifications ADD COLUMN IF NOT EXISTS access_request_id uuid REFERENCES public.access_requests(id) ON DELETE SET NULL;
CREATE OR REPLACE FUNCTION public.reygom_access_request_notify() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE batch uuid; actor record; device_label text; message text;
BEGIN
 IF NEW.status<>'pending' THEN RETURN NEW; END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.status='pending' THEN RETURN NEW; END IF;
 END IF;
 SELECT u.username,u.role,o.name AS office_name INTO actor
 FROM public.users u JOIN public.organizations o ON o.id=u.organization_id WHERE u.id=NEW.user_id;
 IF actor.role='superadmin' THEN RETURN NEW; END IF;
 SELECT label INTO device_label FROM public.access_devices WHERE id=NEW.device_id;
 message:=format(E'Usuario: %s\nOficina: %s\nIP: %s\nDispositivo: %s\nSolicitud: %s\nFecha (UTC): %s\nEl acceso permanece bloqueado hasta su aprobación.',actor.username,actor.office_name,host(NEW.ip),coalesce(device_label,'Sin identificar'),NEW.id,to_char(NEW.requested_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS'));
 INSERT INTO public.notification_batches(sender_id,request_key,payload_hash)
 VALUES(NULL,public.uuid_generate_v4(),repeat('0',64)) RETURNING id INTO batch;
 INSERT INTO public.internal_notifications(batch_id,sender_id,sender_name,sender_role,recipient_id,recipient_office_id,title,body,access_request_id)
 SELECT batch,NULL,'Seguridad ReyGom','system',u.id,u.organization_id,'Solicitud de autorización de acceso',message,NEW.id
 FROM public.users u JOIN public.organizations o ON o.id=u.organization_id
 WHERE u.role='superadmin' AND u.is_active AND o.status='active' AND o.deleted_at IS NULL;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION public.reygom_access_request_notify() FROM PUBLIC;
DROP TRIGGER IF EXISTS access_request_notification ON public.access_requests;
CREATE TRIGGER access_request_notification AFTER INSERT OR UPDATE OF status ON public.access_requests
FOR EACH ROW EXECUTE FUNCTION public.reygom_access_request_notify();
COMMIT;
