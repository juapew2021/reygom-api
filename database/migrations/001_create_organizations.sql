BEGIN;

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE IF NOT EXISTS organizations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),

    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,

    legal_name VARCHAR(200),
    tax_id VARCHAR(50),

    email VARCHAR(160),
    phone VARCHAR(30),
    website VARCHAR(255),

    country_code CHAR(2) NOT NULL DEFAULT 'ES',

    logo VARCHAR(255),

    primary_color VARCHAR(20),
    secondary_color VARCHAR(20),

    status VARCHAR(20) NOT NULL DEFAULT 'active',

    is_demo BOOLEAN NOT NULL DEFAULT FALSE,

    settings JSONB NOT NULL DEFAULT '{}'::jsonb,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    CONSTRAINT organizations_status_check
        CHECK (status IN ('active', 'inactive'))
);

CREATE INDEX IF NOT EXISTS idx_organizations_name
    ON organizations(name);

CREATE INDEX IF NOT EXISTS idx_organizations_slug
    ON organizations(slug);

CREATE INDEX IF NOT EXISTS idx_organizations_status
    ON organizations(status);

CREATE INDEX IF NOT EXISTS idx_organizations_deleted_at
    ON organizations(deleted_at);

COMMIT;
