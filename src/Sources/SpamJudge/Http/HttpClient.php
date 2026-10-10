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

final class HttpClient
{
	/**
	 * @throws JsonException|HttpClientException
	 */
	public function post(string $url, string $apiKey, array $payload): array
	{
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$ch   = curl_init($url);

		if ($ch === false) {
			throw new CurlInitException();
		}

		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
			CURLOPT_POSTFIELDS => $body,
		]);

		$response = curl_exec($ch);
		$error    = curl_error($ch);
		$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($response === false) {
			throw new HttpClientException($error);
		}

		if ($status < 200 || $status >= 300) {
			throw new HttpStatusException($status);
		}

		try {
			$data = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidJsonException($e);
		}

		if (! is_array($data)) {
			throw new InvalidJsonException(new JsonException('Decoded JSON is not an array'));
		}

		return $data;
	}
}
