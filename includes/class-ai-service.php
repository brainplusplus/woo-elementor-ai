<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI Service — mirrors openai-php/client request/response handling exactly.
 *
 * Request format: identical to Payload::create() + HttpTransporter
 * Response parsing: identical to CreateResponseChoice + CreateResponseMessage
 * Streaming: identical to CreateStreamedResponseDelta
 *
 * @see https://github.com/openai-php/client
 */
class AI_Service {

    /**
     * Send a chat completion request.
     *
     * Mirrors: Chat::create() → Payload::create('chat/completions', $parameters)
     *          → HttpTransporter::requestObject() → json_decode → CreateResponse::from()
     *
     * @param  array  $messages  OpenAI-format messages array.
     * @param  array  $options   Override: model, max_tokens, temperature.
     * @return array  ['success' => bool, 'content' => string|null, 'error' => string, 'message' => string]
     */
    public function chat( array $messages, array $options = [] ): array {
        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            return [
                'success' => false,
                'error'   => 'missing_settings',
                'message' => __( 'AI API is not configured. Go to Woo Elementor AI settings.', 'woo-elementor-ai' ),
            ];
        }

        $mode = $settings['ai_processing_mode'] ?? 'curl';
        if ( 'exec_curl' === $mode ) {
            return $this->chat_exec_curl( $messages, $options, $settings );
        }

        $parameters = [
            'model'       => $options['model'] ?? $settings['model'],
            'messages'    => $messages,
            'max_tokens'  => $options['max_tokens'] ?? $settings['max_tokens'],
            'temperature' => $options['temperature'] ?? $settings['temperature'],
        ];

        // Build URL — mirrors BaseUri::toString() + ResourceUri::create()
        // BaseUri adds trailing slash, ResourceUri is "chat/completions"
        // Result: "{baseUri}/chat/completions"
        $base_uri = rtrim( $settings['base_url'], '/' ) . '/';
        $url      = $base_uri . 'chat/completions';

        // Send request — mirrors HttpTransporter::requestObject()
        // Headers: Authorization: Bearer {key} (from Headers::withAuthorization)
        // Content-Type: application/json (default ContentType)
        // Body: json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        //   — mirrors Payload::toRequest() line 185
        $log = \WooElementorAI\Log_Service::get_instance();
        $log->log( 'ai_request', 'info', "AI chat request: model={$parameters['model']} max_tokens={$parameters['max_tokens']}", [
            'url' => $url,
            'model' => $parameters['model'],
            'messages_count' => count( $parameters['messages'] ),
            'max_tokens' => $parameters['max_tokens'],
            'messages' => $parameters['messages'],
        ] );

