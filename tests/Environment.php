<?php

declare(strict_types=1);

if (! defined('SMF')) {
    define('SMF', true);
}

if (! function_exists('add_integration_function')) {
    function add_integration_function(string $hook, string $callback, bool $replace): void
    {
        $GLOBALS['spam_judge_hooks'][] = [$hook, $callback, $replace];
    }
}

if (! function_exists('loadLanguage')) {
    function loadLanguage(string $language): void
    {
    }
}

if (! function_exists('log_error')) {
    function log_error(string $message): void
    {
        $GLOBALS['spam_judge_errors'][] = $message;
    }
}

if (! function_exists('loadGeneralSettingParameters')) {
    function loadGeneralSettingParameters(array $subActions, string $default): void
    {
        $GLOBALS['spam_judge_general_settings'] = [$subActions, $default];
    }
}

if (! function_exists('prepareDBSettingContext')) {
    function prepareDBSettingContext(array $config): void
    {
        $GLOBALS['spam_judge_prepared_settings'] = $config;
    }
}

if (! function_exists('checkSession')) {
    function checkSession(): void
    {
        $GLOBALS['spam_judge_check_session'] = true;
    }
}

if (! function_exists('saveDBSettings')) {
    function saveDBSettings(array $config): void
    {
        $GLOBALS['spam_judge_saved_settings'] = $config;
    }
}

if (! function_exists('redirectexit')) {
    function redirectexit(string $url): never
    {
        throw new \RuntimeException('redirect:' . $url);
    }
}

if (! function_exists('createList')) {
    function createList(array $config): void
    {
        $GLOBALS['spam_judge_list'] = $config;
    }
}

require_once __DIR__ . '/../src/Sources/SpamJudge.php';

final class Environment
{
    public static function reset(): void
    {
        $GLOBALS['modSettings'] = [];
        $GLOBALS['user_info'] = [];
        $GLOBALS['txt'] = [
            'spam_judge' => 'Spam Judge',
            'spam_judge_error_invalid_json' => 'invalid JSON',
            'spam_judge_error_invalid_response' => 'invalid response',
            'spam_judge_error_invalid_answer' => 'invalid answer',
            'spam_judge_error_invalid_probabilities' => 'invalid probabilities',
            'spam_judge_error_missing_config' => 'missing configuration',
            'spam_judge_error_encode' => 'encode failure',
            'spam_judge_error_curl_init' => 'curl init failure',
            'spam_judge_error_request' => 'request failure: %s',
            'spam_judge_error_http' => 'http failure: %d',
            'logs' => 'Logs',
            'spam_judge_action_none' => 'None',
            'spam_judge_action_restricted' => 'Restricted',
            'spam_judge_action_unapproved' => 'Unapproved',
            'spam_judge_no_items' => 'No items',
            'date' => 'Date',
            'spam_judge_type' => 'Type',
            'who_member' => 'Member',
            'spam_judge_verdict' => 'Verdict',
            'spam_judge_action' => 'Action',
        ];
        $GLOBALS['spam_judge_hooks'] = [];
        $GLOBALS['spam_judge_errors'] = [];
        $GLOBALS['spam_judge_inserts'] = [];
        $GLOBALS['spam_judge_queries'] = [];
        $GLOBALS['spam_judge_db_rows'] = [];
        $GLOBALS['spam_judge_prepared_settings'] = null;
        $GLOBALS['spam_judge_general_settings'] = null;
        $GLOBALS['spam_judge_gateway_routers'] = [];
        $GLOBALS['smcFunc'] = [
            'db_insert' => static function (...$arguments): void {
                $GLOBALS['spam_judge_inserts'][] = $arguments;
            },
            'db_query' => static function (string $identifier, string $query, array $params = []): object {
                $GLOBALS['spam_judge_queries'][] = ['query' => $query, 'params' => $params];

                return new \stdClass();
            },
            'db_fetch_assoc' => static function (): ?array {
                return array_shift($GLOBALS['spam_judge_db_rows']);
            },
            'db_free_result' => static function (): void {
            },
        ];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    public static function startGateway(string $verdict): array
    {
        return self::launchGateway(
            '<?php echo json_encode(["answers" => ["classification" => ["type" => "choice", "choice" => "' . $verdict . '", "probabilities" => ["ham" => 0.01, "spam" => 0.98, "review" => 0.01]]]]);'
        );
    }

    public static function startFailingGateway(): array
    {
        return self::launchGateway('<?php http_response_code(500); echo "error";');
    }

    private static function launchGateway(string $routerBody): array
    {
        $router = tempnam(sys_get_temp_dir(), 'spam-judge-router-');
        file_put_contents($router, $routerBody);
        $port = random_int(20000, 40000);
        $null = \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        // Array form skips the shell wrapper, so $process is the PHP server itself on
        // every platform and proc_terminate() can reliably shut it down afterwards.
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start test gateway.');
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port);

            if ($socket !== false) {
                fclose($socket);
                break;
            }

            usleep(10_000);
        }

        $GLOBALS['spam_judge_gateway_routers'][] = $router;

        return [$process, 'http://127.0.0.1:' . $port];
    }

    public static function stopGateway(mixed $process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        foreach ($GLOBALS['spam_judge_gateway_routers'] as $router) {
            if (is_file($router)) {
                unlink($router);
            }
        }

        $GLOBALS['spam_judge_gateway_routers'] = [];
    }
}
