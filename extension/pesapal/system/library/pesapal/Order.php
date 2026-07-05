<?php
namespace Pesapal;

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Auth.php';

class Order {
	private Config $config;
	private Logger $logger;
	private Client $client;

	public function __construct(Config $config, Logger $logger, Client $client) {
		$this->config = $config;
		$this->logger = $logger;
		$this->client = $client;
	}

	/**
	 * Register an IPN callback URL with Pesapal.
	 * Returns the full response including ipn_id.
	 */
	public function registerIpn(string $ipn_url, string $token): array {
		$this->logger->info('Registering IPN URL', ['url' => $ipn_url]);

		$response = $this->client->post('/api/URLSetup/RegisterIPN', [
			'url'                   => $ipn_url,
			'ipn_notification_type' => 'GET',
		], $token);

		if (empty($response['ipn_id'])) {
			$this->logger->error('IPN registration failed', ['response' => $response]);
			throw new \RuntimeException('Pesapal IPN registration failed: ' . ($response['error']['message'] ?? 'Unknown error'));
		}

		$this->logger->info('IPN registered', ['ipn_id' => $response['ipn_id']]);

		return $response;
	}

	/**
	 * Submit an order to Pesapal and retrieve the hosted payment redirect URL.
	 */
	public function submit(array $payload, string $token): array {
		$this->logger->info('Submitting order', [
			'id'       => $payload['id'] ?? '',
			'amount'   => $payload['amount'] ?? '',
			'currency' => $payload['currency'] ?? '',
		]);

		$response = $this->client->post('/api/Transactions/SubmitOrderRequest', $payload, $token);

		if (empty($response['order_tracking_id'])) {
			$this->logger->error('Order submission failed', ['response' => $response]);
			throw new \RuntimeException('Pesapal order submission failed: ' . ($response['error']['message'] ?? 'Unknown error'));
		}

		$this->logger->info('Order submitted', [
			'order_tracking_id' => $response['order_tracking_id'],
		]);

		return $response;
	}

	/**
	 * Query the current status of a transaction.
	 * Always call this to verify — never trust callback/IPN parameters directly.
	 */
	public function getStatus(string $order_tracking_id, string $token): array {
		$this->logger->info('Querying transaction status', ['order_tracking_id' => $order_tracking_id]);

		$response = $this->client->get('/api/Transactions/GetTransactionStatus', [
			'orderTrackingId' => $order_tracking_id,
		], $token);

		$this->logger->info('Status received', [
			'order_tracking_id' => $order_tracking_id,
			'status'            => $response['payment_status_description'] ?? '',
		]);

		return $response;
	}
}
