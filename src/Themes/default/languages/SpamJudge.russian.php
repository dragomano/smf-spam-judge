<?php

$txt['spam_judge']                   = 'Spam Judge';
$txt['spam_judge_type']              = 'Тип';
$txt['spam_judge_verdict']           = 'Вердикт';
$txt['spam_judge_action']            = 'Действие';
$txt['spam_judge_action_none']       = 'нет';
$txt['spam_judge_action_restricted'] = 'ограничен';
$txt['spam_judge_action_unapproved'] = 'неодобрено';
$txt['spam_judge_no_items']          = 'Записей пока нет';

$txt['spam_judge_enabled']          = 'Включить Spam Judge';
$txt['spam_judge_gateway_url']      = 'URL шлюза System One API';
$txt['spam_judge_api_key']          = 'API-ключ шлюза';
$txt['spam_judge_model']            = 'Модель';
$txt['spam_judge_spam_threshold']   = 'Порог уверенности в спаме (0–1)';
$txt['spam_judge_check_first_post'] = 'Также проверять первые сообщения новых участников';
$txt['spam_judge_post_threshold']   = 'Считать участника «новым» до этого числа сообщений';
$txt['spam_judge_restricted_group'] = 'Ограничивающая группа для подозрительных аккаунтов';
$txt['spam_judge_no_group']         = '— не выбрано, не ограничивать —';

$txt['spam_judge_error_missing_config']        = 'не задан URL шлюза или API-ключ.';
$txt['spam_judge_error_encode']                = 'не удалось преобразовать запрос в JSON.';
$txt['spam_judge_error_curl_init']             = 'не удалось инициализировать cURL.';
$txt['spam_judge_error_request']               = 'ошибка запроса к шлюзу: %s';
$txt['spam_judge_error_http']                  = 'шлюз вернул HTTP-статус %d.';
$txt['spam_judge_error_invalid_json']          = 'шлюз вернул некорректный JSON.';
$txt['spam_judge_error_invalid_response']      = 'ответ шлюза должен быть JSON-объектом.';
$txt['spam_judge_error_invalid_answer']        = 'в ответе шлюза отсутствует корректный результат классификации.';
$txt['spam_judge_error_invalid_probabilities'] = 'вероятности в ответе шлюза имеют некорректный формат.';

$txt['spam_judge_settings_description'] = 'Настройте подключение к шлюзу System One, модель и правила автоматической проверки новых пользователей и их сообщений.';
$txt['spam_judge_logs_description']     = 'Здесь отображаются результаты проверок регистраций и сообщений, выполненных модулем Spam Judge.';
