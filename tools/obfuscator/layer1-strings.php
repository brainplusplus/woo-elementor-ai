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

class XorStringEncoder extends NodeVisitorAbstract
{
    private const DONE = '_xor_done';
    private int $constDepth = 0;
    private int $propDefaultDepth = 0;
    private int $paramDefaultDepth = 0;
    private string $xorKey;

    public function getKey(): string { return $this->xorKey; }

    public function __construct()
    {
        $this->xorKey = bin2hex(random_bytes(8));
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\ClassConst || $node instanceof Node\Const_) {
            $this->constDepth++;
        }
        if ($node instanceof Stmt\PropertyProperty) {
            $this->propDefaultDepth++;
        }
        if ($node instanceof Node\Param && $node->default !== null) {
            $this->paramDefaultDepth++;
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
        if ($node instanceof Node\Param && $node->default !== null) {
            $this->paramDefaultDepth--;
        }
        if ($this->constDepth > 0 || $this->propDefaultDepth > 0 || $this->paramDefaultDepth > 0) {
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

        $encrypted = $this->xorEncrypt($node->value);
        $hexKey = $this->xorKey;
        $hexEnc = bin2hex($encrypted);

        $result = new Expr\FuncCall(
            new Node\Name('self::_xs'),
            [
                new Node\Arg(new Scalar\String_($hexEnc)),
                new Node\Arg(new Scalar\String_($hexKey)),
            ]
        );
        $result->setAttribute(self::DONE, true);
        return $result;
    }

    private function xorEncrypt(string $data): string
    {
        $key = $this->xorKey;
        $result = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $result .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
        }
        return $result;
    }
}

class DecryptorInjector extends NodeVisitorAbstract
{
    private bool $injected = false;
    private string $xorKey;

    public function __construct(string $xorKey)
    {
        $this->xorKey = $xorKey;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($this->injected || !($node instanceof Stmt\Namespace_)) {
            return null;
        }

        $decryptor = $this->buildDecryptor();
        array_splice($node->stmts, 0, 0, $decryptor);
        $this->injected = true;
        return $node;
    }

    private function buildDecryptor(): array
    {
        $code = <<<'PHP'
<?php
function _xs(string $d, string $k): string {
    $r = '';
    $kl = strlen($k);
    for ($i = 0; $i < strlen($d); $i += 2) {
        $b = hexdec(substr($d, $i, 2));
        $r .= chr($b ^ ord($k[($i >> 1) % $kl]));
    }
    return $r;
}
PHP;
        $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
        $parser = (new ParserFactory())->createForHostVersion($lexer);
        $ast = $parser->parse($code);
        return $ast[0]->stmts;
    }
}

class GlobalDecryptorInjector extends NodeVisitorAbstract
{
    private bool $injected = false;
    private string $xorKey;

    public function __construct(string $xorKey)
    {
        $this->xorKey = $xorKey;
    }

    private function buildDecryptorMethod(): Stmt\ClassMethod
    {
        $rVar = new Expr\Variable('r');
        $dVar = new Expr\Variable('d');
        $kVar = new Expr\Variable('k');
        $klVar = new Expr\Variable('kl');
        $iVar = new Expr\Variable('i');
        $bVar = new Expr\Variable('b');

        return new Stmt\ClassMethod('_xs', [
            'type' => Stmt\Class_::MODIFIER_PRIVATE | Stmt\Class_::MODIFIER_STATIC,
            'params' => [
                new Node\Param($dVar, null, new Node\Name('string')),
                new Node\Param($kVar, null, new Node\Name('string')),
            ],
            'returnType' => new Node\Name('string'),
            'stmts' => [
                new Stmt\Expression(new Expr\Assign($rVar, new Scalar\String_(''))),
                new Stmt\Expression(new Expr\Assign($klVar, new Expr\FuncCall(new Node\Name('strlen'), [new Node\Arg($kVar)]))),
                new Stmt\For_([
                    'init' => [new Expr\Assign($iVar, new Scalar\LNumber(0))],
                    'cond' => [new Expr\BinaryOp\Smaller($iVar, new Expr\FuncCall(new Node\Name('strlen'), [new Node\Arg($dVar)]))],
                    'loop' => [new Expr\AssignOp\Plus($iVar, new Scalar\LNumber(2))],
                    'stmts' => [
                        new Stmt\Expression(new Expr\Assign($bVar,
                            new Expr\FuncCall(new Node\Name('hexdec'), [
                                new Node\Arg(new Expr\FuncCall(new Node\Name('substr'), [
                                    new Node\Arg($dVar),
                                    new Node\Arg($iVar),
                                    new Node\Arg(new Scalar\LNumber(2)),
                                ])),
                            ])
                        )),
                        new Stmt\Expression(new Expr\AssignOp\Concat($rVar,
                            new Expr\FuncCall(new Node\Name('chr'), [
                                new Node\Arg(new Expr\BinaryOp\BitwiseXor($bVar,
                                    new Expr\FuncCall(new Node\Name('ord'), [
                                        new Node\Arg(new Expr\ArrayDimFetch($kVar,
                                            new Expr\BinaryOp\Mod(
                                                new Expr\BinaryOp\Div($iVar, new Scalar\LNumber(2)),
                                                $klVar
                                            )
                                        ))
                                    ])
                                )),
                            ])
                        )),
                    ],
                ]),
                new Stmt\Return_($rVar),
            ],
        ]);
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($this->injected) {
            return null;
        }

        if ($node instanceof Stmt\Class_ && !empty($node->stmts)) {
            $node->stmts[] = $this->buildDecryptorMethod();
            $this->injected = true;
            return $node;
        }
        return null;
    }
}

function run_layer1(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $encoder = new XorStringEncoder();
    $t1 = new NodeTraverser();
    $t1->addVisitor($encoder);
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new GlobalDecryptorInjector($encoder->getKey()));
    $ast = $t2->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer1($f, $f);
    echo "Layer 1 done: $f\n";
}
