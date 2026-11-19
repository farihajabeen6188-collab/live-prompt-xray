<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use XRay\Recorder\Redactor;

/**
 * Turns the messages sent to a model into redacted, display-ready rows. Shared by the live
 * platform decorator and the typing preview, so both show the context the same way.
 */
final readonly class MessageDescriber
{
    public function __construct(private Redactor $redactor)
    {
    }

    /**
     * @param array<mixed>|string|object $input
     *
     * @return list<array{role: string, content: string, truncated: bool, toolCallId?: string}>
     */
    public function describeInput(array|string|object $input): array
    {
        if (\is_string($input)) {
            return [$this->row('user', $input)];
        }
        if (!$input instanceof MessageBag) {
            return [$this->row('input', (string) json_encode($input, \JSON_PARTIAL_OUTPUT_ON_ERROR))];
        }

        return array_map($this->describe(...), $input->getMessages());
    }

    /**
     * @return array{role: string, content: string, truncated: bool, toolCallId?: string}
     */
    public function describe(MessageInterface $message): array
    {
        $role = $message->getRole()->value;

        if ($message instanceof SystemMessage) {
            return $this->row($role, (string) $message->getContent());
        }
        if ($message instanceof ToolCallMessage) {
            return [...$this->row($role, $message->asText() ?? ''), 'toolCallId' => $message->getToolCall()->getId()];
        }
        if ($message instanceof AssistantMessage) {
            $text = $message->asText() ?? '';
            foreach ($message->getToolCalls() as $toolCall) {
                $text .= ('' === $text ? '' : "\n").\sprintf('→ %s(%s)', $toolCall->getName(), json_encode($toolCall->getArguments(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
            }

            return $this->row($role, $text);
        }

        return $this->row($role, method_exists($message, 'asText') ? (string) $message->asText() : '');
    }

    /**
     * @return array{role: string, content: string, truncated: bool}
     */
    private function row(string $role, string $content): array
    {
        $redacted = $this->redactor->redactString($content);

        return ['role' => $role, 'content' => $redacted->value, 'truncated' => $redacted->truncated];
    }
}
