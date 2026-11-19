<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Classic RAG: retrieves the docs closest to the user's question and appends the relevant ones
 * to the system prompt. Runs after the system prompt processor (priority -30).
 */
#[AsInputProcessor(priority: -100)]
final readonly class DocsContextProcessor implements InputProcessorInterface
{
    /** Cosine distance: 0 is identical, 1 is unrelated. */
    private const float MAX_DISTANCE = 0.78;

    public function __construct(
        #[Autowire(service: 'app.docs_retriever')]
        private RetrieverInterface $retriever,
    ) {
    }

    public function processInput(Input $input): void
    {
        $messages = $input->getMessageBag();
        // The latest user message (MessageBag::getUserMessage() returns the first one of a conversation).
        $question = null;
        foreach ($messages->getMessages() as $message) {
            if ($message instanceof UserMessage) {
                $question = $message->asText();
            }
        }
        if (null === $question || '' === trim($question)) {
            return;
        }

        $relevant = array_filter(
            [...$this->retriever->retrieve($question, ['maxItems' => 3])],
            static fn (VectorDocumentInterface $doc): bool => null !== $doc->getScore() && $doc->getScore() <= self::MAX_DISTANCE,
        );
        if ([] === $relevant) {
            return;
        }

        $context = implode("\n", array_map(
            static fn (VectorDocumentInterface $doc): string => \sprintf('[%s] %s', (string) $doc->getId(), (string) $doc->getMetadata()->getText()),
            $relevant,
        ));
        $system = $messages->getSystemMessage();
        $prompt = (null === $system ? '' : $system->getContent()."\n\n")."# Context from the Nimbus docs\nCite the document id in brackets.\n".$context;

        $input->setMessageBag($messages->withSystemMessage(Message::forSystem($prompt)));
    }
}
