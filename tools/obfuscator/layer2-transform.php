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

class JunkMethodInjector extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\Class_) || empty($node->stmts)) {
            return null;
        }
        for ($i = 0; $i < 2; $i++) {
            $mname = '_' . bin2hex(random_bytes(5));
            $body = [];
            for ($j = 0; $j < mt_rand(2, 4); $j++) {
                $vname = '_' . bin2hex(random_bytes(3));
                $body[] = new Stmt\Expression(new Expr\Assign(
                    new Expr\Variable($vname),
                    new Expr\BinaryOp\BitwiseXor(
                        new Scalar\LNumber(mt_rand()),
                        new Expr\FuncCall(new Node\Name('strlen'), [
                            new Node\Arg(new Scalar\String_(bin2hex(random_bytes(4))))
                        ])
                    )
                ));
            }
            $body[] = new Stmt\Return_(new Expr\Variable($vname));
            $node->stmts[] = new Stmt\ClassMethod($mname, [
                'type' => Stmt\Class_::MODIFIER_PRIVATE,
                'stmts' => $body,
            ]);
        }
        return $node;
    }
}

class JunkPropertyInjector extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\Class_) || empty($node->stmts)) {
            return null;
        }
        $propName = '_' . bin2hex(random_bytes(5));
        $prop = new Stmt\Property(Stmt\Class_::MODIFIER_PRIVATE, [
            new Stmt\PropertyProperty($propName, new Scalar\LNumber(mt_rand()))
        ]);
        array_unshift($node->stmts, $prop);
        return $node;
    }
}

class ShuffleMethods extends NodeVisitorAbstract
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

function run_layer2(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $t1 = new NodeTraverser();
    $t1->addVisitor(new JunkPropertyInjector());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new JunkMethodInjector());
    $ast = $t2->traverse($ast);

    $t3 = new NodeTraverser();
    $t3->addVisitor(new ShuffleMethods());
    $ast = $t3->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer2($f, $f);
    echo "Layer 2 done: $f\n";
}
