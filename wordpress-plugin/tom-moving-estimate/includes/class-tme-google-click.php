<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Optional, client-asserted advertising attribution. Never required for a lead. */
final class TME_Google_Click
{
    public static function validate($payload, ?int $now_ms = null): array
    {
        if (!is_array($payload) || ($payload['consent'] ?? null) !== 'granted') {
            return array();
        }
        $id = $payload['gclid'] ?? null;
        $captured = $payload['capturedAt'] ?? null;
        $consent = $payload['consentAt'] ?? null;
        $now = $now_ms ?? (int) floor(microtime(true) * 1000);

        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,512}\z/D', $id)
            || !is_int($captured) || !is_int($consent)
            || $consent <= 0 || $captured < $consent || $captured > $now
            || $now - $captured >= 90 * 86400 * 1000
            || $now - $consent >= 365 * 86400 * 1000) {
            return array();
        }

        return array(
            'google_click_id'          => $id,
            'google_click_captured_at' => gmdate('Y-m-d H:i:s', intdiv($captured, 1000)),
            'google_click_consent_at'  => gmdate('Y-m-d H:i:s', intdiv($consent, 1000)),
        );
    }

    /** Format only validated, saved attribution for an internal Step 1 message. */
    public static function internal_notification(object $session): string
    {
        $id = $session->google_click_id ?? null;
        $captured = $session->google_click_captured_at ?? null;
        $consent = $session->google_click_consent_at ?? null;
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,512}\z/D', $id)
            || !is_string($captured) || !is_string($consent)
            || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $captured)
            || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $consent)) {
            return '';
        }
        return "Google click ID: {$id}\nGoogle click captured (UTC): {$captured}\nAdvertising consent (UTC): {$consent}\n";
    }
}
