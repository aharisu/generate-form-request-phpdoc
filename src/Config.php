<?php

namespace aharisu\GenerateFormRequestPHPDoc;

/**
 * @property-read string $filename
 * @property-read string $form_request_extends
 * @property-read bool $default_write
 * @property-read string[] $scan_dirs
 */
class Config
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $config;

    public function __construct(
    ) {
        $this->config = config('generate-form-request-phpdoc');
    }

    public function __get(string $name): mixed
    {
        return $this->config[$name] ?? null;
    }
}
