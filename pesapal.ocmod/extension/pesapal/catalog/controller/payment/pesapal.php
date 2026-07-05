<?php
namespace Opencart\Catalog\Controller\Extension\Pesapal\Payment;

class Pesapal extends \Opencart\System\Engine\Controller {
	/** Renders the payment option inside OC4 checkout. */
	public function index(): string {
		$this->load->language('extension/pesapal/payment/pesapal');

		return $this->load->view('extension/pesapal/payment/pesapal', [
			'language'       => $this->config->get('config_language'),
			'text_title'     => $this->language->get('text_title'),
			'text_redirect'  => $this->language->get('text_redirect'),
			'button_confirm' => $this->language->get('button_confirm'),
		]);
	}

	/** AJAX — creates the order on Pesapal and returns the redirect URL. */
	public function confirm(): void {
		$this->load->language('extension/pesapal/payment/pesapal');
		$this->load->model('checkout/order');
		$this->load->model('extension/pesapal/payment/pesapal');

		$json = [];

		if (empty($this->session->data['order_id'])) {
			$json['redirect'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'), true);
			$this->sendJson($json);
			return;
		}

		$order_id   = (int)$this->session->data['order_id'];
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$json['redirect'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'), true);
			$this->sendJson($json);
			return;
		}

		try {
			$token  = $this->model_extension_pesapal_payment_pesapal->getToken();
			$ipn_id = $this->model_extension_pesapal_payment_pesapal->getIpnId($token);
			$result = $this->model_extension_pesapal_payment_pesapal->submitOrder($order_info, $token, $ipn_id);

			// Set pending status
			$pending_status_id = (int)$this->config->get('payment_pesapal_pending_status_id');
			$this->model_checkout_order->addHistory($order_id, $pending_status_id ?: 1, 'Customer redirected to Pesapal payment page.', false);

			$json['redirect'] = $result['redirect_url'];
		} catch (\Exception $e) {
			$json['error'] = $this->language->get('error_payment_failed');
		}

		$this->sendJson($json);
	}

	private function sendJson(array $data): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
}
