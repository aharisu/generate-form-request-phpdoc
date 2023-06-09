<?php

namespace aharisu\GenerateFormRequestPHPDoc\Console;

use aharisu\GenerateFormRequestPHPDoc\Config;
use aharisu\GenerateFormRequestPHPDoc\ExternalPhpDoc\ExternalPhpDocFile;
use aharisu\GenerateFormRequestPHPDoc\RuleNode;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest;
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
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
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
    protected $signature = 'form-request:generate {targets?* : The target files} {--write : Write in FormRequest class file}';

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
        $config = new Config();

        $dirs = $config->scan_dirs;
        $classNames = $this->getTargetClasses($dirs);

        $isWrite = $this->option('write');
        //オプション指定がない場合は、デフォルト設定に従う
        if ($isWrite == false) {
            $isWrite = $config->default_write;
        }

        $externalPhpDocFile = null;
        //外部ファイルに書き込む場合は
        if ($isWrite === false) {
            //外部ファイルの内容を読み込みます
            $externalPhpDocFileName = $config->filename;
            $externalPhpDocFileName = base_path($externalPhpDocFileName);
            $externalPhpDocFile = ExternalPhpDocFile::load($this->files, $externalPhpDocFileName);
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

                try {
                    $request = $reflectionClass->newInstanceWithoutConstructor();
                    if (method_exists($request, 'rules') === false) {
                        continue;
                    }

                    $rules = $request->rules();
                } catch (Exception $e) {
                    $this->error(implode(PHP_EOL, [
                        'An error occurred.',
                        "Class: {$className}",
                        "Message: {$e->getMessage()}",
                    ]));

                    continue;
                }
                $rulesTree = $this->parseRules($rules);

                $phpDocNodeAry = $this->ruleNodeTreeToPropertyTagValueNode($rulesTree);

                $doc = $this->getClassPhpDoc($reflectionClass, $externalPhpDocFile);
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

                $this->outputClassPhpDoc(
                    $reflectionClass,
                    $externalPhpDocFile,
                    $doc,
                    $newDoc
                );
            }
        }

        //外部ファイルから読込みを行っている場合は、書き込みを行う
        if ($externalPhpDocFile !== null) {
            $externalPhpDocFile->outputExternalFile($config);
        }

        return 0;
    }

    /**
     * @param string[] $dirs
     * @return string[]
     */
    private function getTargetClasses(array $dirs): array
    {
        $targets = $this->argument('targets');
        $targetClasses = [];
        $targetFiles = [];
        foreach ($targets as $target) {
            if (class_exists($target)) {
                $targetClasses[] = $target;
            } elseif (($path = realpath($target)) !== false) {
                $targetFiles[] = $path;
            }
        }

        $isTargetSpecify = count($targets) !== 0;
        $classNames = [];
        foreach ($dirs as $dir) {
            $dir = $this->toAbsolutePath($dir);
            if (is_dir($dir)) {
                $classMap = ClassMapGenerator::createMap($dir);
                ksort($classMap);
                foreach ($classMap as $className => $path) {
                    //引数でファイル指定がない場合は全体を対象にする
                    //もしくは、引数で指定されたファイルのみ対象にする
                    if ($isTargetSpecify === false
                        || (in_array($path, $targetFiles, true) || in_array($className, $targetClasses, true))
                    ) {
                        $classNames[] = $className;
                    }
                }
            }
        }

        return $classNames;
    }

    private function toAbsolutePath(string $path): string
    {
        // Windowsの絶対パスの場合
        if (preg_match('/^[a-zA-Z]:\\\\/', $path)) {
            return $path;
        }

        // Unixの絶対パスの場合
        if (substr($path, 0, 1) === '/') {
            return $path;
        }

        return base_path($path);
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
                    'integer', 'int', 'digits', 'digits_between' => 'int',
                    'numeric' => 'int|float',
                    'string', 'email', 'url',
                        'alpha', 'alpha_dash', 'alpha_num',
                        'ip', 'ipv4', 'ipv6',
                        'timezone', 'uuid', 'ulid' => 'string',
                    'boolean' => 'bool',
                    'array' => 'array',
                    'file', 'image', 'mimes', 'mimetypes' => '\Illuminate\Http\UploadedFile',
                    'json' => 'mixed',
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
                } elseif ($node->typeName === null || $node->typeName === 'array' || $node->typeName === 'mixed') {
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
                $typeName = $node->typeName;
                // 型が|で区切られている場合は、
                if (strpos($typeName, '|') !== false) {
                    //UnionTypeNodeに変換する
                    $type = new UnionTypeNode(
                        array_map(
                            fn($name) => new IdentifierTypeNode($name),
                            explode('|', $typeName)));
                } else {
                    $type = new IdentifierTypeNode($node->typeName);
                }
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

    private function getClassPhpDoc(
        ReflectionClass $reflectionClass,
        ?ExternalPhpDocFile $externalPhpDocFile,
    ): string|false {
        if ($externalPhpDocFile !== null) {
            $doc = $externalPhpDocFile->getClassPhpDoc(
                $reflectionClass->getNamespaceName(),
                $reflectionClass->getShortName()
            );
        } else {
            $doc = $reflectionClass->getDocComment();
        }

        return $doc;
    }

    private function outputClassPhpDoc(
        ReflectionClass $reflectionClass,
        ?ExternalPhpDocFile $externalPhpDocFile,
        string|false $oldPhpDocText,
        string $newPhpDocText
    ): void {
        if ($externalPhpDocFile === null) {
            $filename = $reflectionClass->getFileName();
            $contents = $this->files->get($filename);
            if ($oldPhpDocText !== false) {
                $contents = str_replace($oldPhpDocText, $newPhpDocText, $contents);
            } else {
                $classShortName = $reflectionClass->getShortName();

                $replace = "{$newPhpDocText}\n";
                $pos = strpos($contents, "final class {$classShortName}") ?: strpos($contents, "class {$classShortName}");
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, 0);
                }
            }

            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to ' . $filename);
            }
        } else {
            $externalPhpDocFile->replaceClassPhpDoc($reflectionClass->getNamespaceName(), $reflectionClass->getShortName(), $newPhpDocText);
        }
    }
}
