<?php

/**
 * @package Spam Judge
 * @author  Bugo
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/BSD-3-Clause BSD
 *
 * @version 0.2
 */

namespace Bugo\SpamJudge\Http;

if (! defined('SMF'))
	die('No direct access...');

final class HttpStatusException extends HttpClientException
{
	public function __construct(public readonly int $status)
	{
		parent::__construct((string) $status);
	}
}
