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

final class OpenAiStrategy extends AbstractStrategy
{
	protected function buildBody(string $content): array
	{
		$choices = [];

		foreach (self::CRITERIA as $value => $description) {
			$choices[] = [
				'value'       => $value,
				'description' => $description,
			];
		}

		return [
			'model' => $this->model,
			'input' => $content,
			'questions' => [
				[
					'name'         => 'classification',
					'type'         => 'choice',
					'instructions' => self::INSTRUCTIONS,
					'choices'      => $choices,
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

		foreach ($answers as $item) {
			if (is_array($item) && ($item['name'] ?? null) === 'classification') {
				return $this->createResult($item);
			}
		}

		throw new InvalidAnswerException('Classification answer not found');
	}

	protected function normalizeProbabilities(array $probabilities): array
	{
		$normalized = [];

		foreach ($probabilities as $item) {
			if (is_array($item) && isset($item['value'], $item['probability'])) {
				$normalized[(string) $item['value']] = $item['probability'];
			}
		}

		return $normalized;
	}
}
