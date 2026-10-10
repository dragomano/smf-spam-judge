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

interface StrategyInterface
{
	public function classify(string $content): Result;
}
