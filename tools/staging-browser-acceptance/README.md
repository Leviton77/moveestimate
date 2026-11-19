# Temporary browser acceptance helper

For the authorized HTTPS Staging site only. Not part of the product package. Arming uses the normal authenticated WordPress activation lifecycle, checks rc31/schema, creates no quote and expires after 30 minutes. No management REST endpoint or API credential is introduced.

Only the two exact synthetic fixture names from the source, reserved phone, empty email, exact synthetic address and language-specific Staging form URL are intercepted on the existing public sessions POST. A per-language atomic option prevents duplicate fixture submissions. The real controller runs unchanged. Mail is intercepted only inside these fixture callbacks after a self-check; ordinary requests are untouched. Rows remain saved until browser event verification finishes.

Deactivate through WordPress after the browser run. The deactivation hook deletes only rows matching five ownership predicates, cancels their customer email hooks and writes sanitized report.php prefixed by PHP exit. The report is read through pinned-host SFTP, never publicly exposed. The closed batch cannot be rearmed. If the browser fails, still deactivate and inspect the failure/cleanup report. Do not install on Production.

Run `php tests/staging-browser-acceptance-harness.php` for isolated guard, real-handler-with-doubles, mail isolation and cleanup tests. Synthetic GCLID values and pixel HTTP acceptance do not prove paid-click attribution.
