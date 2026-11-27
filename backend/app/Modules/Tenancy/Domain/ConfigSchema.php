<?php

namespace App\Modules\Tenancy\Domain;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a school config body against Schemas/school-config.schema.json.
 * Every object in the schema forbids unknown keys, so a setting that is not
 * in the schema (for example one letting a clerk edit locked marks) can
 * never be saved.
 */
class ConfigSchema
{
    public const ID = 'https://campustab.local/schemas/school-config.json';

    private ?Validator $validator = null;

    public static function path(): string
    {
        return app_path('Modules/Tenancy/Schemas/school-config.schema.json');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, list<string>> errors keyed by JSON pointer; empty when valid
     */
    public function errors(array $body): array
    {
        $data = json_decode((string) json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
        $result = $this->validator()->validate($data, self::ID);

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();

        if ($error === null) {
            return ['/' => ['The config is not valid.']];
        }

        /** @var array<string, list<string>> $formatted */
        $formatted = (new ErrorFormatter)->format($error, true);

        return $formatted;
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->setMaxErrors(20);
            $this->validator->resolver()?->registerFile(self::ID, self::path());
        }

        return $this->validator;
    }
}
