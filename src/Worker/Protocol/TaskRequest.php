<?php

declare(strict_types=1);

namespace Convoy\Worker\Protocol;

final readonly class TaskRequest
{
    public function __construct(
        public string $id,
        public string $taskClass,
        /** @var array<string, mixed> */
        public array $constructorArgs,
        /** @var array<string, mixed> */
        public array $contextAttrs = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            taskClass: $data['task'],
            constructorArgs: $data['args'] ?? [],
            contextAttrs: $data['context'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => MessageType::TaskRequest->value,
            'id' => $this->id,
            'task' => $this->taskClass,
            'args' => $this->constructorArgs,
            'context' => $this->contextAttrs,
        ];
    }
}
