<?php
namespace Pesapal;

require_once __DIR__ . '/Client.php';

class Auth {
	public function __construct(
		private readonly Config $config,
		private readonly Logger $logger,
		private readonly Client $client
	) {}

	/**
	 * Request a new Bearer token from PesaPal.
	 * Returns ['token' => string, 'expiryDate' => string].
	 * Never store the token permanently — caller must cache in session only.
	 */
	public function requestToken(): array {
		$this->logger->info('Requesting authentication token', [
			'environment' => $this->config->getEnvironment(),
		]);

		$response = $this->client->post('/api/Auth/RequestToken', [
			'consumer_key'    => $this->config->getConsumerKey(),
			'consumer_secret' => $this->config->getConsumerSecret(),
		]);

		if (empty($response['token'])) {
			$this->logger->error('Token request failed', ['response' => $response]);
			throw new \RuntimeException('PesaPal authentication failed: ' . ($response['error']['message'] ?? 'Unknown error'));
		}

		$this->logger->info('Token obtained successfully');

		return [
			'token'      => $response['token'],
			'expiryDate' => $response['expiryDate'],
		];
	}
}
