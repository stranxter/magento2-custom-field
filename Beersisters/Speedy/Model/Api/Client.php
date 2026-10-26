<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Minimal client for Speedy's REST API (https://api.speedy.bg/web-api.html).
 *
 * Every request is a JSON POST carrying userName/password in the body. Speedy gives API users full rights
 * (shipments, pickups and other billable calls), so this client refuses anything outside /location/. A later
 * waybill feature has to widen ALLOWED_PATH deliberately, for exactly the endpoints it needs.
 */
class Client
{
    private const BASE_URL = 'https://api.speedy.bg/v1';
    /** `D`: `$` must not match before a trailing newline. */
    private const ALLOWED_PATH = '#^/location/[a-z0-9/_-]*$#D';
    private const TIMEOUT = 60;

    private const XML_USERNAME = 'carriers/bsspeedy/api_username';
    private const XML_PASSWORD = 'carriers/bsspeedy/api_password';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    public function __construct(ScopeConfigInterface $scopeConfig, CurlFactory $curlFactory)
    {
        $this->scopeConfig = $scopeConfig;
        $this->curlFactory = $curlFactory;
    }

    /**
     * POSTs to a /location/ endpoint and returns the decoded JSON response.
     *
     * @param array{0: string, 1: string}|null $credentials [username, password] to use instead of the saved ones
     * @throws ApiException
     */
    public function requestJson(string $path, array $params = [], ?array $credentials = null): array
    {
        $body = $this->post($path, $params, $credentials);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ApiException(sprintf('Speedy %s: response is not JSON', $path));
        }
        $this->throwOnError($path, $data);

        return $data;
    }

    /**
     * POSTs to a /location/.../csv/... endpoint and returns the CSV text. Speedy reports errors (e.g. a
     * missing license) as a JSON body with HTTP 200, so a body starting with "{" is treated as an error.
     *
     * @throws ApiException
     */
    public function requestCsv(string $path, array $params = []): string
    {
        $body = $this->post($path, $params, null);
        if (str_starts_with(ltrim($body), '{')) {
            $data = json_decode($body, true);
            $this->throwOnError($path, is_array($data) ? $data : []);
            throw new ApiException(sprintf('Speedy %s: expected CSV, got JSON', $path));
        }

        return $body;
    }

    /**
     * @throws ApiException
     */
    private function post(string $path, array $params, ?array $credentials): string
    {
        if (!preg_match(self::ALLOWED_PATH, $path)) {
            throw new ApiException(sprintf('Speedy path not allowed: %s', $path));
        }

        if ($credentials !== null) {
            [$username, $password] = array_map('strval', $credentials);
        } else {
            $username = (string)$this->scopeConfig->getValue(self::XML_USERNAME);
            // Already decrypted: config.xml declares the Encrypted backend model, so the config reader decrypts it.
            $password = (string)$this->scopeConfig->getValue(self::XML_PASSWORD);
        }
        if ($username === '' || $password === '') {
            throw new ApiException('Speedy API credentials are not configured');
        }

        $payload = json_encode(
            ['userName' => $username, 'password' => $password, 'language' => 'BG'] + $params,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->addHeader('Content-Type', 'application/json; charset=utf-8');
        try {
            $curl->post(self::BASE_URL . $path, $payload);
        } catch (\Exception $e) {
            throw new ApiException(sprintf('Speedy %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if ($curl->getStatus() !== 200) {
            throw new ApiException(sprintf('Speedy %s: HTTP %d', $path, $curl->getStatus()));
        }

        return $curl->getBody();
    }

    /**
     * @throws ApiException
     */
    private function throwOnError(string $path, array $data): void
    {
        if (!empty($data['error'])) {
            $message = is_array($data['error']) ? (string)($data['error']['message'] ?? 'unknown error') : 'unknown error';
            throw new ApiException(sprintf('Speedy %s: %s', $path, mb_substr($message, 0, 300)));
        }
    }
}
