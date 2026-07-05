<?php
namespace Opencart\Catalog\Controller\Extension\Pesapal\Payment;

/**
 * Callback controller — route: extension/pesapal/payment/callback
 *
 * Pesapal redirects the customer here after a payment attempt.
 * GET params: OrderTrackingId, OrderMerchantReference, OrderNotificationType
 */
class Callback extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/pesapal/payment/pesapal');
		$this->load->model('checkout/order');
		$this->load->model('extension/pesapal/payment/pesapal');

		$order_tracking_id = $this->request->get['OrderTrackingId'] ?? '';

		if (!$order_tracking_id) {
			$this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));
			return;
		}

		try {
			// Always verify with the API — never trust GET parameters
			$token       = $this->model_extension_pesapal_payment_pesapal->getToken();
			$status_info = $this->model_extension_pesapal_payment_pesapal->verifyTransaction($order_tracking_id, $token);
			$transaction = $this->model_extension_pesapal_payment_pesapal->getTransactionByTrackingId($order_tracking_id);

			if (!$transaction) {
				$this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));
				return;
			}

			$order_id       = (int)$transaction['order_id'];
			$pesapal_status = strtoupper($status_info['payment_status_description'] ?? 'INVALID');
			$payment_method = $status_info['payment_method'] ?? '';

			// Idempotent update — returns false if already at this status
			$updated = $this->model_extension_pesapal_payment_pesapal->updateTransactionStatus(
				$order_tracking_id,
				$pesapal_status,
				$payment_method
			);

			if ($updated) {
				$oc_status_id = $this->model_extension_pesapal_payment_pesapal->mapStatusToOrderStatusId($pesapal_status);
				$notify       = ($pesapal_status === 'COMPLETED');
				$comment      = sprintf(
					'Pesapal: %s | Tracking ID: %s | Method: %s',
					$pesapal_status,
					$order_tracking_id,
					$payment_method
				);
				$this->model_checkout_order->addHistory($order_id, $oc_status_id, $comment, $notify);
			}

			if ($pesapal_status === 'COMPLETED') {
				unset($this->session->data['order_id']);
				$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true));
			} else {
				$this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));
			}
		} catch (\Throwable $e) {
			$this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));
		}
	}
}
