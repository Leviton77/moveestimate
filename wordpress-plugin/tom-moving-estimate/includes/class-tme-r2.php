<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_R2
{
    private string $account_id;
    private string $bucket;
    private string $access_key;
    private string $secret_key;
    private string $host;

    public function __construct()
    {
        $credentials = TME_Plugin::credentials();
        $this->account_id = $credentials['account_id'];
        $this->bucket = $credentials['bucket_name'];
        $this->access_key = $credentials['access_key'];
        $this->secret_key = $credentials['secret_key'];
        $this->host = $this->account_id . '.r2.cloudflarestorage.com';
    }

    public function ready(): bool
    {
        return TME_Plugin::is_configured();
    }

    private function encoded_path(string $key = ''): string
    {
        $segments = array_map('rawurlencode', array_filter(explode('/', $key), static fn($part) => $part !== ''));
        $path = '/' . rawurlencode($this->bucket);
        if ($segments) {
            $path .= '/' . implode('/', $segments);
        }
        return $path;
    }

    private function canonical_query(array $query): string
    {
        ksort($query, SORT_STRING);
        $pairs = array();
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $pairs);
    }

    private function signing_key(string $date): string
    {
        $date_key = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $region_key = hash_hmac('sha256', 'auto', $date_key, true);
        $service_key = hash_hmac('sha256', 's3', $region_key, true);
        return hash_hmac('sha256', 'aws4_request', $service_key, true);
    }

    public function presign(string $method, string $key = '', int $expires = 900, array $extra_query = array()): string
    {
        $method = strtoupper($method);
        $expires = min(3600, max(60, $expires));
        $amz_date = gmdate('Ymd\THis\Z');
        $date = substr($amz_date, 0, 8);
        $scope = $date . '/auto/s3/aws4_request';
        $query = array_merge(
            $extra_query,
            array(
                'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
                'X-Amz-Credential'    => $this->access_key . '/' . $scope,
                'X-Amz-Date'          => $amz_date,
                'X-Amz-Expires'       => (string) $expires,
                'X-Amz-SignedHeaders' => 'host',
            )
        );
        $canonical_query = $this->canonical_query($query);
        $canonical_request = implode("\n", array(
            $method,
            $this->encoded_path($key),
            $canonical_query,
            'host:' . $this->host . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ));
        $string_to_sign = implode("\n", array(
            'AWS4-HMAC-SHA256',
            $amz_date,
            $scope,
            hash('sha256', $canonical_request),
        ));
        $signature = hash_hmac('sha256', $string_to_sign, $this->signing_key($date));
        return 'https://' . $this->host . $this->encoded_path($key) . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
    }

    public function upload_url(string $key): string
    {
        return $this->presign('PUT', $key, 900);
    }

    public function view_url(string $key, int $expires = 900): string
    {
        return $this->presign('GET', $key, $expires);
    }

    public function download_url(string $key, string $filename): string
    {
        $safe = sanitize_file_name($filename) ?: 'moving-estimate-file';
        return $this->presign('GET', $key, 300, array(
            'response-content-disposition' => 'attachment; filename="' . $safe . '"',
        ));
    }

    public function head(string $key)
    {
        if (!$this->ready()) {
            return new WP_Error('tme_r2_unconfigured', __('R2 storage is not configured.', 'tom-moving-estimate'));
        }
        $response = wp_remote_request($this->presign('HEAD', $key, 300), array(
            'method'      => 'HEAD',
            'timeout'     => 20,
            'redirection' => 0,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('tme_r2_head', sprintf(__('R2 returned HTTP %d while verifying the file.', 'tom-moving-estimate'), $code));
        }
        return array(
            'size' => (int) wp_remote_retrieve_header($response, 'content-length'),
            'type' => (string) wp_remote_retrieve_header($response, 'content-type'),
        );
    }

    public function delete(string $key)
    {
        if (!$this->ready()) {
            return new WP_Error('tme_r2_unconfigured', __('R2 storage is not configured.', 'tom-moving-estimate'));
        }
        $response = wp_remote_request($this->presign('DELETE', $key, 300), array(
            'method'      => 'DELETE',
            'timeout'     => 30,
            'redirection' => 0,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if (!in_array($code, array(200, 202, 204, 404), true)) {
            return new WP_Error('tme_r2_delete', sprintf(__('R2 returned HTTP %d while deleting the file.', 'tom-moving-estimate'), $code));
        }
        return true;
    }

    public function test_connection()
    {
        if (!$this->ready()) {
            return new WP_Error('tme_r2_unconfigured', __('Enter and save all R2 settings first.', 'tom-moving-estimate'));
        }
        $url = $this->presign('GET', '', 300, array('list-type' => '2', 'max-keys' => '1'));
        $response = wp_remote_get($url, array('timeout' => 20, 'redirection' => 0));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('tme_r2_test', sprintf(__('R2 connection failed with HTTP %d. Check the account, bucket and keys.', 'tom-moving-estimate'), $code));
        }
        return true;
    }
}
