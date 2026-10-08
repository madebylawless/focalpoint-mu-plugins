# Focal Point EasyCoach LTI

Shared, security-focused LTI 1.3 platform integration for the Rayner Focal
Point WordPress multisite.

Version `0.2.0` adds the versioned network data model and stable opaque learner
mapping. The REST routes still return a safe `503`; the plugin does not yet
generate cryptographic keys, launch EasyCoach, accept OAuth clients, or accept
result requests.

## Responsibilities

This MU plugin will own:

- LTI 1.3 and OpenID Connect platform endpoints;
- OAuth 2.0 client-credentials token issuing;
- JWT signing and verification;
- Focal Point public JWKS publication;
- stable opaque learner subject mapping;
- Assignment and Grade Services line-item and score endpoints;
- validated result ingestion, idempotency and audit metadata.

The learner-facing roleplay controls and profile presentation remain in the
`rayner_focalpoint` theme. Management KPI ingestion and reporting remain in
the `rayner_focalpoint_mgmt` theme.

## Planned platform routes

All routes use the `focalpoint-lti/v1` REST namespace:

- `GET /jwks`
- `GET|POST /authorize`
- `POST /token`
- `GET /lineitems/{lineitem_id}`
- `POST /lineitems/{lineitem_id}/scores`

WordPress exposes these beneath `/wp-json/` on a standard installation.

## Network data model

The MU plugin uses six network-level tables based on `$wpdb->base_prefix`:

| Table | Purpose |
| --- | --- |
| `fp_lti_user_map` | Stable pseudonymous LTI subject to WordPress user mapping |
| `fp_lti_activities` | Source site training and EasyCoach roleplay mapping |
| `fp_lti_line_items` | AGS line-item identity and score maximum |
| `fp_lti_launches` | Short-lived launch state, nonce hashes and audit state |
| `fp_lti_result_events` | Immutable accepted score events and payload hashes |
| `fp_lti_current_results` | Fast latest/best learner result summary |

The installer is versioned through the network option
`fp_easycoach_lti_schema_version`. Because MU plugins have no activation hook,
the installer checks the version during WordPress `init`, uses a network lock,
and runs `dbDelta()` only when an installation or upgrade is required.

No learner names or email addresses are stored in these tables. WordPress user
IDs remain inside Focal Point and are never sent as the LTI learner identifier.

### Learner subjects

Each learner receives a cryptographically random identifier in this form:

```text
fp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

The mapping is stable within one EasyCoach deployment and different across
separate deployments. The public integration API is:

```php
$subject = fp_easycoach_lti_subject_for_user($wp_user_id);
$user_id = fp_easycoach_lti_user_id_for_subject($subject);
```

Mappings are retained rather than recycled so an old EasyCoach result can
never be associated with a different learner.

## Configuration contract

Configuration belongs in protected server/environment configuration, normally
surfaced to WordPress as constants. Do not commit production values.

```php
define('FP_EASYCOACH_LTI_ENABLED', false);
define('FP_EASYCOACH_LTI_CLIENT_ID', '');
define('FP_EASYCOACH_LTI_DEPLOYMENT_ID', '');
define('FP_EASYCOACH_LTI_ISSUER', '');
define('FP_EASYCOACH_LTI_KEY_ID', '');
define('FP_EASYCOACH_LTI_PRIVATE_KEY_PATH', '');
```

The EasyCoach public JWKS endpoint defaults to the vendor-confirmed URL:

```text
https://lti.easygenerator.com/api/v1/jwks
```

It can be overridden with `FP_EASYCOACH_LTI_TOOL_JWKS_URL` if Easygenerator
changes it or a controlled test double is used.

## Local verification

The inactive foundation has a WordPress-free smoke test that checks plugin
bootstrapping, route registration and fail-closed responses:

```bash
php focalpoint-easycoach-lti/tests/smoke.php
php focalpoint-easycoach-lti/tests/data-model.php
```

## Retention and removal

Removing the MU-plugin files does not delete its tables. This is intentional:
LTI identifiers and accepted result history are audit data and must not be
silently destroyed by a deployment change. Any future erasure or retention
tool must be explicit, permission-controlled and coordinated with the related
user-profile JSON and Management records.

## Security rules

- Never store private keys beneath the public web root.
- Never commit private keys, secrets, access tokens or result payloads.
- Do not use email addresses or sequential WordPress user IDs as LTI subjects.
  Subjects will be random opaque identifiers persisted in a dedicated mapping.
- Validate issuer, audience, deployment, nonce, state, timestamps and JWT
  signatures before creating a launch session.
- Validate OAuth scope and client assertions before accepting an AGS score.
- Store a score only after the complete request has been authenticated.
