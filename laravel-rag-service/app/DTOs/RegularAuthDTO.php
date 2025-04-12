<?php

namespace App\DTOs;

class RegularAuthDTO extends AuthDTO
{
    public function __construct(
        ?string $name,
        public readonly ?string $email,
        public readonly string $password,
        RegistrationType $registrationType = RegistrationType::REGULAR
    ) {
        parent::__construct($registrationType, $name, $email);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            email: $data['email'],
            password: $data['password']
        );
    }
}