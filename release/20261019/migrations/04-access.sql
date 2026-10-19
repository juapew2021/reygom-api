BEGIN;
CREATE TABLE access_devices (
 id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), user_id uuid NOT NULL REFERENCES users(id),
 secret_hash char(64) NOT NULL, label varchar(160) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), last_seen timestamptz NOT NULL DEFAULT now(),
 UNIQUE(user_id,secret_hash), UNIQUE(id,user_id)
);
CREATE TABLE user_access_policies (
 user_id uuid PRIMARY KEY REFERENCES users(id), max_devices int NOT NULL DEFAULT 1 CHECK(max_devices BETWEEN 1 AND 20),
 max_ips int NOT NULL DEFAULT 1 CHECK(max_ips BETWEEN 1 AND 20)
);
CREATE TABLE access_requests (
 id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), user_id uuid NOT NULL REFERENCES users(id),
 device_id uuid NOT NULL, ip inet NOT NULL,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
 requested_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(device_id,user_id) REFERENCES access_devices(id,user_id), UNIQUE(device_id,ip)
);
CREATE TABLE access_grants (
 id uuid PRIMARY KEY DEFAULT uuid_generate_v4(), user_id uuid NOT NULL REFERENCES users(id),
 device_id uuid NOT NULL, ip inet NOT NULL, expires_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), created_by uuid NOT NULL REFERENCES users(id),
 revoked_at timestamptz, revoked_by uuid REFERENCES users(id),
 FOREIGN KEY(device_id,user_id) REFERENCES access_devices(id,user_id)
);
CREATE UNIQUE INDEX access_current_pair ON access_grants(device_id,ip) WHERE revoked_at IS NULL;
CREATE INDEX access_user ON access_grants(user_id,device_id);
REVOKE ALL ON access_devices,user_access_policies,access_requests,access_grants FROM PUBLIC,reygom_office_runtime;
COMMIT;
