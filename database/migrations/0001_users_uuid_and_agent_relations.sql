BEGIN;

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Añadimos una nueva columna UUID temporal.
ALTER TABLE users
ADD COLUMN id_uuid UUID DEFAULT uuid_generate_v4();

-- Garantizamos que todos los usuarios tengan UUID.
UPDATE users
SET id_uuid = uuid_generate_v4()
WHERE id_uuid IS NULL;

ALTER TABLE users
ALTER COLUMN id_uuid SET NOT NULL;

-- Quitamos la clave primaria antigua.
ALTER TABLE users
DROP CONSTRAINT users_pkey;

-- Eliminamos el identificador entero.
ALTER TABLE users
DROP COLUMN id;

-- Renombramos la nueva columna.
ALTER TABLE users
RENAME COLUMN id_uuid TO id;

-- Creamos la nueva clave primaria UUID.
ALTER TABLE users
ADD CONSTRAINT users_pkey PRIMARY KEY (id);

-- Relación entre propiedades y agentes.
ALTER TABLE properties
ADD CONSTRAINT properties_agent_id_fkey
FOREIGN KEY (agent_id)
REFERENCES users(id)
ON DELETE SET NULL;

-- Relación entre leads y agentes.
ALTER TABLE leads
ADD CONSTRAINT leads_assigned_to_fkey
FOREIGN KEY (assigned_to)
REFERENCES users(id)
ON DELETE SET NULL;

-- Índices para mejorar consultas por agente.
CREATE INDEX IF NOT EXISTS idx_properties_agent_id
ON properties(agent_id);

CREATE INDEX IF NOT EXISTS idx_leads_assigned_to
ON leads(assigned_to);

COMMIT;
