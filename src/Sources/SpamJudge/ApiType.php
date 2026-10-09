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

if (! defined('SMF'))
	die('No direct access...');

enum ApiType: string
{
	case SystemOne = 'system_one';
	case OpenAi    = 'openai';

	public function label(): string
	{
		return match ($this) {
			self::SystemOne => 'TypeSafe System One',
			self::OpenAi    => 'OpenAI Decisions',
		};
	}

	public function defaultModel(): string
	{
		return match ($this) {
			self::SystemOne => 'jev-latest',
			self::OpenAi    => 'gpt-6-luna',
		};
	}

	public static function options(): array
	{
		$options = [];

		foreach (self::cases() as $case) {
			$options[$case->value] = $case->label();
		}

		return $options;
	}
}
