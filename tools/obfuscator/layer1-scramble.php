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

class CommentStripper extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        $node->setAttribute('comments', []);
        return $node;
    }
}

class StringEncoder extends NodeVisitorAbstract
{
    private const DONE = '_encoded';
    private int $constDepth = 0;
    private int $propDefaultDepth = 0;

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\ClassConst || $node instanceof Node\Const_) {
            $this->constDepth++;
        }
        if ($node instanceof Stmt\PropertyProperty) {
            $this->propDefaultDepth++;
        }
        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\ClassConst || $node instanceof Node\Const_) {
            $this->constDepth--;
            return null;
        }
        if ($node instanceof Stmt\PropertyProperty) {
            $this->propDefaultDepth--;
            return null;
        }
        if ($this->constDepth > 0 || $this->propDefaultDepth > 0) {
            return null;
        }
        if (!($node instanceof Scalar\String_) || $node->hasAttribute(self::DONE)) {
            return null;
        }
        if (strlen($node->value) < 3) {
            return null;
        }
        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $node->value)) {
            return null;
        }
        $hex = bin2hex($node->value);
        $wrapped = new Scalar\String_($hex);
        $wrapped->setAttribute(self::DONE, true);
        return new Expr\FuncCall(
            new Node\Name('hex2bin'),
            [new Node\Arg($wrapped)]
        );
    }
}

class JunkAppender extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\Class_) || empty($node->stmts)) {
            return null;
        }
        for ($i = 0; $i < 2; $i++) {
            $mname = '_' . bin2hex(random_bytes(5));
            $body = [];
            for ($j = 0; $j < 3; $j++) {
                $vname = '_' . bin2hex(random_bytes(3));
                $body[] = new Stmt\Expression(new Expr\Assign(
                    new Expr\Variable($vname),
                    new Expr\BinaryOp\Concat(
                        new Scalar\String_(bin2hex(random_bytes(4))),
                        new Expr\Cast\String_(new Expr\Variable('wpdb'))
                    )
                ));
            }
            $node->stmts[] = new Stmt\ClassMethod($mname, [
                'type' => Stmt\Class_::MODIFIER_PRIVATE,
                'stmts' => $body,
            ]);
        }
        return $node;
    }
}

function run_layer1(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $t1 = new NodeTraverser();
    $t1->addVisitor(new CommentStripper());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new StringEncoder());
    $ast = $t2->traverse($ast);

    $t3 = new NodeTraverser();
    $t3->addVisitor(new JunkAppender());
    $ast = $t3->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer1($f, $f);
    echo "Layer 1 done: $f\n";
}
