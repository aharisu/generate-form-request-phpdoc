<?php

namespace aharisu\GenerateFormRequestPHPDoc\Console;

use aharisu\GenerateFormRequestPHPDoc\RuleNode;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use ReflectionClass;

class GenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'form-request:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate PHPDoc for FormRequest';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dir = 'app/Http/Requests';
        $dir = base_path($dir);

        $classNames = [];
        if (is_dir($dir)) {
            $classMap = ClassMapGenerator::createMap($dir);
            ksort($classMap);
            foreach ($classMap as $className => $_path) {
                $classNames[] = $className;
            }
        }

        $lexer = new Lexer();
        $constExprParser = new ConstExprParser();
        $typeParser = new TypeParser();
        $phpDocParser = new PhpDocParser($typeParser, $constExprParser);

        foreach ($classNames as $className) {
            if (class_exists($className)) {
                $reflectionClass = new ReflectionClass($className);

                if ($reflectionClass->isSubclassOf(FormRequest::class) === false) {
                    continue;
                }

                if ($reflectionClass->isInstantiable() == false) {
                    continue;
                }

                $request = $this->laravel->make($className);
                if (method_exists($request, 'rules') === false) {
                    continue;
                }

                $rules = $request->rules();
                $rulesTree = $this->parseRules($rules);

                $phpDocNodeAry = $this->ruleNodeTreeToPropertyTagValueNode($rulesTree);

                $doc = $reflectionClass->getDocComment();
                $newDoc = null;
                if ($doc !== false) {
                    $tokens = new TokenIterator($lexer->tokenize($doc));
                    $phpDocNode = $phpDocParser->parse($tokens);

                    $phpDocNode->children = Arr::where($phpDocNode->children, static function ($node) use ($phpDocNodeAry) {
                        if ($node instanceof PhpDocTagNode) {
                            //同じ名前を持つノードが存在するなら、既存のタグ配列から削除する
                            $existsNode = Arr::first($phpDocNodeAry, fn (PhpDocTagNode $value, $key) => $value->name === $node->name);

                            return $existsNode === null;
                        } else {
                            return true;
                        }
                    });

                    $phpDocNode->children = array_merge($phpDocNode->children, $phpDocNodeAry);

                    $newDoc = self::getPHPDocText($phpDocNode);
                } else {
                    $newDoc = self::getPHPDocText(new PhpDocNode($phpDocNodeAry));
                }

                $filename = $reflectionClass->getFileName();
                $contents = $this->files->get($filename);
                if ($doc !== false) {
                    $contents = str_replace($doc, $newDoc, $contents);
                } else {
                    $classShortName = $reflectionClass->getShortName();

                    $replace = "{$newDoc}\n";
                    $pos = strpos($contents, "final class {$classShortName}") ?: strpos($contents, "class {$classShortName}");
                    if ($pos !== false) {
                        $contents = substr_replace($contents, $replace, $pos, 0);
                    }
                }

                if ($this->files->put($filename, $contents)) {
                    $this->info('Written new phpDocBlock to ' . $filename);
                }
            }
        }

        return 0;
    }

    /**
     * @param  string[]  $validationRules
     */
    private static function isRequired(array $validationRules): bool
    {
        if (in_array('nullable', $validationRules, strict: true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  string[]  $validationRules
     */
    private static function getTypeText(array $validationRules): string
    {
        foreach ($validationRules as $rule) {
            if (gettype($rule) === 'string') {
                $rule = Arr::first(explode(':', $rule));
                $text = match ($rule) {
                    'integer', 'numeric' => 'int',
                    'string', 'email' => 'string',
                    'boolean' => 'bool',
                    'array' => 'array',
                    'file' => '\Illuminate\Http\UploadedFile',
                    default => null,
                };
                if ($text !== null) {
                    return $text;
                }
            }
        }

        return 'mixed';
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function parseRules(array $rules): RuleNode
    {
        $tree = new RuleNode('root', true, 'root');

        foreach ($rules as $name => $validations) {
            if (is_string($validations)) {
                $validations = explode('|', $validations);
            }

            if (is_array($validations) === false) {
                $this->output->error('required array or string in validation rules.');

                continue;
            }

            $typeText = self::getTypeText($validations);
            $isRequired = self::isRequired($validations);

            $parts = explode('.', $name);
            $firstKey = array_key_first($parts);
            $lastKey = array_key_last($parts);

            $node = $tree;
            //対応するノードに対して矛盾がないかチェック
            foreach ($parts as $key => $part) {
                //名前部分が*であれば、インデックスアクセス配列として処理する
                $isIndexed = $part === '*';
                $isLastPart = $key === $lastKey;
                $arrayTypeName = $isIndexed ? 'indexed-array' : 'shaped-array';

                //入れ子構造のルール指定の整合性チェック
                if ($key === $firstKey) {
                    //最初の要素はチェックをしない
                } elseif ($node->typeName === null || $node->typeName === 'array') {
                    //対応するノードの型名を詳細な配列型名に更新する
                    $node->typeName = $arrayTypeName;
                } elseif ($node->typeName === 'indexed-array') {
                    if ($isIndexed === false) {
                        //インデックス配列に対して、構造を持った配列のルールを混ぜようとしているのでエラー
                        $this->output->error("the indexed-array and the shaped-array cannot be specified together. {$name}");
                        break;
                    }
                } elseif ($node->typeName === 'shaped-array') {
                    if ($isIndexed) {
                        //構造を持った配列に対して、インデックス配列のルールを混ぜようとしているためエラー
                        $this->output->error("the indexed-array and the shaped-array cannot be specified together. {$name}");
                        break;
                    }
                } else {
                    //対応するノードがarray以外なのに、構造を指定している場合はエラー
                    $this->output->error("Inconsistency in the rules. {$name}: {$part} : {$node->typeName}");
                    break;
                }

                if (array_key_exists($part, $node->children)) {
                    //重複する名称へのルール指定ならエラーにする
                    //ただし、配列などの入れ子構造に対するルール指定の際も重複する名称となりえるため、ドット区切りで最後のパーツ名が重複しているときだけエラーにする
                    if ($isLastPart) {
                        $this->output->error("Duplicate names rule specification. {$name}: {$part} : {$node->typeName}");
                        break;
                    }
                } else {
                    //対応するノードが存在しない場合は新しいノードを作成する
                    //※中間パーツに対しては、Requiredや型指定は指定しない
                    $childNode = new RuleNode(
                        $part,
                        $isLastPart ? $isRequired : null,
                        $isLastPart ? $typeText : null,
                    );
                    $node->children[$childNode->name] = $childNode;
                }

                $node = $node->children[$part];
            }
        }

        return $tree;
    }

    /**
     * @return PhpDocTagNode[]
     */
    private function ruleNodeTreeToPropertyTagValueNode(RuleNode $tree): array
    {
        $phpDocTagNodeAry = [];

        foreach ($tree->children as $node) {
            $typeNode = self::ruleNodeToPHPDocTypeNode($node);

            $phpDocTagNodeAry[] = new PhpDocTagNode(
                '@property-read',
                new PropertyTagValueNode($typeNode, '$' . $node->name, '')
            );
        }

        return $phpDocTagNodeAry;
    }

    private static function ruleNodeToPHPDocTypeNode(RuleNode $node): TypeNode
    {
        /** @var TypeNode | null */
        $type = null;

        switch ($node->typeName) {
            case null:
                var_dump($node);
                throw new Exception("unknown type. {$node->name}");
            case 'shaped-array':
                if (count($node->children) === 0) {
                    throw new Exception("required child property information. {$node->name}: {$node->typeName}");
                }
                $items = [];
                foreach ($node->children as $childNode) {
                    $items[] = new ArrayShapeItemNode(
                        new IdentifierTypeNode($childNode->name),
                        false,
                        self::ruleNodeToPHPDocTypeNode($childNode)
                    );
                }

                $type = new ArrayShapeNode($items);
                break;
            case 'indexed-array':
                if (count($node->children) !== 1) {
                    throw new Exception("only 1 child property information. {$node->name}: {$node->typeName}");
                }

                $type = new ArrayTypeNode(
                    self::ruleNodeToPHPDocTypeNode($node->children[array_key_first($node->children)])
                );
                break;
            default:
                if (count($node->children) !== 0) {
                    var_dump($node);
                    throw new Exception("don't have child property information. {$node->name}: {$node->typeName}");
                }
                $type = new IdentifierTypeNode($node->typeName);
                break;
        }

        if ($node->isRequired === false) {
            $type = new NullableTypeNode($type);
        }

        return $type;
    }

    private static function getPHPDocText(PhpDocNode $node): string
    {
        $children = [];
        foreach ($node->children as $child) {
            $s = (string)$child;
            if ($s !== '') {
                $s = implode("\n * ", explode(PHP_EOL, $s));
            }

            $children[] = $s === '' ? '' : ' ' . $s;
        }

        return "/**\n *" . implode("\n *", $children) . "\n */";
    }
}
