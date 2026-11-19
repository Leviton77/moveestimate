# One-shot rc31 Staging acceptance helper

This optional QA plugin is separate from the rc31 product package. The execution gate accepts an administrator or the exact WordPress activation hook (including delegated Jetpack activation), and only when both home_url and site_url are exactly HTTPS 1227937.us19.myftpupload.com and the rc31 plugin/schema version is loaded. It adds no REST route, public API, credentials, cron job, or frontend hook.

It reads schema column names, then intercepts wp_mail using pre_wp_mail and verifies interception with an invalid-domain self-check. Two synthetic info quotes are submitted through the actual installed TME_Public::create_session handler: FR with advertising consent and EN with denied advertising consent. Email is empty; phone is a reserved 202-555-0100 test number; names have an unpredictable 96-bit fixture marker. No analytics SDK is executed.

The helper checks actual saved UTM, consent time and GCLID fields. It clears the pending customer-email hook for its own rows and removes only records matching all five ownership fields (ID, token, unpredictable synthetic name, reserved phone, empty email). Existing customer records are never selected or modified except an aggregate row count before and after. A metadata/test failure still attempts strict fixture cleanup.

Only sanitized checks, counts and version/timestamps go to report.php. That file starts with a PHP exit guard and is meant to be read through authenticated SFTP. It contains no raw customer details, lead tokens, SQL errors or stack trace. A cleanup failure or unexpected concurrent row-count change fails the result.

The helper is inert on ordinary page requests and on Production. It does not send messages after activation. Deactivate it after the report is retrieved. Do not reactivate it just to reread the report.

Tests: tests/staging-acceptance-harness.php exercises the real rc31 API/database code with isolated WordPress/database doubles, including Production/lookalike/non-HTTPS guards, admin/schema gates, mail interception, strict cleanup and injected failures. These doubles are not the actual saved-lead acceptance result.
