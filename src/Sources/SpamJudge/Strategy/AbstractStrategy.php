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

use Bugo\SpamJudge\Http\HttpClient;
use JsonException;

if (! defined('SMF'))
	die('No direct access...');

abstract class AbstractStrategy implements StrategyInterface
{
	protected const INSTRUCTIONS = 'Classify the supplied forum content for anti-spam moderation. '
		. 'Choose spam only for clearly unsolicited or deceptive promotional content. '
		. 'Choose ham for legitimate user content. '
		. 'Choose review when the content is ambiguous or there is not enough evidence.';

	protected const CRITERIA = [
		'ham'    => 'Legitimate forum content, including ordinary discussion, questions, and relevant links.',
		'spam'   => 'Unsolicited or deceptive promotion, link farming, or automated spam.',
		'review' => 'Ambiguous content or insufficient evidence to confidently classify as ham or spam.',
	];

	private const MAX_CONTENT_LENGTH = 10000;

	private const VERDICTS = ['ham', 'spam', 'review'];

	public function __construct(
		private readonly HttpClient $httpClient,
		private readonly string $gateway,
		private readonly string $apiKey,
		protected readonly string $model,
	) {}

	/**
	 * @throws JsonException
	 */
	final public function classify(string $content): Result
	{
		$data = $this->httpClient->post(
			$this->gateway,
			$this->apiKey,
			$this->buildBody(mb_substr($content, 0, self::MAX_CONTENT_LENGTH)),
		);

		return $this->extractResult($data);
	}

	abstract protected function buildBody(string $content): array;

	abstract protected function extractResult(array $data): Result;

	abstract protected function normalizeProbabilities(array $probabilities): array;

	protected function createResult(array $answer): Result
	{
		$choice = $answer['choice'] ?? null;

		if (
			($answer['type'] ?? null) !== 'choice'
			|| ! in_array($choice, self::VERDICTS, true)
		) {
			throw new InvalidAnswerException('Unexpected classification answer');
		}

		$raw = $answer['probabilities'] ?? null;

		if (! is_array($raw) || $raw === []) {
			throw new InvalidProbabilitiesException('Missing probabilities');
		}

		$probabilities = $this->normalizeProbabilities($raw);

		foreach (self::VERDICTS as $option) {
			if (
				! isset($probabilities[$option])
				|| ! is_numeric($probabilities[$option])
				|| ! is_finite((float) $probabilities[$option])
				|| (float) $probabilities[$option] < 0
				|| (float) $probabilities[$option] > 1
			) {
				throw new InvalidProbabilitiesException('Invalid probability for ' . $option);
			}

			$probabilities[$option] = (float) $probabilities[$option];
		}

		return new Result($choice, $probabilities);
	}
}
