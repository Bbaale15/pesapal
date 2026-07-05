<?php
namespace Pesapal;

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Auth.php';

class Order {
	public function __construct(
		private readonly Config $config,
		private readonly Logger $logger,
		private readonly Client $client
	) {}

	/**
	 * Register an IPN callback URL with PesaPal.
	 * Returns the ipn_id (notification_id) to use in order submissions.
	 */
	public function registerIpn(string $ipn_url, string $token): array {
		$this->logger->info('Registering IPN URL', ['url' => $ipn_url]);

		$response = $this->client->post('/api/URLSetup/RegisterIPN', [
			'url'                   => $ipn_url,
			'ipn_notification_type' => 'GET',
		], $token);

		if (empty($response['ipn_id'])) {
			$this->logger->error('IPN registration failed', ['response' => $response]);
			throw new \RuntimeException('PesaPal IPN registration failed: ' . ($response['error']['message'] ?? 'Unknown error'));
		}

		$this->logger->info('IPN registered', ['ipn_id' => $response['ipn_id']]);

		return $response;
	}

	/**
	 * Submit an order to PesaPal and retrieve the hosted payment redirect URL.
	 */
	public function submit(array $payload, string $token): array {
		$this->logger->info('Submitting order to PesaPal', [
			'id'       => $payload['id'] ?? '',
			'amount'   => $payload['amount'] ?? '',
			'currency' => $payload['currency'] ?? '',
		]);

		$response = $this->client->post('/api/Transactions/SubmitOrderRequest', $payload, $token);

		if (empty($response['order_tracking_id'])) {
			$this->logger->error('Order submission failed', ['response' => $response]);
			throw new \RuntimeException('PesaPal order submission failed: ' . ($response['error']['message'] ?? 'Unknown error'));
		}

		$this->logger->info('Order submitted', [
			'order_tracking_id'  => $response['order_tracking_id'],
			'merchant_reference' => $response['merchant_reference'] ?? '',
		]);

		return $response;
	}

	/**
	 * Query the current status of a transaction.
	 * Always call this to verify — never trust callback parameters directly.
	 */
	public function getStatus(string $order_tracking_id, string $token): array {
		$this->logger->info('Querying transaction status', ['order_tracking_id' => $order_tracking_id]);

		$response = $this->client->get('/api/Transactions/GetTransactionStatus', [
			'orderTrackingId' => $order_tracking_id,
		], $token);

		$this->logger->info('Transaction status received', [
			'order_tracking_id'          => $order_tracking_id,
			'payment_status_description' => $response['payment_status_description'] ?? '',
			'status_code'                => $response['status_code'] ?? '',
		]);

		return $response;
	}
}
