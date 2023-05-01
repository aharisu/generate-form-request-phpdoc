<?php

namespace aharisu\GenerateFormRequestPHPDoc\ExternalPhpDoc;

use aharisu\GenerateFormRequestPHPDoc\Config;
use Exception;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class ExternalPhpDocFile
{
    public function __construct(
        private readonly FileSystem $files,
        private readonly string $path,
    ) {
    }

    /**
     * @var ClassData[]
     */
    public array $classes = [];

    public static function load(Filesystem $files, string $path): self
    {
        $self = new self($files, $path);

        $text = false;
        try {
            $text = $files->get($path);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        //外部ファイルが読み込めなければ空の状態で終了
        if ($text === false) {
            return $self;
        }

        $parser = self::createParser();
        $errorHandler = new \PhpParser\ErrorHandler\Collecting();

        $errors = [];
        $stmts = $parser->parse($text, $errorHandler);

        //パース時にエラーが発生したら空の状態で終了
        if ($errorHandler->hasErrors()) {
            foreach ($errorHandler->getErrors() as $error) {
                $errors[] = $error->getRawMessage();
            }
            echo join(PHP_EOL, $errors);

            return $self;
        }

        //パース結果が存在しなければ空の状態で終了
        if ($stmts === null) {
            return $self;
        }

        $visitor = new class() extends NodeVisitorAbstract
        {
            private ?string $namespace = null;

            /**
             * @var ClassData[]
             */
            public array $classes = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name->toString();
                }

                if ($node instanceof Node\stmt\Class_) {
                    $className = $node->name->toString();
                    $classNameFull = $this->namespace . '\\' . $className;

                    $comments = $node->getComments();
                    $comments = implode('\n', array_map(function ($comment) {
                        return $comment->getText();
                    }, $comments));

                    $this->classes[$classNameFull] = new ClassData(
                        $className,
                        $this->namespace,
                        $comments,
                    );
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        $self->classes = $visitor->classes;

        return $self;
    }

    private static function createParser(): Parser
    {
        $lexer = new \PhpParser\Lexer([
            'usedAttributes' => [
                'comments',
                //'startLine',
                //'endLine',
                //'startTokenPos',
                //'endTokenPos',
                //'startFilePos',
                //'endFilePos',
            ],
        ]);

        $factory = new ParserFactory();

        return $factory->create(ParserFactory::PREFER_PHP7, $lexer);
    }

    public function getClassPhpDoc(string $namespace, string $className): string|false
    {
        $fullClassName = $namespace . '\\' . $className;

        $classData = $this->classes[$fullClassName] ?? null;
        if ($classData === null) {
            return false;
        } else {
            return $classData->phpDoc;
        }
    }

    public function replaceClassPhpDoc(string $namespace, string $className, string $newPhpDocText): void
    {
        $fullClassName = $namespace . '\\' . $className;
        $classData = $this->classes[$fullClassName] ?? null;
        if ($classData === null) {
            $this->classes[$fullClassName] = new ClassData(
                $className,
                $namespace,
                $newPhpDocText
            );
        } else {
            $classData->phpDoc = $newPhpDocText;
        }
    }


    public function outputExternalFile(Config $config): void
    {
        $texts = ["<?php"];
        foreach ($this->classes as $classData) {
            $text = <<<EOT
namespace $classData->namespace {
$classData->phpDoc
class $classData->name extends $config->form_request_extends {}
}
EOT;
            $texts[] = $text;
        }
        $contents = join(PHP_EOL . PHP_EOL, $texts) . PHP_EOL;
        $this->files->put($this->path, $contents);
    }
}
