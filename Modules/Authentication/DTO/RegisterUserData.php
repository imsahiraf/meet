<?php

namespace Modules\Authentication\DTO;

final class RegisterUserData
{
    public function __construct(
        public readonly string $fullName,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $password,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fullName: $data['full_name'],
            email: mb_strtolower($data['email']),
            phone: $data['phone'],
            password: $data['password'],
        );
    }
}
