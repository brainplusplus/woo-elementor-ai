<?php

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

require_once __DIR__ . '/vendor/autoload.php';

class IntegrityCheckInjector extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\Class_)) {
            return null;
        }

        $method = new Stmt\ClassMethod('_ck', [
            'type' => Stmt\Class_::MODIFIER_PRIVATE | Stmt\Class_::MODIFIER_STATIC,
            'stmts' => [
                new Stmt\Return_(new Expr\BinaryOp\Identical(
                    new Expr\FuncCall(new Node\Name('md5_file'), [
                        new Node\Arg(new Expr\ConstFetch(new Node\Name('__FILE__'))),
                    ]),
                    new Expr\Variable('_h')
                )),
            ],
        ]);

        $node->stmts[] = $method;
        return $node;
    }
}

function run_layer5(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $t = new NodeTraverser();
    $t->addVisitor(new IntegrityCheckInjector());
    $ast = $t->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer5($f, $f);
    echo "Layer 5 done: $f\n";
}
