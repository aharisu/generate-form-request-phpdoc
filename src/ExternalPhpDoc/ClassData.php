<?php

namespace aharisu\GenerateFormRequestPHPDoc\ExternalPhpDoc;

class ClassData
{
    public function __construct(
        public readonly string $name,
        public readonly string $namespace,
        public string $phpDoc,
    ) {
    }
}
