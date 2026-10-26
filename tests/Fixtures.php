<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/** The shared payload contract (schemas/v1, a copy of the service's) for tests. */
final class Fixtures
{
    public static function schemaDir(): string
    {
        return dirname(__DIR__).'/schemas/v1';
    }

    /** @return array<string, mixed> */
    public static function clientToken(): array
    {
        return json_decode((string) file_get_contents(self::schemaDir().'/fixtures/client-token.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> the schema's complaints; empty when $data fits */
    public static function violations(mixed $data, string $schema): array
    {
        $validator = new Validator;
        $object = json_decode(json_encode($data, JSON_THROW_ON_ERROR));
        // STRICT: the draft named by the schema's own $schema line (07, with if/then) instead of the library's draft-04.
        $validator->validate($object, json_decode((string) file_get_contents(self::schemaDir().'/'.$schema)), Constraint::CHECK_MODE_NORMAL | Constraint::CHECK_MODE_STRICT);

        return array_map(fn (array $error): string => "{$error['property']}: {$error['message']}", $validator->getErrors());
    }
}
