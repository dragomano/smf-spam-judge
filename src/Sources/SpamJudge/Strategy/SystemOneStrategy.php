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

if (! defined('SMF'))
	die('No direct access...');

final class SystemOneStrategy extends AbstractStrategy
{
	protected function buildBody(string $content): array
	{
		return [
			'model' => $this->model,
			'state' => [
				'content' => $content,
			],
			'questions' => [
				'classification' => [
					'type'         => 'choice',
					'instructions' => self::INSTRUCTIONS,
					'criteria'     => self::CRITERIA,
				],
			],
		];
	}

	protected function extractResult(array $data): Result
	{
		$answers = $data['answers'] ?? null;

		if (! is_array($answers)) {
			throw new InvalidResponseException('Missing answers');
		}

		$answer = $answers['classification'] ?? null;

		if (! is_array($answer)) {
			throw new InvalidAnswerException('Classification answer not found');
		}

		return $this->createResult($answer);
	}

	protected function normalizeProbabilities(array $probabilities): array
	{
		return $probabilities;
	}
}
