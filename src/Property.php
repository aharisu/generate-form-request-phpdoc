<?php

namespace aharisu\GenerateFormRequestPHPDoc;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;

class Property
{
    public function __construct(
        public string $name,
        public TypeNode $type,
        public bool $isRequired,
    ) {
    }
}
