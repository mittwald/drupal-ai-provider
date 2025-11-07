<?php

namespace Drupal\ai_provider_mittwald;

use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;

/**
 * OpenAI Chat message iterator.
 */
class MittwaldChatMessageIterator extends StreamedChatMessageIterator
{

    /**
     * {@inheritdoc}
     */
    public function doIterate(): \Generator
    {
        foreach ($this->iterator->getIterator() as $data) {
            $metadata = $data->usage ? $data->usage->toArray() : [];
            $message  = $this->createStreamedChatMessage(
                $data->choices[0]->delta->role ?? '',
                $data->choices[0]->delta->content ?? '',
                $metadata,
                $data->choices[0]->delta->toolCalls ?? null,
                $data->toArray(),
            );
            if ($data->usage !== null) {
                $message->setInputTokenUsage($data->usage->promptTokens ?? 0);
                $message->setOutputTokenUsage($data->usage->completionTokens ?? 0);
                $message->setTotalTokenUsage($data->usage->totalTokens ?? 0);
                $message->setReasoningTokenUsage($data->usage->completionTokenDetails->reasoningTokens ?? 0);
                $message->setCachedTokenUsage($data->usage->completionTokenDetails->cachedTokens ?? 0);
            }
            if (isset($data->choices[0]->finishReason)) {
                $this->setFinishReason($data->choices[0]->finishReason);
            }
            yield $message;
        }
    }

}
