<?php
namespace Opencart\Catalog\Model\Extension\Pesapal\Payment;

class Pesapal extends \Opencart\System\Engine\Model {
	private ?\Pesapal\Config  $cfg         = null;
	private ?\Pesapal\Logger  $log         = null;
	private ?\Pesapal\Client  $http        = null;
	private ?\Pesapal\Auth    $auth        = null;
	private ?\Pesapal\Order   $order_api   = null;

	// ------------------------------------------------------------------
	// OC4 Payment Model API
	// ------------------------------------------------------------------

	public function getMethods(array $address): array {
		$this->load->language('extension/pesapal/payment/pesapal');

		if (!$this->config->get('config_checkout_payment_address')) {
			$status = true;
		} elseif (!$this->config->get('payment_pesapal_geo_zone_id')) {
			$status = true;
		} else {
			$query = $this->db->query(
				"SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone`
				 WHERE `geo_zone_id` = '" . (int)$this->config->get('payment_pesapal_geo_zone_id') . "'
				   AND `country_id`  = '" . (int)$address['country_id'] . "'
				   AND (`zone_id`    = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')"
			);
			$status = (bool)$query->num_rows;
		}

		if (!$status) {
			return [];
		}

		return [
			'code'       => 'pesapal',
			'name'       => $this->language->get('text_title'),
			'option'     => [
				'pesapal' => [
					'code' => 'pesapal.pesapal',
					'name' => $this->language->get('text_title'),
				],
			],
			'sort_order' => $this->config->get('payment_pesapal_sort_order'),
		];
	}

	// ------------------------------------------------------------------
	// Token management (session-cached, never stored permanently)
	// ------------------------------------------------------------------

	public function getToken(): string {
		$cached_token  = $this->session->data['pesapal_token'] ?? '';
		$cached_expiry = $this->session->data['pesapal_token_expiry'] ?? '';

		if ($cached_token && $cached_expiry && strtotime($cached_expiry) > time() + 30) {
			return $cached_token;
		}

		$result = $this->getAuth()->requestToken();

		$this->session->data['pesapal_token']        = $result['token'];
		$this->session->data['pesapal_token_expiry'] = $result['expiryDate'];

		return $result['token'];
	}

	// ------------------------------------------------------------------
	// IPN registration (lazy — registered on first checkout)
	// ------------------------------------------------------------------

	public function getIpnId(string $token): string {
		$ipn_id = $this->getSetting('payment_pesapal_ipn_id');

		if ($ipn_id) {
			return $ipn_id;
		}

		$ipn_url  = $this->getStoreUrl() . 'index.php?route=extension/pesapal/payment/ipn';
		$response = $this->getOrderApi()->registerIpn($ipn_url, $token);

		$this->saveSetting('payment_pesapal_ipn_id', $response['ipn_id']);

		return $response['ipn_id'];
	}

	private function getStoreUrl(): string {
		if (defined('HTTPS_SERVER')) {
			return \HTTPS_SERVER;
		}
		if (defined('HTTP_SERVER')) {
			return \HTTP_SERVER;
		}
		return $this->config->get('config_url');
	}

	// ------------------------------------------------------------------
	// Order submission
	// ------------------------------------------------------------------

	public function submitOrder(array $order_info, string $token, string $ipn_id): array {
		$merchant_reference = 'OC-' . $order_info['order_id'] . '-' . time();
		$callback_url       = $this->getStoreUrl() . 'index.php?route=extension/pesapal/payment/callback';

		$payload = [
			'id'              => $merchant_reference,
			'currency'        => $order_info['currency_code'],
			'amount'          => round((float)$order_info['total'], 2),
			'description'     => 'Order #' . $order_info['order_id'],
			'callback_url'    => $callback_url,
			'notification_id' => $ipn_id,
			'billing_address' => [
				'email_address' => $order_info['email'],
				'phone_number'  => $order_info['telephone'] ?: '',
				'country_code'  => ($order_info['payment_iso_code_2'] ?? '') ?: ($order_info['shipping_iso_code_2'] ?? 'UG'),
				'first_name'    => ($order_info['payment_firstname'] ?? '') ?: $order_info['firstname'],
				'last_name'     => ($order_info['payment_lastname'] ?? '') ?: $order_info['lastname'],
				'line_1'        => $order_info['payment_address_1'] ?? '',
				'city'          => $order_info['payment_city'] ?? '',
				'state'         => $order_info['payment_zone'] ?? '',
				'postal_code'   => $order_info['payment_postcode'] ?? '',
				'zip_code'      => $order_info['payment_postcode'] ?? '',
			],
		];

		$response = $this->getOrderApi()->submit($payload, $token);

		$this->db->query("
			INSERT INTO `" . DB_PREFIX . "pesapal_transaction`
			SET `order_id`           = '" . (int)$order_info['order_id'] . "',
			    `merchant_reference` = '" . $this->db->escape($merchant_reference) . "',
			    `order_tracking_id`  = '" . $this->db->escape($response['order_tracking_id']) . "',
			    `amount`             = '" . (float)$order_info['total'] . "',
			    `currency`           = '" . $this->db->escape($order_info['currency_code']) . "',
			    `payment_status`     = 'PENDING',
			    `created_at`         = NOW(),
			    `updated_at`         = NOW()
		");

		return $response;
	}

	// ------------------------------------------------------------------
	// Status verification — always call the API, never trust parameters
	// ------------------------------------------------------------------

	public function verifyTransaction(string $order_tracking_id, string $token): array {
		return $this->getOrderApi()->getStatus($order_tracking_id, $token);
	}

	// ------------------------------------------------------------------
	// Transaction DB helpers
	// ------------------------------------------------------------------

	public function getTransactionByTrackingId(string $order_tracking_id): array {
		$query = $this->db->query(
			"SELECT * FROM `" . DB_PREFIX . "pesapal_transaction`
			 WHERE `order_tracking_id` = '" . $this->db->escape($order_tracking_id) . "'
			 LIMIT 1"
		);
		return $query->row ?? [];
	}

	/**
	 * Updates the transaction status only if the new status differs (idempotent).
	 * Returns true if an update was performed, false if already at that status.
	 */
	public function updateTransactionStatus(string $order_tracking_id, string $new_status, string $payment_method = ''): bool {
		$transaction = $this->getTransactionByTrackingId($order_tracking_id);

		if (!$transaction) {
			return false;
		}

		if ($transaction['payment_status'] === $new_status) {
			return false; // already at this status — prevent duplicate updates
		}

		$this->db->query("
			UPDATE `" . DB_PREFIX . "pesapal_transaction`
			SET `payment_status`  = '" . $this->db->escape($new_status) . "',
			    `payment_method`  = '" . $this->db->escape($payment_method) . "',
			    `updated_at`      = NOW()
			WHERE `order_tracking_id` = '" . $this->db->escape($order_tracking_id) . "'
		");

		return true;
	}

	/**
	 * Maps a Pesapal status description to the configured OpenCart order status ID.
	 */
	public function mapStatusToOrderStatusId(string $pesapal_status): int {
		switch (strtoupper($pesapal_status)) {
			case 'COMPLETED':
				return (int)$this->config->get('payment_pesapal_processing_status_id');
			case 'FAILED':
			case 'INVALID':
				return (int)$this->config->get('payment_pesapal_failed_status_id');
			case 'CANCELLED':
				return (int)$this->config->get('payment_pesapal_cancelled_status_id');
			default:
				return (int)$this->config->get('payment_pesapal_pending_status_id');
		}
	}

	// ------------------------------------------------------------------
	// Library object initialisation (lazy, using dependency injection)
	// ------------------------------------------------------------------

	private function getConfig(): \Pesapal\Config {
		if (!$this->cfg) {
			require_once \DIR_EXTENSION . 'pesapal/system/library/pesapal/Config.php';
			$this->cfg = new \Pesapal\Config(
				(string)$this->config->get('payment_pesapal_consumer_key'),
				(string)$this->config->get('payment_pesapal_consumer_secret'),
				(string)($this->config->get('payment_pesapal_environment') ?: 'sandbox')
			);
		}
		return $this->cfg;
	}

	private function getLogger(): \Pesapal\Logger {
		if (!$this->log) {
			require_once \DIR_EXTENSION . 'pesapal/system/library/pesapal/Logger.php';
			$this->log = new \Pesapal\Logger(
				(bool)$this->config->get('payment_pesapal_debug'),
				\DIR_STORAGE . 'logs/pesapal.log'
			);
		}
		return $this->log;
	}

	private function getClient(): \Pesapal\Client {
		if (!$this->http) {
			require_once \DIR_EXTENSION . 'pesapal/system/library/pesapal/Client.php';
			$this->http = new \Pesapal\Client($this->getConfig(), $this->getLogger());
		}
		return $this->http;
	}

	private function getAuth(): \Pesapal\Auth {
		if (!$this->auth) {
			require_once \DIR_EXTENSION . 'pesapal/system/library/pesapal/Auth.php';
			$this->auth = new \Pesapal\Auth($this->getConfig(), $this->getLogger(), $this->getClient());
		}
		return $this->auth;
	}

	private function getOrderApi(): \Pesapal\Order {
		if (!$this->order_api) {
			require_once \DIR_EXTENSION . 'pesapal/system/library/pesapal/Order.php';
			$this->order_api = new \Pesapal\Order($this->getConfig(), $this->getLogger(), $this->getClient());
		}
		return $this->order_api;
	}

	// ------------------------------------------------------------------
	// Settings helpers (direct DB — works in catalog context)
	// ------------------------------------------------------------------

	private function getSetting(string $key): string {
		$query = $this->db->query(
			"SELECT `value` FROM `" . DB_PREFIX . "setting`
			 WHERE `code` = 'payment_pesapal' AND `key` = '" . $this->db->escape($key) . "' LIMIT 1"
		);
		return $query->row['value'] ?? '';
	}

	private function saveSetting(string $key, string $value): void {
		$exists = $this->db->query(
			"SELECT `setting_id` FROM `" . DB_PREFIX . "setting`
			 WHERE `code` = 'payment_pesapal' AND `key` = '" . $this->db->escape($key) . "' LIMIT 1"
		);

		if ($exists->num_rows) {
			$this->db->query(
				"UPDATE `" . DB_PREFIX . "setting`
				 SET `value` = '" . $this->db->escape($value) . "'
				 WHERE `code` = 'payment_pesapal' AND `key` = '" . $this->db->escape($key) . "'"
			);
		} else {
			$this->db->query(
				"INSERT INTO `" . DB_PREFIX . "setting` (`store_id`, `code`, `key`, `value`, `serialized`)
				 VALUES (0, 'payment_pesapal', '" . $this->db->escape($key) . "', '" . $this->db->escape($value) . "', 0)"
			);
		}
	}
}
