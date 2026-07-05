<?php
namespace Opencart\Admin\Model\Extension\Pesapal\Payment;

class Pesapal extends \Opencart\System\Engine\Model {
	public function install(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "pesapal_transaction` (
				`transaction_id`    INT(11)       NOT NULL AUTO_INCREMENT,
				`order_id`          INT(11)       NOT NULL,
				`merchant_reference` VARCHAR(255) NOT NULL DEFAULT '',
				`order_tracking_id` VARCHAR(255)  NOT NULL DEFAULT '',
				`amount`            DECIMAL(15,2) NOT NULL DEFAULT '0.00',
				`currency`          VARCHAR(10)   NOT NULL DEFAULT '',
				`payment_status`    VARCHAR(50)   NOT NULL DEFAULT 'PENDING',
				`payment_method`    VARCHAR(100)  NOT NULL DEFAULT '',
				`created_at`        DATETIME      NOT NULL,
				`updated_at`        DATETIME      NOT NULL,
				PRIMARY KEY (`transaction_id`),
				KEY `order_id` (`order_id`),
				KEY `order_tracking_id` (`order_tracking_id`),
				KEY `merchant_reference` (`merchant_reference`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
		");
	}

	public function uninstall(): void {
		// Keep transaction table by default to preserve payment records
	}
}
