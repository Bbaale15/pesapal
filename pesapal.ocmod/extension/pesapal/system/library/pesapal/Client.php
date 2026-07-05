<?php
namespace Pesapal;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';

class Client {
	public function __construct(
		private readonly Config $config,
		private readonly Logger $logger
	) {}

	public function post(string $path, array $body, string $token = ''): array {
		$url = $this->config->getBaseUrl() . $path;

		$this->logger->info('POST ' . $path, ['body' => $body]);

		$response = $this->request('POST', $url, $body, $token);

		$this->logger->info('POST ' . $path . ' response', ['response' => $response]);

		return $response;
	}

	public function get(string $path, array $params = [], string $token = ''): array {
		$url = $this->config->getBaseUrl() . $path;

		if ($params) {
			$url .= '?' . http_build_query($params);
		}

		$this->logger->info('GET ' . $path, ['params' => $params]);

		$response = $this->request('GET', $url, [], $token);

		$this->logger->info('GET ' . $path . ' response', ['response' => $response]);

		return $response;
	}

	private function request(string $method, string $url, array $body, string $token): array {
		$headers = [
			'Accept: application/json',
			'Content-Type: application/json',
		];

		if ($token) {
			$headers[] = 'Authorization: Bearer ' . $token;
		}

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_USERAGENT      => 'PesapalOC4/1.0.0',
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}

		$result    = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error) {
			$this->logger->error('cURL error', ['url' => $url, 'error' => $curl_error]);
			throw new \RuntimeException('PesaPal API connection error: ' . $curl_error);
		}

		$decoded = json_decode($result, true);

		if (!is_array($decoded)) {
			$this->logger->error('Invalid API response', ['url' => $url, 'http_code' => $http_code, 'raw' => $result]);
			throw new \RuntimeException('PesaPal API returned invalid response (HTTP ' . $http_code . ')');
		}

		return $decoded;
	}
}
