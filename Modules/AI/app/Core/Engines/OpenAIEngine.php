<?php

namespace Modules\AI\app\Core\Engines;

use Modules\AI\app\Core\Contracts\AIEngineInterface;
use OpenAI\Laravel\Facades\OpenAI;


class OpenAIEngine implements AIEngineInterface
{
    public function boot(): void
    {
        // TODO: Implement boot() method.
    }

    public function core($prompt, $imageUrl = null): string
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        // DeepSeek-chat does not support image_url; skip image if provided
        // If image generation is needed, upgrade to a vision-capable model

        $response = OpenAI::chat()->create([
            'model' => 'deepseek-chat',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'temperature' => 0.3,
        ]);

        return $response->choices[0]->message->content;
    }


}
