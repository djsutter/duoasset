<?php

namespace App\Tax\Application;

use App\Enums\Schedule3Method;

final class Schedule3MethodResolver
{
    private const SESSION_KEY = 'schedule3.method';

    private const DEFAULT_METHOD = Schedule3Method::Pool;

    public function resolve(): Schedule3Method
    {
        $value = session(self::SESSION_KEY);

        if (! $value) {
            return self::DEFAULT_METHOD;
        }

        return Schedule3Method::tryFrom($value)
            ?? self::DEFAULT_METHOD;
    }

    public function set(Schedule3Method $method): void
    {
        session([self::SESSION_KEY => $method->value]);
    }
}
