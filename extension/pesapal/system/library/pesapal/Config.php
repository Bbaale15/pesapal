<?php
namespace Pesapal;

class Config {
	private const SANDBOX_BASE = 'https://cybqa.pesapal.com/pesapalv3';
	private const LIVE_BASE    = 'https://pay.pesapal.com/v3';

	private string $consumer_key;
	private string $consumer_secret;
	private string $environment;

	public function __construct(string $consumer_key, string $consumer_secret, string $environment = 'sandbox') {
		$this->consumer_key    = $consumer_key;
		$this->consumer_secret = $consumer_secret;
		$this->environment     = $environment;
	}

	public function getBaseUrl(): string {
		return $this->environment === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
	}

	public function getConsumerKey(): string    { return $this->consumer_key; }
	public function getConsumerSecret(): string { return $this->consumer_secret; }
	public function getEnvironment(): string    { return $this->environment; }
	public function isLive(): bool              { return $this->environment === 'live'; }
}
