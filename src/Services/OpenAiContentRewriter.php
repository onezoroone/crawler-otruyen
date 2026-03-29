<?php

namespace Nqt\CrawlerOtruyen\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Viết lại mô tả truyện qua OpenAI Chat Completions (tùy bật + API key).
 */
class OpenAiContentRewriter
{
    public function __construct(
        protected CrawlerImportOptions $options
    ) {}

    /**
     * Trả về bản plain text đã chỉnh sửa; lỗi API → ném exception (caller giữ bản gốc).
     *
     * @param  CrawlerImportOptions|null  $importOptions  Ghi đè theo lần import (vd. wizard); null = dùng inject mặc định.
     */
    public function rewriteSynopsis(string $plainText, ?CrawlerImportOptions $importOptions = null): string
    {
        $opt = $importOptions ?? $this->options;
        $plainText = trim($plainText);
        if ($plainText === '') {
            return '';
        }

        if (! $opt->contentRewriteEnabled()) {
            return $plainText;
        }

        $apiKey = $opt->openaiApiKey();
        if ($apiKey === null || $apiKey === '') {
            return $plainText;
        }

        $maxIn = (int) config('crawler-otruyen.openai_max_input_chars', 12000);
        if (Str::length($plainText) > $maxIn) {
            $plainText = Str::limit($plainText, $maxIn, '…');
        }

        $userPrompt = $opt->openaiUserPrompt();
        $model = $opt->openaiModel();

        try {
            $response = Http::timeout((int) config('crawler-otruyen.openai_timeout', 120))
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => (string) config(
                                'crawler-otruyen.openai_system_message',
                                'Bạn là biên tập nội dung web truyện tranh tiếng Việt. Chỉ trả về văn bản thuần, không markdown, không giải thích.'
                            ),
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt."\n\n--- NỘI DUNG GỐC ---\n".$plainText,
                        ],
                    ],
                    'max_tokens' => (int) config('crawler-otruyen.openai_max_tokens', 4096),
                    'temperature' => (float) config('crawler-otruyen.openai_temperature', 0.4),
                ]);
        } catch (Throwable $e) {
            throw new \RuntimeException('OpenAI không phản hồi: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI lỗi HTTP '.$response->status().': '.$response->body());
        }

        $data = $response->json();
        $out = $data['choices'][0]['message']['content'] ?? '';
        $out = is_string($out) ? trim(strip_tags($out)) : '';

        return $out !== '' ? $out : $plainText;
    }
}
