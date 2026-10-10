<?php

/**
 * @package Spam Judge
 * @author  Bugo
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/BSD-3-Clause BSD
 *
 * @version 0.2
 */

namespace Bugo\SpamJudge;

use Bugo\SpamJudge\Http\CurlInitException;
use Bugo\SpamJudge\Http\HttpClientException;
use Bugo\SpamJudge\Http\HttpStatusException;
use Bugo\SpamJudge\Http\InvalidJsonException;
use Bugo\SpamJudge\Strategy\InvalidAnswerException;
use Bugo\SpamJudge\Strategy\InvalidProbabilitiesException;
use Bugo\SpamJudge\Strategy\InvalidResponseException;
use Bugo\SpamJudge\Strategy\Result;
use Bugo\SpamJudge\Strategy\StrategyFactory;
use JsonException;

if (! defined('SMF'))
	die('No direct access...');

final readonly class Classifier
{
	public function __construct(
		private string $gateway,
		private string $apiKey,
		private ApiType $apiType,
		private string $model,
		private ErrorLogger $logError,
		private StrategyFactory $factory = new StrategyFactory(),
	) {}

	/**
	 * Expects the SpamJudge language to be loaded by the caller
	 */
	public function classify(string $content): ?Result
	{
		global $txt;

		try {
			return $this->factory
				->create($this->apiType, $this->gateway, $this->apiKey, $this->model)
				->classify($content);
		} catch (JsonException) {
			($this->logError)($txt['spam_judge_error_encode']);
		} catch (CurlInitException) {
			($this->logError)($txt['spam_judge_error_curl_init']);
		} catch (HttpStatusException $e) {
			($this->logError)(sprintf($txt['spam_judge_error_http'], $e->status));
		} catch (InvalidJsonException) {
			($this->logError)($txt['spam_judge_error_invalid_json']);
		} catch (HttpClientException $e) {
			($this->logError)(sprintf($txt['spam_judge_error_request'], $e->getMessage()));
		} catch (InvalidAnswerException) {
			($this->logError)($txt['spam_judge_error_invalid_answer']);
		} catch (InvalidProbabilitiesException) {
			($this->logError)($txt['spam_judge_error_invalid_probabilities']);
		} catch (InvalidResponseException) {
			($this->logError)($txt['spam_judge_error_invalid_response']);
		}

		return null;
	}
}
