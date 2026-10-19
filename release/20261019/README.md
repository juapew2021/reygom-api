# Production release preparation — not deployed

The ordered 13-step migration bundle was tested against a fully restored protected backup in an isolated namespace, followed by a second recovery and data checksum comparison. Do not substitute the original DEV office mapping migration.

The owner approved assigning the four unassigned properties to the existing ReyGom office 3feb96a3-3669-4004-80f7-f9544a527a84. The guarded office migration requires this explicit session setting: reygom.release_office_id. It checks the expected five-property/four-unassigned inventory and refuses unexpected unassigned users or leads. Refresh the inventory and backup before any approved deployment. Never transfer DEV users, offices, leads, credentials or property records.

Every migration file has its own recorded SHA256. Most contain their own transaction boundaries: there is no claim that all 13 steps form one atomic transaction. Stop on any error, keep writes frozen and inspect completed steps before recovery. Do not rerun the guarded office mapping blindly after partial completion.

Coordinate API, CRM, public web and crawler publication. Preserve PROD database credentials, JWT, media volumes and the existing user. Set explicit production URLs, including PANORAMA_PUBLIC_BASE_URL; verify exact trusted ingress proxy IP before enabling access controls. The crawler candidate listens on 8090 and must support rental_kind, room_occupancy, country_code and postal_code.

Mailbox info has a separately encrypted, least-scope credential prepared in the protected server vault. It is not in this repository or image, and has not been activated in PROD. Do not reuse DEV mailbox credentials. No mail or paid searches were made during the isolated release rehearsal.

The legacy API does not isolate new offices. Do not automatically return to it after accepting new office data. After reopening writes, preserve new records and use maintenance/forward repair; a full database recovery requires reconciling newer data.

GitHub review publication does not authorize merging main, tagging a release or deploying PROD. Obtain explicit owner approval after review, with immutable images, fresh backups, maintenance window and recovery conditions fixed. v0.5.0 already exists: choose a new release identifier.
