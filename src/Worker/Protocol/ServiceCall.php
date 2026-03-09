<?php

declare(strict_types=1);

namespace Convoy\Worker\Protocol;

final readonly class ServiceCall
{
    public function __construct(
        public string $id,
        public string $serviceClass,
        public string $method,
        /** @var list<mixed> */
        public array $args = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            serviceClass: $data['service'],
            method: $data['method'],
            args: $data['args'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => MessageType::ServiceCall->value,
            'id' => $this->id,
            'service' => $this->serviceClass,
            'method' => $this->method,
            'args' => $this->args,
        ];
    }
}
