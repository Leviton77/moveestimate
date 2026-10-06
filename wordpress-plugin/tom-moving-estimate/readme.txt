=== Tom Moving Estimate ===
Contributors: tommoving
Tags: moving estimate, photos, video, Cloudflare R2
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.0-rc18
License: Proprietary

Private moving-estimate information, photo and video submissions, with media hosted on Cloudflare R2 and WordPress rep access.

== What it does ==

* Keeps the customer estimate at /estimate/.
* Requires the customer to save the moving information first.
* After the information is saved, presents two clear actions: upload photos or start the guided video.
* Keeps the information available to reps even when the customer does not continue with media.
* Accepts up to 50 photos, records up to 15 minutes, or accepts an existing mobile video.
* Uploads photos and videos directly from the browser to a private Cloudflare R2 bucket.
* Uses WordPress accounts and the Moving Estimate Rep role for staff access.
* Provides private playback, laser pointer, timestamp drawings and notes.
* Allows authorized reps to download or permanently delete photos and videos.
* Allows authorized reps to permanently delete an entire estimate, one at a time or in bulk from the list.
* Sends a warning three days before automatic deletion.
* Automatically deletes submitted photos or video 30 days after upload.
* Keeps the small estimate record in the WordPress database after media deletion.
* Prepares WordPress storage for representative-reviewed AI moving reports.
* Shows a disabled AI report workspace before any AI connection or customer-media processing is enabled.
* Lets representatives edit and approve a structured synthetic report during the Phase 2A pilot.
* Lets authorized representatives download the current report as CSV or JSON.
* Provides a clean internal report that can be printed or saved as PDF without a paid PDF service.
* Builds a one-click full-text lead report for any estimate and emails it to the moving-estimate reps.

== Installation ==

1. Upload and activate the plugin on the staging WordPress site.
2. Open Move Estimates > Settings.
3. Enter the Cloudflare Account ID, staging bucket name, Access Key ID and Secret Access Key.
4. Save and test the R2 connection.
5. Apply the displayed CORS policy to the R2 staging and production buckets.
6. Use Connect the /estimate/ page on staging. The plugin adds a reversible rewrite while leaving the previous static folder untouched.
7. Assign staff the Moving Estimate Rep role under WordPress Users.
8. Test required-information, photo and video submissions, including rep review, download and deletion.

== Private storage ==

The Access Key ID and Secret Access Key are encrypted before storage using the WordPress authentication salts. The R2 bucket remains private. Customer uploads use short-lived signed URLs and rep playback/download links are short-lived.

For an additional deletion backstop, create an R2 lifecycle rule for the prefix sessions/ that expires objects after 33 days.

== Data retained in WordPress ==

Customer contact and move details, submission choice, consent time, media metadata, estimate status, rep notes, video annotations and future representative-reviewed AI reports remain in the WordPress database. Photo and video bytes are stored only in R2 and are deleted according to the retention schedule.

== Phase 2A preparation ==

This release creates the database fields and representative-only AI Moving Report panel. The analysis connection is intentionally disabled. Installing this release does not send photos or videos to OpenAI and does not create API charges.

On staging, an administrator can load the bundled synthetic example into a test estimate. Representatives can then correct inventory, box ranges, disassembly recommendations, mattress-bag quantities, access details and open questions. The original synthetic draft and the current corrected report are stored separately.

Representatives can export the current saved report as CSV or JSON, or open a print-ready report and use the browser's Print / Save as PDF function. Draft reports are visibly marked DRAFT; only approved reports are marked APPROVED.

== Live walkthrough (1.2.0) ==

A representative can start a guided real-time video call with a customer from Move Estimates > Live Walkthrough. The call runs on the separate Sites app; the customer opens a link (sent by text or email) with no login. When the call ends, the recording and the contact details the customer gave are pulled back into WordPress as a new "Live walkthrough" estimate request, with the same private R2 storage, review screen and 30-day retention as an uploaded video. A background check every five minutes imports any call whose tab was closed before finishing. Requires the Sites app URL and a shared secret under Live Walkthrough settings; texting the link also needs Twilio credentials.

= 1.2.0-rc3 =
* The laser tool on the video review screen now saves a point instead of only flashing while held — click or tap it to drop a marker at that moment in the video, alongside drawings and notes.
* The saved shared secret for the Sites connection is now trimmed on use, so a value pasted with stray whitespace still authenticates.

