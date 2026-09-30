<?php

namespace App\Services\Research;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ResearchClient
{
    public function providers(): array
    {
        try {
            $response = Http::withToken(config('research.token'))->connectTimeout(2)->timeout(5)->get(rtrim(config('research.base_url'), '/').'/ready');
            $available = $response->successful() ? $response->json('providers', []) : [];
        } catch (Throwable) {
            $available = [];
        }

        return array_map(fn ($id) => ['id' => $id, 'available' => collect($available)->contains(fn ($p) => ($p['id'] ?? null) === $id && ($p['available'] ?? false) === true)], config('research.providers'));
    }

    public function research(string $bytes, string $provider, string $question): array
    {
        try {
            $response = Http::withToken(config('research.token'))->connectTimeout(5)->timeout(config('research.timeout'))->post(rtrim(config('research.base_url'), '/').'/v1/research', [
                'image_base64' => base64_encode($bytes), 'mime_type' => 'image/webp', 'provider' => $provider, 'question' => $question,
            ]);
        } catch (Throwable) {
            throw new ApiException('WEB_RESEARCH_UNAVAILABLE', 503, 'Сервис исследования недоступен.');
        }
        if (! $response->successful()) {
            $code = $response->json('error.code');
            $known = ['WEB_SEARCH_BLOCKED', 'AGENT_FAILED', 'AGENT_OUTPUT_INVALID', 'PROVIDER_UNAVAILABLE', 'BUSY', 'RESEARCH_TIMEOUT', 'TOOL_BUDGET_EXCEEDED'];
            throw new ApiException(in_array($code, $known, true) ? $code : 'WEB_RESEARCH_UNAVAILABLE', 502, 'Исследование не завершилось.');
        }
        $result = $response->json();
        if (! $this->valid($result)) {
            throw new ApiException('AGENT_OUTPUT_INVALID', 502, 'Неверный ответ агента.');
        }

        return $result;
    }

    private function valid(mixed $r): bool
    {
        if (! is_array($r) || count($r) !== 5 || ! in_array($r['status'] ?? null, ['identified', 'uncertain'], true) || ! array_key_exists('name', $r) ||
         ! ($r['name'] === null || is_string($r['name']) && mb_strlen($r['name']) <= 255) || ! is_string($r['description'] ?? null) ||
         mb_strlen($r['description']) < 1 || mb_strlen($r['description']) > 4000 || ! in_array($r['confidence'] ?? null, ['low', 'medium', 'high'], true) ||
         ! is_array($r['sources'] ?? null) || ! array_is_list($r['sources']) || count($r['sources']) > 6) {
            return false;
        }
        if ($r['status'] === 'identified' && (! trim((string) $r['name']) || ! $r['sources'])) {
            return false;
        }
        if ($r['status'] === 'uncertain' && $r['confidence'] === 'high') {
            return false;
        }
        foreach ($r['sources'] as $s) {
            if (! is_array($s) || count($s) !== 3) {
                return false;
            }
            foreach (['url' => 2000, 'title' => 300, 'evidence' => 1000] as $key => $max) {
                if (! is_string($s[$key] ?? null) || mb_strlen($s[$key]) < 1 || mb_strlen($s[$key]) > $max) {
                    return false;
                }
            }
            if (! filter_var($s['url'], FILTER_VALIDATE_URL) || ! in_array(parse_url($s['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                return false;
            }
        }

        return true;
    }
}
