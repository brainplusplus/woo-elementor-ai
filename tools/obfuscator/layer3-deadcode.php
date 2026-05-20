<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Lexer;
use PhpParser\PhpVersion;

class OpaquePredicateInjector extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\ClassMethod) || empty($node->stmts)) {
            return null;
        }

        $name = $node->name->toString();
        if (strpos($name, '__') === 0) {
            return null;
        }

        $isVoid = false;
        if ($node->returnType !== null) {
            $rt = $node->returnType;
            if ($rt instanceof Node\Identifier && $rt->toString() === 'void') {
                $isVoid = true;
            }
        }

        $wrapped = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ || $stmt instanceof Stmt\Throw_
                || $stmt instanceof Stmt\Goto_ || $stmt instanceof Stmt\Label) {
                $wrapped[] = $stmt;
                continue;
            }

            $predicate = $this->randomTruePredicate();
            $deadBranch = $this->generateDeadBranch($isVoid);

            $wrapped[] = new Stmt\If_($predicate, [
                'stmts' => [$stmt],
                'else' => new Stmt\Else_($deadBranch),
            ]);
        }
        $node->stmts = $wrapped;
        return $node;
    }

    private function randomTruePredicate(): Expr
    {
        $predicates = [
            fn() => new Expr\BinaryOp\Identical(
                new Scalar\LNumber(PHP_INT_SIZE),
                new Scalar\LNumber(PHP_INT_SIZE)
            ),
            fn() => new Expr\BinaryOp\Identical(
                new Expr\FuncCall(new Node\Name('strlen'), [new Node\Arg(new Scalar\String_(''))]),
                new Scalar\LNumber(0)
            ),
            fn() => new Expr\BinaryOp\NotIdentical(
                new Expr\FuncCall(new Node\Name('phpversion'), []),
                new Expr\ConstFetch(new Node\Name('false'))
            ),
            fn() => new Expr\BinaryOp\Greater(
                new Expr\FuncCall(new Node\Name('strlen'), [new Node\Arg(new Scalar\String_(bin2hex(random_bytes(3))))]),
                new Scalar\LNumber(0)
            ),
            fn() => new Expr\BooleanNot(
                new Expr\FuncCall(new Node\Name('is_null'), [
                    new Node\Arg(new Scalar\LNumber(mt_rand(1, 9999)))
                ])
            ),
        ];
        return $predicates[array_rand($predicates)]();
    }

    private function generateDeadBranch(bool $isVoid = false): array
    {
        $patterns = [
            fn() => $this->deadWpError(),
            fn() => $this->deadException(),
        ];
        if (!$isVoid) {
            $patterns[] = fn() => $this->deadReturn();
        }
        return [$patterns[array_rand($patterns)]()];
    }

    private function deadWpError(): Stmt
    {
        return new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('_' . bin2hex(random_bytes(3))),
            new Expr\New_(new Node\Name('WP_Error'), [
                new Node\Arg(new Scalar\String_(bin2hex(random_bytes(4)))),
                new Node\Arg(new Scalar\String_(bin2hex(random_bytes(6)))),
            ])
        ));
    }

    private function deadException(): Stmt
    {
        return new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('_' . bin2hex(random_bytes(3))),
            new Expr\New_(new Node\Name('RuntimeException'), [
                new Node\Arg(new Scalar\String_(bin2hex(random_bytes(8)))),
            ])
        ));
    }

    private function deadReturn(): Stmt
    {
        return new Stmt\Return_(new Expr\FuncCall(new Node\Name('array_map'), [
            new Node\Arg(new Expr\Closure([
                'stmts' => [
                    new Stmt\Return_(new Expr\Variable('_' . bin2hex(random_bytes(2)))),
                ],
            ])),
            new Node\Arg(new Expr\Array_([
                new Expr\ArrayItem(new Scalar\String_(bin2hex(random_bytes(4)))),
                new Expr\ArrayItem(new Scalar\String_(bin2hex(random_bytes(4)))),
            ])),
        ]));
    }
}

class RealisticDeadCodeInjector extends NodeVisitorAbstract
{
    private bool $injected = false;

    public function leaveNode(Node $node): ?Node
    {
        if ($this->injected || !($node instanceof Stmt\Class_) || empty($node->stmts)) {
            return null;
        }

        for ($i = 0; $i < 2; $i++) {
            $node->stmts[] = $this->generateDeadMethod();
        }

        $this->injected = true;
        return $node;
    }

    private function generateDeadMethod(): Stmt\ClassMethod
    {
        return $this->deadCacheMethod();
    }

    private function deadCacheMethod(): Stmt\ClassMethod
    {
        $keyVar = '_' . bin2hex(random_bytes(3));
        $dataVar = '_' . bin2hex(random_bytes(3));
        $resultVar = '_' . bin2hex(random_bytes(3));

        return new Stmt\ClassMethod('_' . bin2hex(random_bytes(5)), [
            'type' => Stmt\Class_::MODIFIER_PRIVATE,
            'params' => [
                new Node\Param(new Expr\Variable($keyVar), null, new Node\Name('string')),
                new Node\Param(new Expr\Variable($dataVar), null, new Node\Name('array')),
            ],
            'stmts' => [
                new Stmt\Expression(new Expr\Assign(
                    new Expr\Variable($resultVar),
                    new Expr\FuncCall(new Node\Name('wp_cache_get'), [
                        new Node\Arg(new Expr\Variable($keyVar)),
                        new Node\Arg(new Scalar\String_(bin2hex(random_bytes(4)))),
                    ])
                )),
                new Stmt\If_(
                    new Expr\BooleanNot(new Expr\Variable($resultVar)),
                    ['stmts' => [
                        new Stmt\Expression(new Expr\FuncCall(new Node\Name('wp_cache_set'), [
                            new Node\Arg(new Expr\Variable($keyVar)),
                            new Node\Arg(new Expr\FuncCall(new Node\Name('json_encode'), [
                                new Node\Arg(new Expr\Variable($dataVar)),
                            ])),
                            new Node\Arg(new Scalar\String_(bin2hex(random_bytes(4)))),
                            new Node\Arg(new Scalar\LNumber(3600)),
                        ])),
                    ]]
                ),
                new Stmt\Return_(new Expr\Variable($resultVar)),
            ],
        ]);
    }
}

class MethodShuffler extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\Class_) || count($node->stmts) < 4) {
            return null;
        }

        $constants = [];
        $others = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassConst) {
                $constants[] = $stmt;
            } else {
                $others[] = $stmt;
            }
        }
        shuffle($others);
        $node->stmts = array_merge($constants, $others);
        return $node;
    }
}

function run_layer3(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $t1 = new NodeTraverser();
    $t1->addVisitor(new RealisticDeadCodeInjector());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new OpaquePredicateInjector());
    $ast = $t2->traverse($ast);

    $t3 = new NodeTraverser();
    $t3->addVisitor(new MethodShuffler());
    $ast = $t3->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer3($f, $f);
    echo "Layer 3 done: $f\n";
}
