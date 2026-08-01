BEGIN;

-- Organización predeterminada para los datos existentes.
INSERT INTO organizations (
    name,
    slug,
    legal_name,
    country_code,
    status,
    is_demo,
    settings
)
VALUES (
    'ReyGom',
    'reygom',
    'ReyGom',
    'ES',
    'active',
    FALSE,
    '{}'::jsonb
)
ON CONFLICT (slug) DO NOTHING;

-- Añadir organization_id a las entidades principales.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS organization_id UUID NULL;

ALTER TABLE properties
    ADD COLUMN IF NOT EXISTS organization_id UUID NULL;

ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS organization_id UUID NULL;

-- Asociar los datos históricos con la organización predeterminada.
UPDATE users
SET organization_id = (
    SELECT id
    FROM organizations
    WHERE slug = 'reygom'
    LIMIT 1
)
WHERE organization_id IS NULL;

UPDATE properties
SET organization_id = (
    SELECT id
    FROM organizations
    WHERE slug = 'reygom'
    LIMIT 1
)
WHERE organization_id IS NULL;

UPDATE leads
SET organization_id = (
    SELECT id
    FROM organizations
    WHERE slug = 'reygom'
    LIMIT 1
)
WHERE organization_id IS NULL;

-- Recrear las claves foráneas de manera idempotente.
ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_organization_id_fkey;

ALTER TABLE users
    ADD CONSTRAINT users_organization_id_fkey
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;

ALTER TABLE properties
    DROP CONSTRAINT IF EXISTS properties_organization_id_fkey;

ALTER TABLE properties
    ADD CONSTRAINT properties_organization_id_fkey
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;

ALTER TABLE leads
    DROP CONSTRAINT IF EXISTS leads_organization_id_fkey;

ALTER TABLE leads
    ADD CONSTRAINT leads_organization_id_fkey
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;

-- Índices necesarios para filtrar datos por organización.
CREATE INDEX IF NOT EXISTS idx_users_organization_id
    ON users(organization_id);

CREATE INDEX IF NOT EXISTS idx_properties_organization_id
    ON properties(organization_id);

CREATE INDEX IF NOT EXISTS idx_leads_organization_id
    ON leads(organization_id);

COMMIT;
