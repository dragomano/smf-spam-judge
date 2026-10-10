<?php

/**
 * @package Spam Judge
 * @author  Bugo
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/BSD-3-Clause BSD
 *
 * @version 0.2
 */

namespace Bugo\SpamJudge\Strategy;

use Bugo\SpamJudge\ApiType;
use Bugo\SpamJudge\Http\HttpClient;

if (! defined('SMF'))
	die('No direct access...');

final readonly class StrategyFactory
{
	public function __construct(private HttpClient $httpClient = new HttpClient()) {}

	public function create(ApiType $apiType, string $gateway, string $apiKey, string $model): StrategyInterface
	{
		return match ($apiType) {
			ApiType::OpenAi    => new OpenAiStrategy($this->httpClient, $gateway, $apiKey, $model),
			ApiType::SystemOne => new SystemOneStrategy($this->httpClient, $gateway, $apiKey, $model),
		};
	}
}
