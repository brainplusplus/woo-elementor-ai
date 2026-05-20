<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Lexer;
use PhpParser\PhpVersion;

class IfFlattener extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if (!($node instanceof Stmt\If_) || !empty($node->elseifs)) {
            return null;
        }

        $stmts = [];
        $endLabel = '_L' . bin2hex(random_bytes(3));

        if ($node->else !== null) {
            $elseLabel = '_L' . bin2hex(random_bytes(3));
            $stmts[] = new Stmt\If_(
                new Expr\BooleanNot($node->cond),
                ['stmts' => [new Stmt\Goto_($elseLabel)]]
            );
            foreach ($node->stmts as $s) {
                $stmts[] = $s;
            }
            $stmts[] = new Stmt\Goto_($endLabel);
            $stmts[] = new Stmt\Label($elseLabel);
            foreach ($node->else->stmts as $s) {
                $stmts[] = $s;
            }
            $stmts[] = new Stmt\Label($endLabel);
        } else {
            $stmts[] = new Stmt\If_(
                new Expr\BooleanNot($node->cond),
                ['stmts' => [new Stmt\Goto_($endLabel)]]
            );
            foreach ($node->stmts as $s) {
                $stmts[] = $s;
            }
            $stmts[] = new Stmt\Label($endLabel);
        }

        return $stmts;
    }
}

class MethodGotoFlattener extends NodeVisitorAbstract
{
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callStatic',
        '__get', '__set', '__isset', '__unset',
        '__sleep', '__wakeup', '__toString', '__invoke',
        '__set_state', '__clone', '__debugInfo',
        '__serialize', '__unserialize',
    ];

    public function leaveNode(Node $node): ?Node
    {
        if (!($node instanceof Stmt\ClassMethod)) {
            return null;
        }

        if ($node->stmts === null || count($node->stmts) < 4) {
            return null;
        }

        $name = $node->name->toString();
        if (in_array($name, self::MAGIC_METHODS, true)) {
            return null;
        }

        $chunks = $this->chunkStmts($node->stmts);
        if (count($chunks) < 2) {
            return null;
        }

        $labels = [];
        foreach ($chunks as $i => $chunk) {
            $labels[$i] = '_L' . bin2hex(random_bytes(3));
        }

        $chunkBlocks = [];
        foreach ($chunks as $i => $chunk) {
            $block = [new Stmt\Label($labels[$i])];
            foreach ($chunk as $stmt) {
                $block[] = $stmt;
            }
            if ($i < count($chunks) - 1) {
                $block[] = new Stmt\Goto_($labels[$i + 1]);
            }
            $chunkBlocks[$i] = $block;
        }

        $firstLabel = $labels[0];
        $order = range(0, count($chunkBlocks) - 1);
        shuffle($order);

        $flattened = [new Stmt\Goto_($firstLabel)];
        foreach ($order as $idx) {
            foreach ($chunkBlocks[$idx] as $stmt) {
                $flattened[] = $stmt;
            }
        }

        $node->stmts = $flattened;
        return $node;
    }

    private function chunkStmts(array $stmts): array
    {
        $chunks = [];
        $pos = 0;
        $total = count($stmts);
        while ($pos < $total) {
            $size = mt_rand(2, 3);
            $chunk = array_slice($stmts, $pos, $size);
            if (!empty($chunk)) {
                $chunks[] = $chunk;
            }
            $pos += $size;
        }
        return $chunks;
    }
}

function run_layer2(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $t1 = new NodeTraverser();
    $t1->addVisitor(new IfFlattener());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new MethodGotoFlattener());
    $ast = $t2->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer2($f, $f);
    echo "Layer 2 flow done: $f\n";
}
