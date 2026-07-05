<?php
namespace Pesapal;

class Logger {
	private const REDACTED_KEYS = ['consumer_secret', 'consumer_key', 'token', 'Authorization'];

	private bool $enabled;
	private string $log_file;

	public function __construct(bool $enabled, string $log_file) {
		$this->enabled  = $enabled;
		$this->log_file = $log_file;
	}

	public function info(string $message, array $context = []): void {
		$this->write('INFO', $message, $context);
	}

	public function error(string $message, array $context = []): void {
		$this->write('ERROR', $message, $context);
	}

	private function write(string $level, string $message, array $context): void {
		if (!$this->enabled) {
			return;
		}

		$entry = sprintf(
			'[%s] [%s] %s%s',
			date('Y-m-d H:i:s'),
			$level,
			$message,
			$context ? ' ' . json_encode($this->redact($context), JSON_UNESCAPED_SLASHES) : ''
		) . PHP_EOL;

		@error_log($entry, 3, $this->log_file);
	}

	private function redact(array $data): array {
		foreach ($data as $key => $value) {
			if (in_array($key, self::REDACTED_KEYS, true)) {
				$data[$key] = '***';
			} elseif (is_array($value)) {
				$data[$key] = $this->redact($value);
			}
		}
		return $data;
	}
}
