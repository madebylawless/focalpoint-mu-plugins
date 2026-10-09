# Focal Point EasyCoach LTI

Shared, security-focused LTI 1.3 platform integration for the Rayner Focal
Point WordPress multisite.

Version `0.5.0` adds the LTI 1.3 OAuth 2.0 client-credentials token service.
It validates EasyGenerator `private_key_jwt` client assertions against the
registered Tool JWKS, rejects assertion replay and issues one-hour RS256 bearer
tokens restricted to the advertised AGS line-item and score scopes. Line-item
and score routes still return a safe `503` until their complete authenticated
service flows are implemented.

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

## Platform routes

All routes use the `focalpoint-lti/v1` REST namespace:

- `GET /jwks` (implemented)
- `GET|POST /authorize` (implemented for OIDC LTI resource-link launch)
- `POST /token` (implemented for OAuth 2.0 `client_credentials`)
- `GET /lineitems/{lineitem_id}`
- `POST /lineitems/{lineitem_id}/scores`

WordPress exposes these beneath `/wp-json/` on a standard installation.

## OIDC resource-link launch

The learner theme calls `fp_easycoach_lti_launch_url($post_id)` for a published
AI Roleplay with a configured EasyGenerator target link URI. The resulting
same-origin, nonce-protected URL starts this flow:

1. Focal Point creates a five-minute pending launch and redirects the browser
   to EasyGenerator's registered login-initiation endpoint with opaque
   `login_hint` and `lti_message_hint` values.
2. EasyGenerator sends the browser to Focal Point's `/authorize` endpoint with
   its `state`, `nonce`, client ID and an exact registered redirect URI.
3. Focal Point validates the request, logged-in learner, one-time hints and
   redirect URI, then signs a five-minute `LtiResourceLinkRequest` ID token.
4. Focal Point returns an auto-submitting `form_post` containing only `state`
   and `id_token` to the registered EasyGenerator redirect URI.

The token contains the learner's opaque `fp_...` subject, learner role,
deployment, stable resource-link identity, target URI, return URL and the AGS
line-item endpoint. It deliberately excludes the learner's WordPress ID, name
and email address. Each hint pair is single-use; state and nonce hashes are
retained for replay auditing.

AI Roleplay posts are mapped lazily on their first launch. A stable activity
and line item are reused on later launches while each learner launch receives
fresh one-time hints.

## Signing key and public JWKS

The platform uses RSA with SHA-256 (`RS256`). Its private key is loaded from
`FP_EASYCOACH_LTI_PRIVATE_KEY_PATH` and must:

- be an RSA private key of at least 2048 bits;
- be stored outside the WordPress public root, including through symlinks;
- be readable by PHP but not world-readable, group-writable or executable;
- be no larger than 64 KiB.

Recommended permissions are `0600` when PHP runs as the file owner or `0640`
when a dedicated web-server group needs read access. A production key can be
generated on the server without placing it in this repository:

```bash
umask 077
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 \
  -out /protected/path/focalpoint-lti-private.pem
```

When the integration is enabled and the key is valid, `GET /jwks` returns only
the RSA public modulus and exponent, with `kid`, `use: sig` and `alg: RS256`.
The private PEM is never returned or stored in WordPress.

The JWKS endpoint intentionally depends only on the enabled flag and key
configuration. This allows EasyGenerator to retrieve the public key while the
remaining client and deployment registration values are being established.

Changing a production key requires a new unique key ID. Coordinate the new
public key with EasyGenerator before switching the signing configuration;
overlapping multi-key rotation is not part of this milestone.

## Network data model

The MU plugin uses seven network-level tables based on `$wpdb->base_prefix`:

| Table | Purpose |
| --- | --- |
| `fp_lti_user_map` | Stable pseudonymous LTI subject to WordPress user mapping |
| `fp_lti_activities` | Source site training and EasyCoach roleplay mapping |
| `fp_lti_line_items` | AGS line-item identity and score maximum |
| `fp_lti_launches` | Short-lived launch state, nonce hashes and audit state |
| `fp_lti_oauth_assertions` | Hashed OAuth client-assertion identifiers for replay prevention |
| `fp_lti_result_events` | Immutable accepted score events and payload hashes |
| `fp_lti_current_results` | Fast latest/best learner result summary |

The installer is versioned through the network option
`fp_easycoach_lti_schema_version`. Because MU plugins have no activation hook,
the installer checks the version during WordPress `init`, uses a network lock,
and runs `dbDelta()` only when an installation or upgrade is required. Schema
version `3` adds OAuth assertion replay protection. The earlier version `2`
upgrade added hashed login/message hints and immutable target/return URL
snapshots to pending launches; an explicit idempotent migration makes the
state and nonce columns nullable until EasyGenerator supplies those values.

No learner names or email addresses are stored in these tables. WordPress user
IDs remain inside Focal Point and are never sent as the LTI learner identifier.

`fp_lti_oauth_assertions` stores only SHA-256 hashes of accepted
client-assertion `jti` values until they expire. It prevents replay without
retaining EasyGenerator assertions or issued access tokens.

## OAuth token service

EasyGenerator requests an access token by posting `grant_type=client_credentials`,
the standard JWT bearer `client_assertion_type`, its signed client assertion and
one or both advertised AGS scopes to `/token`. Focal Point requires RS256,
selects the exact `kid` from EasyGenerator's JWKS, validates the signature and
the `iss`, `sub`, `aud`, `iat`, `exp` and `jti` claims, and atomically reserves
the hashed `jti` against replay.

Valid requests receive a signed bearer JWT with a 3600-second lifetime. The
token contains the client ID and granted scopes but no learner data. Supported
scopes are:

- `https://purl.imsglobal.org/spec/lti-ags/scope/lineitem`
- `https://purl.imsglobal.org/spec/lti-ags/scope/score`

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
define('FP_EASYCOACH_LTI_INITIATE_LOGIN_URL', '');
define('FP_EASYCOACH_LTI_REDIRECT_URIS', array(
    '',
));
```

`FP_EASYCOACH_LTI_INITIATE_LOGIN_URL` is the EasyGenerator third-party login
initiation endpoint. `FP_EASYCOACH_LTI_REDIRECT_URIS` must contain the exact
HTTPS redirect URI or URIs registered by EasyGenerator; authorization requests
using any other URI are rejected.

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
php focalpoint-easycoach-lti/tests/keys-and-jwks.php
php focalpoint-easycoach-lti/tests/oidc-launch.php
php focalpoint-easycoach-lti/tests/oauth-token-service.php
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
