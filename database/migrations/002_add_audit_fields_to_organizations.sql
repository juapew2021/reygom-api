BEGIN;

ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS created_by UUID NULL,
    ADD COLUMN IF NOT EXISTS updated_by UUID NULL,
    ADD COLUMN IF NOT EXISTS version INTEGER NOT NULL DEFAULT 1;

ALTER TABLE organizations
    DROP CONSTRAINT IF EXISTS organizations_created_by_fkey;

ALTER TABLE organizations
    ADD CONSTRAINT organizations_created_by_fkey
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL;

ALTER TABLE organizations
    DROP CONSTRAINT IF EXISTS organizations_updated_by_fkey;

ALTER TABLE organizations
    ADD CONSTRAINT organizations_updated_by_fkey
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL;

ALTER TABLE organizations
    DROP CONSTRAINT IF EXISTS organizations_version_check;

ALTER TABLE organizations
    ADD CONSTRAINT organizations_version_check
        CHECK (version > 0);

CREATE INDEX IF NOT EXISTS idx_organizations_created_by
    ON organizations(created_by);

CREATE INDEX IF NOT EXISTS idx_organizations_updated_by
    ON organizations(updated_by);

COMMIT;
