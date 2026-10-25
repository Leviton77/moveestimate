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

    /** Starts an S3 multipart upload so large videos go up in parts that can be retried one by one. */
    public function create_multipart(string $key, string $content_type)
    {
        if (!$this->ready()) {
            return new WP_Error('tme_r2_unconfigured', __('R2 storage is not configured.', 'tom-moving-estimate'));
        }
        $response = wp_remote_request($this->presign('POST', $key, 300, array('uploads' => '')), array(
            'method'      => 'POST',
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => array('Content-Type' => $content_type),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $upload_id = self::xml_value((string) wp_remote_retrieve_body($response), 'UploadId');
        if ($code !== 200 || $upload_id === '') {
            return new WP_Error('tme_r2_multipart', sprintf(__('R2 returned HTTP %d while starting the upload.', 'tom-moving-estimate'), $code));
        }
        return $upload_id;
    }

    public function part_url(string $key, string $upload_id, int $part_number): string
    {
        return $this->presign('PUT', $key, 3600, array('partNumber' => (string) $part_number, 'uploadId' => $upload_id));
    }

    /** Part number => ETag for every part R2 has received. */
    public function list_parts(string $key, string $upload_id)
    {
        $response = wp_remote_request($this->presign('GET', $key, 300, array('uploadId' => $upload_id, 'max-parts' => '1000')), array(
            'method'      => 'GET',
            'timeout'     => 20,
            'redirection' => 0,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('tme_r2_parts', sprintf(__('R2 returned HTTP %d while checking the upload.', 'tom-moving-estimate'), $code));
        }
        return self::parse_parts((string) wp_remote_retrieve_body($response));
    }

    public function complete_multipart(string $key, string $upload_id, array $parts)
    {
        ksort($parts, SORT_NUMERIC);
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $number => $etag) {
            $xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars((string) $etag, ENT_XML1) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';
        $response = wp_remote_request($this->presign('POST', $key, 300, array('uploadId' => $upload_id)), array(
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 0,
            'headers'     => array('Content-Type' => 'application/xml'),
            'body'        => $xml,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        // S3 can answer 200 with an <Error> body when completing fails.
        if ($code !== 200 || str_contains($body, '<Error>')) {
            return new WP_Error('tme_r2_complete', sprintf(__('R2 returned HTTP %d while finishing the upload.', 'tom-moving-estimate'), $code));
        }
        return true;
    }

    public function abort_multipart(string $key, string $upload_id): void
    {
        wp_remote_request($this->presign('DELETE', $key, 300, array('uploadId' => $upload_id)), array(
            'method'      => 'DELETE',
            'timeout'     => 20,
            'redirection' => 0,
        ));
    }

    public static function parse_parts(string $xml): array
    {
        $parts = array();
        if (preg_match_all('#<Part>(.*?)</Part>#s', $xml, $matches)) {
            foreach ($matches[1] as $part) {
                $number = (int) self::xml_value($part, 'PartNumber');
                $etag = html_entity_decode(self::xml_value($part, 'ETag'), ENT_QUOTES | ENT_XML1);
                if ($number > 0 && $etag !== '') {
                    $parts[$number] = $etag;
                }
            }
        }
        ksort($parts, SORT_NUMERIC);
        return $parts;
    }

    private static function xml_value(string $xml, string $tag): string
    {
        return preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $xml, $match) ? trim($match[1]) : '';
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
