BEGIN;
LOCK TABLE properties IN ACCESS EXCLUSIVE MODE;
ALTER TABLE properties ADD COLUMN public_reference varchar(32);
CREATE SEQUENCE property_public_reference_seq AS bigint MINVALUE 1 NO CYCLE;
-- Start beyond all numbers already issued by the legacy generator, including gaps.
DO $$ DECLARE high bigint; BEGIN
 SELECT greatest(COALESCE(max((regexp_match(reference_id,'^RG[A-Z0-9]{8}([0-9]{6,12})$'))[1]::bigint),0),(SELECT last_value FROM property_reference_seq)) INTO high FROM properties;
 PERFORM setval('property_public_reference_seq',greatest(high,1),high>0);
END $$;
CREATE FUNCTION reygom_allocate_public_reference() RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE n text;candidate text;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtext('reygom.public_property_reference'));
 LOOP
  n:=nextval('public.property_public_reference_seq')::text;
  candidate:='RG'||lpad(n,greatest(6,length(n)),'0');
  IF NOT EXISTS(SELECT 1 FROM public.properties WHERE public_reference=candidate OR reference_id=candidate) THEN RETURN candidate; END IF;
 END LOOP;
END $$;
REVOKE ALL ON FUNCTION reygom_allocate_public_reference() FROM PUBLIC;
DO $$ DECLARE row record;digits text;candidate text; BEGIN
 FOR row IN SELECT id,reference_id FROM properties ORDER BY created_at,id LOOP
  digits:=(regexp_match(row.reference_id,'^RG[A-Z0-9]{8}([0-9]{6,12})$'))[1];
  candidate:=NULL;
  IF digits IS NOT NULL THEN
   digits:=(digits::bigint)::text;
   candidate:='RG'||lpad(digits,greatest(6,length(digits)),'0');
   IF EXISTS(SELECT 1 FROM properties WHERE id<>row.id AND (public_reference=candidate OR reference_id=candidate)) THEN candidate:=NULL; END IF;
  END IF;
  IF candidate IS NULL THEN candidate:=reygom_allocate_public_reference(); END IF;
  UPDATE properties SET public_reference=candidate WHERE id=row.id;
 END LOOP;
END $$;
ALTER TABLE properties ALTER COLUMN public_reference SET NOT NULL;
ALTER TABLE properties ADD CONSTRAINT properties_public_reference_key UNIQUE(public_reference);
ALTER TABLE properties ADD CONSTRAINT properties_public_reference_format CHECK(public_reference ~ '^RG[0-9]{6,}$');
CREATE FUNCTION reygom_property_reference_guard() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
  IF NEW.public_reference IS DISTINCT FROM OLD.public_reference OR NEW.reference_id IS DISTINCT FROM OLD.reference_id THEN RAISE EXCEPTION 'Las referencias del inmueble son permanentes.' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 PERFORM pg_advisory_xact_lock(hashtext('reygom.public_property_reference'));
 IF NEW.public_reference IS NOT NULL THEN RAISE EXCEPTION 'La referencia comercial se genera automáticamente.' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM public.properties WHERE public_reference=NEW.reference_id) THEN RAISE EXCEPTION 'La referencia ya identifica otro inmueble.' USING ERRCODE='23505'; END IF;
 NEW.public_reference:=public.reygom_allocate_public_reference();
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION reygom_property_reference_guard() FROM PUBLIC;
CREATE TRIGGER reygom_property_reference_guard BEFORE INSERT OR UPDATE OF reference_id,public_reference ON properties FOR EACH ROW EXECUTE FUNCTION reygom_property_reference_guard();
COMMIT;
