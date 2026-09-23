# Spam Judge

[![SMF 2.1](https://img.shields.io/badge/SMF-2.1-ed6033.svg?style=flat)](https://github.com/SimpleMachines/SMF2.1)
![License](https://img.shields.io/github/license/dragomano/smf-spam-judge)
![Hooks only: Yes](https://img.shields.io/badge/Hooks%20only-YES-blue)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/smf-spam-judge/badge.svg?branch=main)](https://coveralls.io/github/dragomano/smf-spam-judge?branch=main)

**English** | [Русский](README.ru.md)

Spam Judge is a modification for [Simple Machines Forum (SMF) 2.1](https://www.simplemachines.org/). It classifies new registrations and first posts through a configurable AI API gateway and can automatically restrict accounts or unapprove suspicious messages.

The gateway and model are not hard-coded. You can use a compatible endpoint provided by Vercel AI Gateway, OpenRouter, Cloudflare Workers AI, or another service that implements the response format described below.

## Features

- Classifies registration data and first posts as `ham`, `spam`, or `review`.
- Configures the spam confidence threshold used for automatic actions.
- Moves confidently flagged new accounts to a selected SMF membergroup.
- Unapproves confidently flagged first posts instead of deleting them.
- Shows a decision log in the admin panel with the verdict, probabilities, action, type, and member per check; the full record, including a content snippet, is stored in the database.
- Fails open: an unavailable or invalid gateway response does not block registration or posting.
- Skips checks for administrators and moderators.

## Requirements

- SMF 2.1.
- PHP extensions used by SMF and the mod, including cURL and `mbstring`.
- An API gateway that accepts the request and returns the response shape below.

## Configuration

The settings page contains:

- **Gateway URL**: endpoint receiving the JSON classification request.
- **API key**: sent as `Authorization: Bearer <API key>`.
- **Model**: defaults to `jev-latest`.
- **Spam confidence threshold**: a value from `0` to `1`; defaults to `0.95`.
- **Check first posts**: enables classification of posts from members whose post count is at or below the configured limit.
- **New member post limit**: controls which members are considered new for first-post checks.
- **Restricted membergroup**: destination group for confidently flagged registrations.

The registration payload contains the username and email address. Post checks send the post body without HTML tags. Content sent to the gateway is limited to 10,000 characters. The admin log stores the first 500 characters of the checked content, together with the member name, email address, IP address, verdict, probabilities, and action.

## Gateways

| Aggregator | Gateway | Model |
|------------|---------|-------|
| [Jev AI](https://jevmodel.org/how-to-use-jev/) | `https://api.typesafe.ai/v1/systemone` | `jev-latest` |
| [Codiv](https://codiv.ai) | `https://api.codiv.ai/v1/systemone` | `openjev-latest` |
| [Experiential Labs](https://www.experientiallabs.ai/) | `https://api.experientiallabs.ai/v1/systemone` | `jev-latest` |
| [Vercel](https://vercel.com/changelog/ai-gateway-now-supports-typesafe-clients-and-http-api-for-jev) | `https://ai-gateway.vercel.sh/typesafe` | `typesafe-ai/jev` |
| [Polza.ai](https://polza.ai/?referral=f8C85aRMPd) | `https://polza.ai/api/v1/systemone` | `typesafe/jev` |

## Gateway Contract

Spam Judge sends a `POST` request with `Content-Type: application/json` and a bearer token. The request has this shape:

```json
{
  "model": "jev-latest",
  "state": {
    "content": "Content to classify"
  },
  "questions": {
    "classification": {
      "type": "choice",
      "instructions": "...",
      "criteria": {
        "ham": "...",
        "spam": "...",
        "review": "..."
      }
    }
  }
}
```

The gateway must return a successful JSON response with this structure:

```json
{
  "answers": {
    "classification": {
      "type": "choice",
      "choice": "spam",
      "probabilities": {
        "ham": 0.01,
        "spam": 0.98,
        "review": 0.01
      }
    }
  }
}
```

`choice` must be one of `ham`, `spam`, or `review`. Each probability must be a finite number between `0` and `1`. The mod takes an automatic action only when the choice is `spam` and the `spam` probability reaches the configured threshold.

## Privacy and Safety

The mod sends registration data, post content, and identifying data to a third-party service. Check the provider's data handling terms and make sure this use complies with your forum's privacy policy and applicable law, including GDPR where relevant. Do not enable the mod without configuring a gateway URL and API key.

The project is distributed under the [BSD 3-Clause License](LICENSE).
