BEGIN;
CREATE TABLE IF NOT EXISTS property_panoramas (
 id uuid PRIMARY KEY,
 property_id uuid NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
 label varchar(80) NOT NULL,
 filename varchar(40) NOT NULL UNIQUE,
 width integer NOT NULL CHECK(width BETWEEN 1024 AND 4096),
 height integer NOT NULL CHECK(width=height*2),
 created_at timestamptz NOT NULL DEFAULT now(),
 removed_at timestamptz
);
CREATE INDEX IF NOT EXISTS property_panoramas_property ON property_panoramas(property_id) WHERE removed_at IS NULL;
ALTER TABLE property_panoramas ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS panorama_office ON property_panoramas;
CREATE POLICY panorama_office ON property_panoramas TO reygom_office_runtime
 USING(EXISTS(SELECT 1 FROM properties p WHERE p.id=property_id))
 WITH CHECK(EXISTS(SELECT 1 FROM properties p WHERE p.id=property_id));
GRANT SELECT,INSERT,UPDATE ON property_panoramas TO reygom_office_runtime;
COMMIT;
