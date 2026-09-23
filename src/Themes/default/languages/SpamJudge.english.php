<?php

$txt['spam_judge']         = 'Spam Judge';
$txt['spam_judge_type']    = 'Type';
$txt['spam_judge_verdict'] = 'Verdict';
$txt['spam_judge_action']  = 'Action';
$txt['spam_judge_action_none'] = 'none';
$txt['spam_judge_action_restricted'] = 'moved to restricted group';
$txt['spam_judge_action_unapproved'] = 'unapproved';
$txt['spam_judge_no_items'] = 'No log entries found';

$txt['spam_judge_enabled']          = 'Enable Spam Judge';
$txt['spam_judge_gateway_url']      = 'System One API gateway URL';
$txt['spam_judge_api_key']          = 'Gateway API key';
$txt['spam_judge_model']            = 'Model';
$txt['spam_judge_spam_threshold']   = 'Spam confidence threshold (0–1)';
$txt['spam_judge_check_first_post'] = 'Also check the first posts of new members';
$txt['spam_judge_post_threshold']   = 'Consider a member "new" up to this many posts';
$txt['spam_judge_restricted_group'] = 'Restricted membergroup for flagged accounts';
$txt['spam_judge_no_group']         = '— none, do not restrict —';

$txt['spam_judge_error_missing_config']        = 'the gateway URL or API key is not configured.';
$txt['spam_judge_error_encode']                = 'failed to encode the request as JSON.';
$txt['spam_judge_error_curl_init']             = 'failed to initialize cURL.';
$txt['spam_judge_error_request']               = 'gateway request failed: %s';
$txt['spam_judge_error_http']                  = 'the gateway returned HTTP status %d.';
$txt['spam_judge_error_invalid_json']          = 'the gateway returned invalid JSON.';
$txt['spam_judge_error_invalid_response']      = 'the gateway response must be a JSON object.';
$txt['spam_judge_error_invalid_answer']        = 'the gateway response did not contain a valid classification answer.';
$txt['spam_judge_error_invalid_probabilities'] = 'the probabilities in the gateway response have an invalid format.';

$txt['spam_judge_settings_description'] = 'Configure the System One gateway, model, and automatic checks for new users and their messages.';
$txt['spam_judge_logs_description']     = 'This page shows the registration and message checks performed by Spam Judge.';
