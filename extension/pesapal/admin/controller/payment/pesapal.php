<?php
namespace Opencart\Admin\Controller\Extension\Pesapal\Payment;

class Pesapal extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/pesapal/payment/pesapal');
		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment'),
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/pesapal/payment/pesapal', 'user_token=' . $this->session->data['user_token']),
		];

		$data['save'] = $this->url->link('extension/pesapal/payment/pesapal.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$settings = [
			'payment_pesapal_consumer_key'        => '',
			'payment_pesapal_consumer_secret'     => '',
			'payment_pesapal_environment'         => 'sandbox',
			'payment_pesapal_status'              => 0,
			'payment_pesapal_debug'               => 0,
			'payment_pesapal_pending_status_id'   => 1,
			'payment_pesapal_processing_status_id' => 2,
			'payment_pesapal_failed_status_id'    => 10,
			'payment_pesapal_cancelled_status_id' => 7,
			'payment_pesapal_geo_zone_id'         => 0,
			'payment_pesapal_sort_order'          => 0,
		];

		foreach ($settings as $key => $default) {
			$data[$key] = $this->config->get($key) ?? $default;
		}

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		if (defined('HTTPS_CATALOG')) {
			$catalog = HTTPS_CATALOG;
		} elseif (defined('HTTP_CATALOG')) {
			$catalog = HTTP_CATALOG;
		} else {
			$catalog = '';
		}

		$data['ipn_url']      = $catalog . 'index.php?route=extension/pesapal/payment/ipn';
		$data['callback_url'] = $catalog . 'index.php?route=extension/pesapal/payment/callback';

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/pesapal/payment/pesapal', $data));
	}

	public function save(): void {
		$this->load->language('extension/pesapal/payment/pesapal');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/pesapal/payment/pesapal')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_pesapal', $this->request->post);

			// Attempt IPN re-registration whenever settings are saved (in case environment changed)
			$ipn_result = $this->registerIpn();
			if ($ipn_result) {
				$this->model_setting_setting->editSetting('payment_pesapal', [
					'payment_pesapal_ipn_id' => $ipn_result,
				] + $this->request->post);
			}

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		if ($this->user->hasPermission('modify', 'extension/payment')) {
			$this->load->model('extension/pesapal/payment/pesapal');
			$this->model_extension_pesapal_payment_pesapal->install();
		}
	}

	public function uninstall(): void {
		if ($this->user->hasPermission('modify', 'extension/payment')) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->deleteSetting('payment_pesapal');

			$this->load->model('extension/pesapal/payment/pesapal');
			$this->model_extension_pesapal_payment_pesapal->uninstall();
		}
	}

	/**
	 * Attempts IPN registration using current (just-saved) POST credentials.
	 * Returns the ipn_id on success, empty string on failure.
	 */
	private function registerIpn(): string {
		$consumer_key    = $this->request->post['payment_pesapal_consumer_key'] ?? '';
		$consumer_secret = $this->request->post['payment_pesapal_consumer_secret'] ?? '';
		$environment     = $this->request->post['payment_pesapal_environment'] ?? 'sandbox';

		if (!$consumer_key || !$consumer_secret) {
			return '';
		}

		try {
			require_once \DIR_EXTENSION . 'pesapal/system/library/pesapal/Order.php';

			$config = new \Pesapal\Config($consumer_key, $consumer_secret, $environment);
			$logger = new \Pesapal\Logger(false, ''); // logging off during admin save
			$client = new \Pesapal\Client($config, $logger);
			$auth   = new \Pesapal\Auth($config, $logger, $client);
			$order  = new \Pesapal\Order($config, $logger, $client);

			$token_data = $auth->requestToken();
			$token      = $token_data['token'];

			if (defined('HTTPS_CATALOG')) {
				$catalog = HTTPS_CATALOG;
			} elseif (defined('HTTP_CATALOG')) {
				$catalog = HTTP_CATALOG;
			} else {
				return '';
			}

			$ipn_url  = $catalog . 'index.php?route=extension/pesapal/payment/ipn';
			$response = $order->registerIpn($ipn_url, $token);

			return $response['ipn_id'] ?? '';
		} catch (\Exception $e) {
			return '';
		}
	}
}