= 1.2.0-rc4 =
* A "Finish in Tom Estimator" attempt that isn't ready yet no longer bounces to a near-blank "start a new call" form — it keeps the call's links/status on screen alongside a clearer explanation (still uploading vs. the customer's browser closed before it could).

= 1.2.0-rc5 =
* "Open the call" no longer opens the rep's call in a new tab — on at least one tester's machine that tab was being silently closed by the browser the instant the client's camera/mic released, with no way to reach Finish in Tom Estimator afterward. It now navigates the current tab instead; the call link is also shown as a copyable field for anyone who wants to open it in a new tab/window themselves.

= 1.2.0-rc6 =
* The rc5 fix wasn't enough on its own — same-tab navigation to the call closed too. The one pattern that has proven reliable across every test is opening the link in a browser tab the rep opened themselves (typed/pasted, not clicked into). The "Call ready" screen now leads with that as the recommended step, with the one-click button kept as a fallback underneath.

= 1.2.0-rc7 =
* The client's live-call contact form now also asks for expected moving date, home size, current address and destination address (all optional — only name is required). A live walkthrough imported into WordPress now fills in these fields from what the client gave, instead of leaving them blank.

= 1.2.0-rc8 =
* The rep now chooses the customer's language (English or French) when starting a call. The customer's entire experience — the call screen, error messages, the contact form, and the text/email invite sent to them — renders in that language. This does not change anything on the rep's own screen.

= 1.2.0-rc9 =
* The estimate review screen's "Client details" panel is now editable — name, email, phone, move date, home size, current address and destination address can all be filled in or corrected by the rep, not just viewed. Useful whenever a live call ends without the customer filling in every field.

= 1.2.0-rc10 =
* A new "Create lead report" button on the estimate review screen builds a full-text report of everything captured for that lead — client details, submission and status, rep notes, and a plain-text summary of the AI moving report when one has been saved.
* The report page has a "Send by email" field prepopulated with the email addresses of everyone with moving-estimate rep access, editable before sending.

= 1.2.0-rc11 =
* The note automatically added to a live-walkthrough estimate ("Imported from a live walkthrough on...") showed the call time 4 hours off — it was printing the stored time as if it were already local instead of converting it. It now shows the correct local time.
* "Send by email" on the lead report could show WordPress's generic "The link you followed has expired" page and lose what you'd typed. If that happens again, it now sends you back to a fresh copy of the report with your recipient list still filled in so you can just press Send again.

= 1.2.0-rc12 =
* "Send by email" on the lead report was still showing "The link you followed has expired" even though the email had actually sent — the page it returned to afterward was the one failing its own check, not the send itself. That return page no longer requires one, so this should be gone for good now.

= 1.2.0-rc13 =
* Added a "Delete estimate" button on the estimate review screen — permanently removes that lead and any of its photos or video still in private storage.
* Added checkboxes and a "Delete selected" button to the Move Estimates list, so several estimates can be deleted at once.

= 1.2.0-rc14 =
* On the Live Walkthrough "Call ready" screen, "Email from my mail app" (and "Text from my phone") now open in a new tab. If your browser sends email links to a web mail client like Gmail, the old behaviour replaced the Call ready screen and you lost the rep link with no way back.

= 1.2.0-rc15 =
* The link texted or emailed to the customer for a live walkthrough now reads as a tommoving.ca address instead of the raw call-service URL, so it looks trustworthy in a text message. It forwards to the same secure call page.
* Added a "Customer mobile" field on the "Call ready" screen. If you started a call without a phone number, you can add one there and the "Text the link" options appear — no need to start the call over.

= 1.2.0-rc16 =
* Live Walkthrough settings can now use a Twilio API key (SID + secret) instead of the account Auth Token to send the text message. A key is limited in scope and can be revoked on its own, so it's the safer choice. The Auth Token still works as a fallback; existing setups are unaffected.

= 1.2.0-rc17 =
* "Finish in Tom Estimator" after a live call now returns to whichever WordPress site started the call and imports the recording there. Previously, when one call service was shared between a staging site and the live site, the button always went to the live site.

= 1.2.0-rc18 =
* The Live Walkthrough page now lists "Calls waiting to import": finished calls whose recording is uploaded but not yet in Move Estimates, each with an "Import now" button. If the call tab closed before you clicked "Finish in Tom Estimator", you no longer have to wait for the automatic import.
* If an import fails, the reason now shows next to that call, along with when the automatic import last ran. Previously failures were only written to the server's error log.