        $start_ms = round( microtime( true ) * 1000 );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['api_key'],
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $parameters, JSON_UNESCAPED_UNICODE ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ] );

        $raw_body = curl_exec( $ch );
        $code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_errno = curl_errno( $ch );
        $curl_error = $curl_errno ? curl_error( $ch ) : '';
        curl_close( $ch );

        $elapsed_ms = round( microtime( true ) * 1000 ) - $start_ms;

        if ( $curl_errno ) {
            $log->log( 'ai_request', 'error', 'AI connection failed: ' . $curl_error, [ 'url' => $url, 'elapsed_ms' => $elapsed_ms ] );
            return [
                'success' => false,
                'error'   => 'ai_connection_error',
                'message' => $curl_error,
            ];
        }

        if ( 429 === $code ) {
            $log->log( 'ai_response', 'error', 'AI rate limited (HTTP 429)', [ 'elapsed_ms' => $elapsed_ms ] );
            return [
                'success' => false,
                'error'   => 'ai_rate_limited',
                'message' => __( 'AI API rate limit exceeded. Please try again in a moment.', 'woo-elementor-ai' ),
            ];
        }

        if ( $code >= 500 ) {
            $log->log( 'ai_response', 'error', "AI server error (HTTP {$code})", [ 'body_preview' => substr( $raw_body, 0, 500 ), 'elapsed_ms' => $elapsed_ms ] );
            return [
                'success' => false,
                'error'   => 'ai_server_error',
                'message' => __( 'AI API server error (HTTP ', 'woo-elementor-ai' ) . $code . ').',
            ];
        }

        $data = json_decode( $raw_body, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            $log->log( 'ai_response', 'error', 'AI returned invalid JSON', [ 'body_preview' => substr( $raw_body, 0, 1000 ), 'elapsed_ms' => $elapsed_ms ] );
            return [
                'success' => false,
                'error'   => 'ai_invalid_json',
                'message' => __( 'AI returned invalid JSON.', 'woo-elementor-ai' ),
            ];
        }

        if ( 200 !== $code ) {
            $error_msg = $data['error']['message'] ?? $data['message'] ?? "HTTP {$code}";
            return [
                'success' => false,
                'error'   => 'ai_invalid_response',
                'message' => $error_msg,
            ];
        }

        if ( empty( $data['choices'] ) || ! is_array( $data['choices'] ) ) {
            return [
                'success' => false,
                'error'   => 'ai_no_choices',
                'message' => __( 'AI returned no choices in response.', 'woo-elementor-ai' ),
            ];
        }

        $choice  = $data['choices'][0];
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? null;
        $reasoning_content = $message['reasoning_content'] ?? null;
        $finish_reason = $choice['finish_reason'] ?? null;

        $result = [
            'success'           => true,
            'content'           => $content,
            'reasoning_content' => $reasoning_content,
            'finish_reason'     => $finish_reason,
            'model'             => $data['model'] ?? null,
            'usage'             => $data['usage'] ?? null,
        ];

        $log->log( 'ai_response', 'success', "AI response OK: model={$result['model']} finish_reason={$finish_reason}", [
            'model' => $result['model'],
            'finish_reason' => $finish_reason,
            'usage' => $result['usage'],
            'content_length' => strlen( $content ?? '' ),
            'reasoning_length' => strlen( $reasoning_content ?? '' ),
            'raw_response' => $raw_body,
            'elapsed_ms' => $elapsed_ms,
        ] );

        return $result;
    }

    private function chat_exec_curl( array $messages, array $options, array $settings ): array {
        $parameters = [
            'model'       => $options['model'] ?? $settings['model'],
            'messages'    => $messages,
            'max_tokens'  => $options['max_tokens'] ?? $settings['max_tokens'],
            'temperature' => $options['temperature'] ?? $settings['temperature'],
        ];

        $base_uri = rtrim( $settings['base_url'], '/' ) . '/';
        $url      = $base_uri . 'chat/completions';

        $log = \WooElementorAI\Log_Service::get_instance();
        $log->log( 'ai_request', 'info', "AI chat request (exec_curl): model={$parameters['model']}", [
            'url' => $url, 'model' => $parameters['model'],
            'messages_count' => count( $parameters['messages'] ),
            'messages' => $parameters['messages'],
        ] );

        $json_body = wp_json_encode( $parameters, JSON_UNESCAPED_UNICODE );
        $tmp_file  = sys_get_temp_dir() . '/woo_ai_' . uniqid() . '.json';
        file_put_contents( $tmp_file, $json_body );

        $cmd = sprintf(
            'curl -s -w "\\n%%{http_code}" --connect-timeout 10 --max-time 120 ' .
            '-H "Content-Type: application/json" ' .
            '-H "Authorization: Bearer %s" ' .
            '-d @%s %s 2>&1',
            escapeshellarg( $settings['api_key'] ),
            escapeshellarg( $tmp_file ),
            escapeshellarg( $url )
        );

        $start_ms = round( microtime( true ) * 1000 );

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $proc = proc_open( $cmd, $descriptors, $pipes );
        if ( ! is_resource( $proc ) ) {
            @unlink( $tmp_file );
            $log->log( 'ai_request', 'error', 'exec_curl: proc_open failed', [ 'url' => $url, 'cmd_preview' => substr( $cmd, 0, 200 ) ] );
            return [
                'success' => false,
                'error'   => 'ai_exec_failed',
                'message' => 'Failed to execute curl command.',
            ];
        }

        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit_code = proc_close( $proc );
        @unlink( $tmp_file );

        $elapsed_ms = round( microtime( true ) * 1000 ) - $start_ms;

        $lines    = explode( "\n", trim( $stdout ) );
        $code     = (int) array_pop( $lines );
        $raw_body = implode( "\n", $lines );

        if ( $exit_code !== 0 || empty( $raw_body ) ) {
            $log->log( 'ai_request', 'error', "exec_curl failed: exit={$exit_code}", [
                'stderr' => $stderr, 'elapsed_ms' => $elapsed_ms,
            ] );
            return [
                'success' => false,
                'error'   => 'ai_connection_error',
                'message' => 'curl command failed: ' . trim( $stderr ),
            ];
        }

        if ( 429 === $code ) {
            $log->log( 'ai_response', 'error', 'AI rate limited (HTTP 429)', [ 'elapsed_ms' => $elapsed_ms, 'body_preview' => substr( $raw_body, 0, 500 ) ] );
            return [ 'success' => false, 'error' => 'ai_rate_limited', 'message' => 'Rate limited.' ];
        }
        if ( $code >= 500 ) {
            $log->log( 'ai_response', 'error', "AI server error (HTTP {$code})", [ 'body_preview' => substr( $raw_body, 0, 500 ), 'elapsed_ms' => $elapsed_ms ] );
            return [ 'success' => false, 'error' => 'ai_server_error', 'message' => "HTTP {$code}" ];
        }

        $data = json_decode( $raw_body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            $log->log( 'ai_response', 'error', 'AI invalid JSON', [ 'body_preview' => substr( $raw_body, 0, 1000 ), 'elapsed_ms' => $elapsed_ms ] );
            return [ 'success' => false, 'error' => 'ai_invalid_json', 'message' => 'Invalid JSON.' ];
        }
        if ( 200 !== $code ) {
            return [ 'success' => false, 'error' => 'ai_invalid_response', 'message' => $data['error']['message'] ?? "HTTP {$code}" ];
        }
        if ( empty( $data['choices'] ) ) {
            return [ 'success' => false, 'error' => 'ai_no_choices', 'message' => 'No choices.' ];
        }

        $choice  = $data['choices'][0];
        $message = $choice['message'] ?? [];

        $result = [
            'success'           => true,
            'content'           => $message['content'] ?? null,
            'reasoning_content' => $message['reasoning_content'] ?? null,
            'finish_reason'     => $choice['finish_reason'] ?? null,
            'model'             => $data['model'] ?? null,
            'usage'             => $data['usage'] ?? null,
        ];

        $log->log( 'ai_response', 'success', "AI response OK (exec_curl): model={$result['model']}", [
            'finish_reason' => $result['finish_reason'],
            'usage' => $result['usage'],
            'content_length' => strlen( $result['content'] ?? '' ),
            'raw_response' => $raw_body,
            'elapsed_ms' => $elapsed_ms,
        ] );

        return $result;
    }

    /**
     * Convenience: system + user prompt in one call.
     */
    public function complete( string $system_prompt, string $user_prompt, array $options = [] ): array {
        return $this->chat( [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user', 'content' => $user_prompt ],
        ], $options );
    }

    /**
     * Test connection to the AI API.
     */
    public function test_connection(): array {
        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            return [ 'success' => false, 'error' => 'missing_settings', 'message' => 'AI API not configured.' ];
        }

        $base_uri = rtrim( $settings['base_url'], '/' ) . '/';
        $url      = $base_uri . 'chat/completions';

        $parameters = [
            'model'       => $settings['model'],
            'messages'    => [
                [ 'role' => 'system', 'content' => 'You are a test.' ],
                [ 'role' => 'user', 'content' => 'Say exactly: OK' ],
            ],
            'max_tokens'  => 10,
            'temperature' => 0,
        ];

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['api_key'],
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $parameters, JSON_UNESCAPED_UNICODE ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ] );

        $raw_body   = curl_exec( $ch );
        $code       = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_errno = curl_errno( $ch );
        $curl_error = $curl_errno ? curl_error( $ch ) : '';
        curl_close( $ch );

        if ( $curl_errno ) {
            return [ 'success' => false, 'error' => 'connection_error', 'message' => 'Connection failed: ' . $curl_error ];
        }

        if ( 401 === $code || 403 === $code ) {
            return [ 'success' => false, 'error' => 'auth_failed', 'message' => 'Invalid API key (HTTP ' . $code . ').' ];
        }

        if ( 429 === $code ) {
            return [ 'success' => true, 'message' => 'Connected! (Rate limited but credentials are valid.)' ];
        }

        if ( $code >= 500 ) {
            return [ 'success' => false, 'error' => 'server_error', 'message' => 'AI server error (HTTP ' . $code . '). Response: ' . substr( $raw_body, 0, 200 ) ];
        }

        $data = json_decode( $raw_body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return [ 'success' => false, 'error' => 'invalid_response', 'message' => 'API returned non-JSON response (HTTP ' . $code . '). Body: ' . substr( $raw_body, 0, 300 ) ];
        }

        if ( 200 !== $code ) {
            $msg = $data['error']['message'] ?? $data['message'] ?? 'Unknown error';
            return [ 'success' => false, 'error' => 'api_error', 'message' => $msg ];
        }

        if ( ! empty( $data['choices'] ) && ! empty( $data['choices'][0]['message']['content'] ) ) {
            return [ 'success' => true, 'message' => 'Connected! Model: ' . ( $data['model'] ?? $settings['model'] ) . '. Response: ' . trim( $data['choices'][0]['message']['content'] ) ];
        }

        return [ 'success' => true, 'message' => 'Connected! Model responded but content was empty.' ];
    }

    /**
     * Send a streaming chat completion request.
     *
     * Mirrors: Chat::createStreamed() → Payload::create('chat/completions', [...'stream'=>true])
     *          → HttpTransporter::requestStream()
     *          → StreamResponse iterating CreateStreamedResponse chunks
     *
     * Streaming delta parsing mirrors CreateStreamedResponseDelta::from():
     *   role             = $attributes['role'] ?? null
     *   content          = $attributes['content'] ?? null
     *   reasoningContent = $attributes['reasoning'] ?? null   ← NOTE: "reasoning" not "reasoning_content"
     *   toolCalls        = parsed from $attributes['tool_calls']
     */
    public function chat_stream( array $messages, array $options = [] ): void {
        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();

        if ( empty( $settings['api_key'] ) || empty( $settings['base_url'] ) ) {
            echo "data: " . wp_json_encode( [ 'error' => 'missing_settings' ] ) . "\n\n";
            ob_flush();
            flush();
            return;
        }

        // Build parameters — same as chat() but with stream=true
        // mirrors: Concerns\Streamable::setStreamParameter()
        $parameters = [
            'model'       => $options['model'] ?? $settings['model'],
            'messages'    => $messages,
            'max_tokens'  => $options['max_tokens'] ?? $settings['max_tokens'],
            'temperature' => $options['temperature'] ?? $settings['temperature'],
            'stream'      => true,
        ];

        // Build URL — same as chat()
        $base_uri = rtrim( $settings['base_url'], '/' ) . '/';
        $url      = $base_uri . 'chat/completions';

        // SSE headers
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        if ( ! function_exists( 'curl_init' ) ) {
            echo "data: " . wp_json_encode( [ 'error' => 'curl_required' ] ) . "\n\n";
            ob_flush();
            flush();
            return;
        }

        // Send streaming request — mirrors HttpTransporter with custom stream handler
        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['api_key'],
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $parameters, JSON_UNESCAPED_UNICODE ),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function( $ch, $data ) {
                // Parse SSE — mirrors StreamResponse iterating chunks
                // Each chunk: data: {json}\n\n
                $lines = explode( "\n", $data );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( '' === $line ) continue;

                    if ( 0 === strpos( $line, 'data: ' ) ) {
                        $payload = substr( $line, 6 );
                        if ( '[DONE]' === $payload ) {
                            echo "data: [DONE]\n\n";
                        } else {
                            // Parse chunk — mirrors CreateStreamedResponse::from()
                            $chunk = json_decode( $payload, true );
                            if ( is_array( $chunk ) && ! empty( $chunk['choices'] ) ) {
                                $delta = $chunk['choices'][0]['delta'] ?? [];
                                // Forward as-is — client handles delta.content and delta.reasoning
                                echo "data: " . $payload . "\n\n";
                            } else {
                                echo "data: " . $payload . "\n\n";
                            }
                        }
                        ob_flush();
                        flush();
                    }
                }
                return strlen( $data );
            },
        ] );

        curl_exec( $ch );

        if ( curl_errno( $ch ) ) {
            echo "data: " . wp_json_encode( [ 'error' => curl_error( $ch ) ] ) . "\n\n";
            ob_flush();
            flush();
        }

        curl_close( $ch );
        exit;
    }

    /**
     * Stream chat completion with per-chunk callback.
     *
     * Same as chat_stream() but calls $on_chunk(delta_content, delta_reasoning)
     * instead of echoing directly. Used by SSE generate/refine endpoints.
     *
     * Mirrors: Chat::createStreamed() iteration of CreateStreamedResponse chunks
     * Delta parsing: $attributes['content'] ?? null, $attributes['reasoning'] ?? null
     */
    public function chat_stream_sse( array $messages, array $options, array $settings ): void {
        $parameters = [
            'model'       => $options['model'] ?? $settings['model'],
            'messages'    => $messages,
            'max_tokens'  => $options['max_tokens'] ?? $settings['max_tokens'],
            'temperature' => $options['temperature'] ?? $settings['temperature'],
            'stream'      => true,
        ];

        $base_uri = rtrim( $settings['base_url'], '/' ) . '/';
        $url      = $base_uri . 'chat/completions';
        $on_chunk = $options['on_chunk'] ?? null;

        if ( ! function_exists( 'curl_init' ) || ! $on_chunk ) {
            return;
        }

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['api_key'],
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $parameters, JSON_UNESCAPED_UNICODE ),
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function( $ch, $data ) use ( $on_chunk ) {
                $lines = explode( "\n", $data );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( '' === $line ) continue;

                    if ( 0 === strpos( $line, 'data: ' ) ) {
                        $payload = substr( $line, 6 );
                        if ( '[DONE]' === $payload ) continue;

                        $chunk = json_decode( $payload, true );
                        if ( ! is_array( $chunk ) || empty( $chunk['choices'] ) ) continue;

                        $delta   = $chunk['choices'][0]['delta'] ?? [];
                        $content = $delta['content'] ?? '';
                        // Streaming delta uses 'reasoning' not 'reasoning_content'
                        // mirrors CreateStreamedResponseDelta: $attributes['reasoning'] ?? null
                        $reasoning = $delta['reasoning'] ?? '';

                        $on_chunk( $content, $reasoning );
                    }
                }
                return strlen( $data );
            },
        ] );

        $start_ms = round( microtime( true ) * 1000 );
        curl_exec( $ch );
        $elapsed_ms = round( microtime( true ) * 1000 ) - $start_ms;

        $log = \WooElementorAI\Log_Service::get_instance();
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_error = curl_errno( $ch ) ? curl_error( $ch ) : null;
        if ( $curl_error ) {
            $log->log( 'ai_request', 'error', "AI stream error: {$curl_error}", [ 'url' => $url, 'elapsed_ms' => $elapsed_ms ] );
        } else {
            $log->log( 'ai_response', 'info', "AI stream completed: HTTP {$http_code}", [
                'url' => $url,
                'model' => $parameters['model'],
                'http_code' => $http_code,
                'elapsed_ms' => $elapsed_ms,
            ] );
        }

        curl_close( $ch );
    }
}
