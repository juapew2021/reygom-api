Property analytics DEV

Requires migration database/migrations/20261017_property_visits.sql. Private GET /properties/{id}/analytics?days=7|30|90 enforces authoritative admin/superadmin role and existing office RLS. Public POST /public/properties/{id}/visits requires explicit consent, approved Origin, random visit UUID and random 32-byte write token. No visitor IP or raw referrer is stored.

Local geolocation: standalone MaxMind DB PHP reader v1.14.0 (commit f3c92f68b3bec42a9aa780368399e03dc6b91e89, Apache-2.0), installed under analytics/vendor; DB-IP City Lite October 2026 MMDB under analytics/dbip-city-lite.mmdb, uncompressed MD5 5966907981d3e82f338e823d1b83e002, CC BY 4.0, attribution https://db-ip.com. Neither generated database nor vendor checkout is committed. Dockerfile.analytics copies these prepared local assets. Refresh the geolocation database separately before future releases.

DEV ANALYTICS_ALLOWED_ORIGINS=https://crm-dev.reygom.com. Public web is built with --base=/web-preview/ and VITE_API_URL=https://api-dev.reygom.com. PROD is unchanged.

Run php /opt/analytics/prune.php hourly via namespace reygom-dev CronJob reygom-analytics-retention with only DB environment credentials. Retains 90-day history and removes expired anonymous abuse limits. The first run completed successfully.
