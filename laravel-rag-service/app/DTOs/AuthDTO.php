<?php

namespace App\DTOs;

enum RegistrationType: string
{
    case REGULAR = 'regular';
    case GOOGLE = 'google';
    case FACEBOOK = 'facebook';
    case X = 'x';
    case GITHUB = 'github';
}

abstract class AuthDTO
{
    public function __construct(
        public readonly RegistrationType $registrationType,
        public readonly ?string $name = null,
        public readonly ?string $email = null
    ) {}
}