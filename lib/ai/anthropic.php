<?php
declare(strict_types=1);

/**
 * Adapter Anthropic: Messages-API per cURL, strukturierte Ausgabe über ein erzwungenes Werkzeug mit JSON-Schema.
 */

const AI_ANTHROPIC_BASE = 'https://api.anthropic.com/v1';
const AI_ANTHROPIC_VERSION = '2023-06-01';

function ai_anthropic_structured(array $credential, string $system, string $user, array $schema, array $options): array {
    $model = (string)($options['model'] ?? $credential['model'] ?? AI_DEFAULT_MODEL['anthropic']);
    $body = [
        'model' => $model,
        'max_tokens' => (int)$options['max_tokens'],
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
        'tools' => [[
            'name' => $options['tool_name'],
            'description' => $options['tool_description'],
            'input_schema' => $schema,
        ]],
        'tool_choice' => ['type' => 'tool', 'name' => $options['tool_name']],
    ];
    [$status, $json] = ai_anthropic_request('POST', '/messages', (string)$credential['api_key'], $body, (int)$options['timeout']);
    $errMsg = (string)($json['error']['message'] ?? '');
    if ($status === 400 && $body['max_tokens'] > 4096 && stripos($errMsg, 'max_tokens') !== false) {
        // Ältere Modelle erlauben höchstens 4096 Ausgabe-Token: einmal mit dieser Grenze wiederholen.
        $body['max_tokens'] = 4096;
        [$status, $json] = ai_anthropic_request('POST', '/messages', (string)$credential['api_key'], $body, (int)$options['timeout']);
        $errMsg = (string)($json['error']['message'] ?? '');
    }
    if ($status === 400 && stripos($errMsg, 'tool_choice') !== false) {
        // Manche Modelle erlauben kein erzwungenes Werkzeug: freiwillige Wahl mit ausdrücklicher Aufforderung,
        // und als Ausweg wird reines JSON im Antworttext akzeptiert.
        $body['tool_choice'] = ['type' => 'auto'];
        $body['messages'][0]['content'] = $user . "\n\nAntworte ausschliesslich mit einem Aufruf des Werkzeugs «" . $options['tool_name'] . "». Falls du das Werkzeug nicht aufrufen kannst, antworte nur mit dem JSON-Objekt nach dessen Schema, ohne weiteren Text.";
        [$status, $json] = ai_anthropic_request('POST', '/messages', (string)$credential['api_key'], $body, (int)$options['timeout']);
    }
    ai_anthropic_check_status($status, $json);
    $input = null;
    $text = '';
    foreach ($json['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $options['tool_name']) { $input = $block['input'] ?? null; break; }
        if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
    }
    if (!is_array($input) && $text !== '') {
        $input = ai_anthropic_json_from_text($text);
    }
    if (!is_array($input)) {
        throw new AiException('invalid_response', 'Das Modell hat kein strukturiertes Ergebnis geliefert' . (($json['stop_reason'] ?? '') === 'max_tokens' ? ' (Antwort zu lang).' : '.'));
    }
    return [
        'data' => $input,
        'input_tokens' => (int)($json['usage']['input_tokens'] ?? 0),
        'output_tokens' => (int)($json['usage']['output_tokens'] ?? 0),
        'model' => (string)($json['model'] ?? $model),
    ];
}

/** Liest ein JSON-Objekt aus einem Antworttext (auch in ```json-Zäunen oder mit Text davor und danach). */
function ai_anthropic_json_from_text(string $text): ?array {
    $t = trim($text);
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $t, $m)) $t = $m[1];
    $start = strpos($t, '{');
    $end = strrpos($t, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $d = json_decode(substr($t, $start, $end - $start + 1), true);
    return is_array($d) ? $d : null;
}

/** Modell-Liste des Anbieters über dessen Endpunkt; dient zugleich als Verbindungstest. */
function ai_anthropic_models(string $apiKey): array {
    [$status, $json] = ai_anthropic_request('GET', '/models?limit=100', $apiKey, null, 20);
    ai_anthropic_check_status($status, $json);
    $out = [];
    foreach ($json['data'] ?? [] as $m) {
        if (!empty($m['id'])) $out[] = ['id' => (string)$m['id'], 'name' => (string)($m['display_name'] ?? $m['id'])];
    }
    return $out;
}

function ai_anthropic_request(string $method, string $path, string $apiKey, ?array $body, int $timeout): array {
    if (!function_exists('curl_init')) throw new AiException('network', 'cURL fehlt auf diesem Server.');
    $ch = curl_init(AI_ANTHROPIC_BASE . $path);
    $headers = ['x-api-key: ' . $apiKey, 'anthropic-version: ' . AI_ANTHROPIC_VERSION, 'content-type: application/json', 'accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        if ($errno === CURLE_OPERATION_TIMEDOUT) throw new AiException('timeout', 'Zeitüberschreitung beim Anbieter.');
        throw new AiException('network', 'Keine Verbindung zum Anbieter (cURL ' . $errno . ').');
    }
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : []];
}

function ai_anthropic_check_status(int $status, array $json): void {
    if ($status >= 200 && $status < 300) return;
    $type = (string)($json['error']['type'] ?? '');
    $msg = (string)($json['error']['message'] ?? ('HTTP ' . $status));
    if ($status === 401 || $status === 403 || $type === 'authentication_error' || $type === 'permission_error') throw new AiException('invalid_key', $msg);
    if ($status === 429 || $type === 'rate_limit_error') throw new AiException('rate_limit', $msg);
    if ($status === 529 || $status === 503 || $type === 'overloaded_error') throw new AiException('overloaded', $msg);
    if ($status === 408 || $status === 504) throw new AiException('timeout', $msg);
    if ($status === 404 || $type === 'not_found_error') throw new AiException('invalid_response', 'Modell nicht gefunden: ' . $msg);
    throw new AiException('invalid_response', 'HTTP ' . $status . ': ' . $msg);
}
