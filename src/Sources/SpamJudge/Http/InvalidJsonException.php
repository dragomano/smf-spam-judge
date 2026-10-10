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

use JsonException;

if (! defined('SMF'))
	die('No direct access...');

final class InvalidJsonException extends HttpClientException
{
	public function __construct(JsonException $previous)
	{
		parent::__construct($previous->getMessage(), 0, $previous);
	}
}
