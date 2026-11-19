<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Client-asserted campaign labels, not proof of a paid click or conversion. */
final class TME_Campaign_Attribution
{
    public static function validate($payload, ?int $now_ms = null): array
    {
        if (!is_array($payload) || ($payload['consent'] ?? null) !== 'granted') {
            return array();
        }
        $now = $now_ms ?? (int) floor(microtime(true) * 1000);
        $consent = $payload['consentAt'] ?? null;
        if (!is_int($consent) || $consent <= 0 || $consent > $now
            || $now - $consent >= 365 * 86400 * 1000 || !is_array($payload['values'] ?? null)) {
            return array();
        }
        $values = array();
        foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term') as $key) {
            $value = $payload['values'][$key] ?? null;
            if (is_string($value) && preg_match('/\A[A-Za-z0-9._~-]{1,120}\z/D', $value)) {
                $values[$key] = $value;
            }
        }
        return $values ? array(
            'campaign_attribution' => wp_json_encode($values),
            'campaign_consent_at' => gmdate('Y-m-d H:i:s', intdiv($consent, 1000)),
        ) : array();
    }
}
