<?php
namespace Opencart\Catalog\Controller\Extension\Pesapal\Payment;

/**
 * IPN controller — route: extension/pesapal/payment/ipn
 *
 * Pesapal sends a server-to-server GET request here on every status change.
 * Must be idempotent — repeated notifications must not create duplicate history entries.
 * GET params: OrderTrackingId, OrderNotificationType, OrderMerchantReference
 */
class Ipn extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->model('checkout/order');
		$this->load->model('extension/pesapal/payment/pesapal');

		$order_tracking_id = $this->request->get['OrderTrackingId'] ?? '';

		if (!$order_tracking_id) {
			$this->sendJson(['status' => 'error', 'message' => 'Missing OrderTrackingId']);
			return;
		}

		try {
			// Always verify with the API — never trust IPN parameters directly
			$token       = $this->model_extension_pesapal_payment_pesapal->getToken();
			$status_info = $this->model_extension_pesapal_payment_pesapal->verifyTransaction($order_tracking_id, $token);
			$transaction = $this->model_extension_pesapal_payment_pesapal->getTransactionByTrackingId($order_tracking_id);

			if (!$transaction) {
				$this->sendJson(['status' => 'error', 'message' => 'Transaction not found']);
				return;
			}

			$order_id       = (int)$transaction['order_id'];
			$pesapal_status = strtoupper($status_info['payment_status_description'] ?? 'INVALID');
			$payment_method = $status_info['payment_method'] ?? '';

			// updateTransactionStatus returns false when status already matches — prevents duplicate history
			$updated = $this->model_extension_pesapal_payment_pesapal->updateTransactionStatus(
				$order_tracking_id,
				$pesapal_status,
				$payment_method
			);

			if ($updated) {
				$oc_status_id = $this->model_extension_pesapal_payment_pesapal->mapStatusToOrderStatusId($pesapal_status);
				$notify       = ($pesapal_status === 'COMPLETED');
				$comment      = sprintf(
					'Pesapal IPN: %s | Tracking ID: %s | Method: %s',
					$pesapal_status,
					$order_tracking_id,
					$payment_method
				);
				$this->model_checkout_order->addHistory($order_id, $oc_status_id, $comment, $notify);
			}

			$this->sendJson([
				'status'            => 'ok',
				'orderTrackingId'   => $order_tracking_id,
				'pesapalStatus'     => $pesapal_status,
				'historyUpdated'    => $updated,
			]);
		} catch (\Throwable $e) {
			// Always return 200 so Pesapal does not keep retrying for server errors
			$this->sendJson(['status' => 'error', 'message' => $e->getMessage()]);
		}
	}

	private function sendJson(array $data): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
}
