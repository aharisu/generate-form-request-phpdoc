<?php

namespace aharisu\GenerateFormRequestPHPDoc;

class RuleNode
{
    public readonly string $name;

    public readonly bool|null $isRequired;

    public string|null $typeName;

    /**
     * @var string[]
     */
    public array $otherAttributes = [];

    /**
     * @var array<string, RuleNode>
     */
    public array $children = [];

    public function __construct(string $name, bool|null $isRequired, string|null $typeText)
    {
        $this->name = $name;
        $this->isRequired = $isRequired;
        $this->typeName = $typeText;
    }
}
