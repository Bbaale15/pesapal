<?php
namespace Pesapal;

class Config {
	private const SANDBOX_BASE = 'https://cybqa.pesapal.com/pesapalv3';
	private const LIVE_BASE    = 'https://pay.pesapal.com/v3';

	public function __construct(
		private readonly string $consumer_key,
		private readonly string $consumer_secret,
		private readonly string $environment = 'sandbox'
	) {}

	public function getBaseUrl(): string {
		return $this->environment === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
	}

	public function getConsumerKey(): string    { return $this->consumer_key; }
	public function getConsumerSecret(): string { return $this->consumer_secret; }
	public function getEnvironment(): string    { return $this->environment; }
	public function isLive(): bool              { return $this->environment === 'live'; }
}
