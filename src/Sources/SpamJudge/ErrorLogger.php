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

final readonly class ErrorLogger
{
	public function __construct(private string $name) {}

	public function __invoke(string $message): void
	{
		log_error($this->name . ': ' . $message);
	}
}
