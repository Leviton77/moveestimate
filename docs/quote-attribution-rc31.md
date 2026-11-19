# rc31 attribution candidate: Staging review

## Problem and behavior

A real browser capture of the installed rc30 form on Staging found campaign labels in the page URL and sessionStorage but not in the initial sessions POST. formUrl is intentionally clean. The installed handler also emitted GA4 generate_lead for a honeypot response that included a token but no completed save; OpenAI correctly emitted no lead_created.

The candidate adds optional campaignAttribution to an initial quote save, only while current persisted advertising consent is valid. Only utm_source, utm_medium, utm_campaign, utm_content and utm_term labels matching the existing theme's 1–120 character allowlist are accepted. PHP validates the envelope again. Labels are client-asserted source evidence, not authenticated paid attribution.

Both GA4 generate_lead and OpenAI lead_created now require a completed initial save and current persisted consent. Request failures, honeypot responses, in-flight duplicates and consent withdrawal produce no additional lead events. Resume data and media uploads do not acquire new campaign metadata through this change.

## Source provenance

The main branch was rc29 while Staging ran 1.2.0-rc30-gclid-draft. The first commit reconciles the six rc30 source files from the previously reviewed candidate ZIP at:
Leviton77/TomMoving commit 974e51b871271f0d93431a81d7c3cc9fa3d6c4fc,
.github/production/move-estimate-rc30/candidate.zip.

Archive SHA-256: 1a07a07637070301633249eacb767c666425fe610e0d8f3c6ec7c2b8cd71d5c3.
The package's public.js is byte-identical to the JavaScript served by Staging:
e9c4ba2b76d9091c49dd4d24c8bf9a326edddae5713a0dc81146d336d327b416.

This verifies frontend provenance, not an exhaustive comparison of installed PHP. Before installation, compare the live plugin source with the package and account for any site-specific mail patch. Existing GCLID persistence and its internal-mail opt-in are retained. No production credentials or customer records are included.

The second commit contains the attribution fix and tests. Version: 1.2.0-rc31-attribution-draft. Stable tag remains rc29 and stable update manifests/releases are untouched.

## Storage and failure behavior

Adds nullable campaign_attribution (JSON text) and campaign_consent_at (UTC) columns using the existing dbDelta version upgrade. Existing rows are unchanged. Campaign metadata and GCLID writes remain separate from the primary lead insert: a failed optional metadata write must not fail the form or create another lead. A primary insert failure still returns an error.

No database query/fragment, raw referrer URL, session token or campaign data is added to analytics event payloads. The public sessions response does not expose the new metadata. Existing permissions and email recipients are unchanged. A private-register export or admin display of this metadata is a subsequent integration step.

## Validation and limits

- Reproduced missing UTM and GA4 honeypot event against installed Staging browser code with the sessions API intercepted.
- Candidate JS passed seven real-page browser scenarios: success, server rejection, honeypot, unknown consent, declined consent, concurrent double-submit and withdrawal while saving.
- The candidate JS was substituted only in the isolated browser. No installed plugin file changed; API responses and analytics functions were mocked.
- tests/campaign-attribution-harness.php exercises the real PHP create_session and DB code using isolated WordPress/database doubles. It tests validation, the API-to-row mapping, retained GCLID, schema additions and optional-write failures.
- The PHP harness is not a real WordPress/MySQL migration or a saved Staging lead test. No synthetic customer record or email was created by the browser tests.
- Genuine advertising click attribution and Ads Manager reporting remain unverified.

## Staging acceptance after approval

1. Back up installed plugin files and database, compare source, and preserve any site-specific approved patch.
2. Install only on 1227937.us19.myftpupload.com. Do not publish a stable plugin release.
3. Verify the version and nullable columns. Existing lead records must remain unchanged.
4. Route or suppress all outgoing mail on Staging using a separately reviewed, exact-host guard before a synthetic saved-lead test.
5. Save one clearly labeled synthetic FR/EN test quote with and without advertising consent. Verify the private database fields, exactly-once event behavior, no client mail and no production-source activity.
6. Reconcile any failed optional metadata write explicitly; a successful lead save alone does not establish saved attribution.
7. Rollback restores the backed-up plugin files. The two nullable metadata columns can remain; do not destructively remove data as part of rollback.

Production deployment requires separate owner approval.
