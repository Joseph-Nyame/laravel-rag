<?php

namespace App\DTOs;

use Illuminate\Http\Request;

class RegularAuthDTO extends AuthDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly string $password,
        RegistrationType $registrationType = RegistrationType::REGULAR
    ) {
        parent::__construct($registrationType);
    }


    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->name ?? null,
            email: $request->email,
            password: $request->password
        );
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