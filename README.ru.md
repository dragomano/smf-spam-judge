# Spam Judge

[![SMF 2.1](https://img.shields.io/badge/SMF-2.1-ed6033.svg?style=flat)](https://github.com/SimpleMachines/SMF2.1)
![License](https://img.shields.io/github/license/dragomano/smf-spam-judge)
![Hooks only: Yes](https://img.shields.io/badge/Hooks%20only-YES-blue)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/smf-spam-judge/badge.svg?branch=main)](https://coveralls.io/github/dragomano/smf-spam-judge?branch=main)

[English](README.md) | **Русский**

Spam Judge — модификация для [Simple Machines Forum (SMF) 2.1](https://www.simplemachines.org/). Она классифицирует новые регистрации и первые сообщения через настраиваемый API-шлюз ИИ и может автоматически ограничивать подозрительные аккаунты или снимать с одобрения подозрительные сообщения.

Шлюз и модель не зашиты в модификацию. Можно использовать совместимый эндпойнт Vercel AI Gateway, OpenRouter, Cloudflare Workers AI или другого сервиса, который возвращает ответ в одном из форматов, описанных ниже.

## Возможности

- Классификация данных регистрации и первых сообщений как `ham`, `spam` или `review`.
- Поддержка обоих форматов шлюза: TypeSafe System One и OpenAI Decisions.
- Настраиваемый порог уверенности в спаме для автоматических действий.
- Перенос новых аккаунтов с высокой вероятностью спама в выбранную группу SMF.
- Снятие с одобрения подозрительных первых сообщений вместо их удаления.
- Журнал решений в админ-панели с вердиктом, вероятностями, действием, типом и участником по каждой проверке; полная запись, включая фрагмент содержимого, сохраняется в базе данных.
- Fail-open: недоступный шлюз или некорректный ответ не блокируют регистрацию и публикацию сообщений.
- Администраторы и модераторы не проверяются.

## Требования

- SMF 2.1.
- Расширения PHP, используемые SMF и модификацией, включая cURL и `mbstring`.
- API-шлюз, принимающий запрос и возвращающий ответ в одном из форматов ниже (TypeSafe System One или OpenAI Decisions).

## Настройка

На странице настроек доступны следующие параметры:

- **URL шлюза** — эндпойнт, принимающий JSON-запрос классификации.
- **Тип API** — формат запроса и ответа: `TypeSafe System One` (по умолчанию) или `OpenAI Decisions`.
- **API-ключ** — передаётся в заголовке `Authorization: Bearer <API-ключ>`.
- **Модель** — по умолчанию `jev-latest` для TypeSafe System One и `gpt-6-luna` для OpenAI Decisions.
- **Порог уверенности в спаме** — значение от `0` до `1`, по умолчанию `0.95`.
- **Проверять первые сообщения** — включает классификацию сообщений участников, число сообщений которых не превышает заданный лимит.
- **Лимит сообщений нового участника** — определяет, какие участники считаются новыми для проверки первых сообщений.
- **Ограничивающая группа** — группа, в которую переносятся регистрации с высокой вероятностью спама.

При регистрации в шлюз отправляются имя пользователя и адрес электронной почты. При проверке сообщения отправляется его текст без HTML-тегов. Длина отправляемого содержимого ограничена 10 000 символами. В журнале сохраняются первые 500 символов проверенного содержимого, имя участника, адрес электронной почты, IP-адрес, вердикт, вероятности и выполненное действие.

## Шлюзы

| Агрегатор | Шлюз | Тип API | Модель |
|-----------|------|---------|--------|
| [Polza.ai](https://polza.ai/?referral=f8C85aRMPd) | `https://polza.ai/api/v1/systemone` | TypeSafe System One | `typesafe/jev` |
| [Codiv](https://codiv.ai) | `https://api.codiv.ai/v1/systemone` | TypeSafe System One | `openjev-latest` |
| [Experiential Labs](https://www.experientiallabs.ai/) | `https://api.experientiallabs.ai/v1/systemone` | TypeSafe System One | `jev-latest` |
| [Jev AI](https://jevmodel.org/how-to-use-jev/) | `https://api.typesafe.ai/v1/systemone` | TypeSafe System One | `jev-latest` |
| [Vercel](https://vercel.com/changelog/ai-gateway-now-supports-typesafe-clients-and-http-api-for-jev) | `https://ai-gateway.vercel.sh/typesafe` | TypeSafe System One | `typesafe-ai/jev` |
| [Decisions](https://developers.openai.com/api/docs/guides/decisions) | `https://api.openai.com/v1/decisions` | OpenAI Decisions | `gpt-6-luna` |

## Контракт шлюза

Spam Judge отправляет `POST` с заголовками `Content-Type: application/json` и bearer-токеном. Структура запроса и ответа зависит от выбранного **типа API**.

### TypeSafe System One

Тип API по умолчанию. Запрос:

```json
{
  "model": "jev-latest",
  "state": {
    "content": "Содержимое для классификации"
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

Ответ:

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

### OpenAI Decisions

Запрос:

```json
{
  "model": "gpt-6-luna",
  "input": "Содержимое для классификации",
  "questions": [
    {
      "name": "classification",
      "type": "choice",
      "instructions": "...",
      "choices": [
        {
          "value": "ham",
          "description": "..."
        },
        {
          "value": "spam",
          "description": "..."
        },
        {
          "value": "review",
          "description": "..."
        }
      ]
    }
  ]
}
```

Ответ:

```json
{
  "answers": [
    {
      "name": "classification",
      "type": "choice",
      "choice": "spam",
      "probabilities": [
        {
          "value": "ham",
          "probability": 0.01
        },
        {
          "value": "spam",
          "probability": 0.98
        },
        {
          "value": "review",
          "probability": 0.01
        }
      ]
    }
  ]
}
```

Правила для обоих форматов одинаковы: `choice` — это `ham`, `spam` или `review`, а в `probabilities` должны быть все три значения в виде конечных чисел от `0` до `1`. Мод выполняет действие автоматически только тогда, когда выбран `spam` и его вероятность достигает заданного порога.

## Конфиденциальность и безопасность

Модификация отправляет стороннему сервису данные регистрации, содержимое сообщений и идентифицирующие данные. Изучите правила обработки данных у провайдера и убедитесь, что использование соответствует политике конфиденциальности форума и применимому законодательству, включая GDPR. Не включайте модификацию без настройки URL шлюза и API-ключа.

Проект распространяется по лицензии [BSD 3-Clause](LICENSE).
