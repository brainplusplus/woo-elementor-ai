<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Lexer;
use PhpParser\PhpVersion;

class SymbolCollector extends NodeVisitorAbstract
{
    public array $methodMap = [];
    public array $propertyMap = [];
    private array $reservedNames;

    public function __construct()
    {
        $magic = ['__construct','__destruct','__call','__callStatic','__get','__set',
            '__isset','__unset','__sleep','__wakeup','__toString','__invoke',
            '__set_state','__clone','__debugInfo','__serialize','__unserialize'];
        $wp = ['init','admin_init','admin_menu','wp_enqueue_scripts','admin_enqueue_scripts',
            'rest_api_init','register_routes','plugin_action_links','settings_init',
            'sanitize_settings','get_settings','get','activate_license','deactivate_license',
            'verify_license','is_licensed','get_machine_key','get_masked_license',
            'render','render_page','display','save','process','handle','execute'];
        $this->reservedNames = array_flip(array_merge($magic, $wp));
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\ClassMethod) {
            $name = $node->name->toString();
            $isPublic = ($node->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0
                || $node->flags === 0;
            if (!$isPublic && !isset($this->reservedNames[$name])) {
                if (!isset($this->methodMap[$name])) {
                    $this->methodMap[$name] = '_' . bin2hex(random_bytes(5));
                }
            }
        }

        if ($node instanceof Stmt\Property) {
            $isPublic = ($node->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0
                || $node->flags === 0;
            if (!$isPublic) {
                foreach ($node->props as $prop) {
                    $name = $prop->name->toString();
                    if (!isset($this->propertyMap[$name])) {
                        $this->propertyMap[$name] = '_' . bin2hex(random_bytes(5));
                    }
                }
            }
        }
        return null;
    }
}

class SymbolRenamer extends NodeVisitorAbstract
{
    /** @var array<string, string> method name → scrambled name */
    private array $methodMap = [];

    /** @var array<string, string> property name → scrambled name */
    private array $propertyMap = [];

    /** @var array<string, string> local var name → scrambled name */
    private array $varMap = [];

    /** @var array<string, bool> names that must NOT be renamed (PHP magic, WP hooks, etc.) */
    private array $reservedNames;

    private string $currentClass = '';

    private int $scopeDepth = 0;

    private array $scopeVarMaps = [];

    public function __construct(array $methodMap, array $propertyMap)
    {
        $this->methodMap = $methodMap;
        $this->propertyMap = $propertyMap;
        $magic = ['__construct','__destruct','__call','__callStatic','__get','__set',
            '__isset','__unset','__sleep','__wakeup','__toString','__invoke',
            '__set_state','__clone','__debugInfo','__serialize','__unserialize'];
        $wp = ['init','admin_init','admin_menu','wp_enqueue_scripts','admin_enqueue_scripts',
            'rest_api_init','register_routes','plugin_action_links','settings_init',
            'sanitize_settings','get_settings','get','activate_license','deactivate_license',
            'verify_license','is_licensed','get_machine_key','get_masked_license',
            'render','render_page','display','save','process','handle','execute'];
        $this->reservedNames = array_flip(array_merge($magic, $wp));
    }

    private function genName(string $prefix = ''): string
    {
        return '_' . bin2hex(random_bytes(5));
    }

    // ── Class tracking ──────────────────────────────────

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\Class_) {
            $this->currentClass = $node->name->toString();
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->scopeDepth++;
            $this->scopeVarMaps[$this->scopeDepth] = [];

            // Rename private/protected methods (not public, not magic, not reserved)
            $name = $node->name->toString();
            $isPublic = ($node->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0
                || $node->flags === 0; // no modifier = public

            if (isset($this->methodMap[$name])) {
                $node->name = new Node\Identifier($this->methodMap[$name]);
            }

            // Rename parameters
            foreach ($node->params as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $origName = $param->var->name;
                    if (!$this->isReservedVar($origName)) {
                        $newName = $this->genName();
                        $this->scopeVarMaps[$this->scopeDepth][$origName] = $newName;
                        $this->varMap[$origName . '@' . $this->scopeDepth] = $newName;
                        $param->var->name = $newName;
                    }
                }
            }
        }

        if ($node instanceof Stmt\Function_) {
            $this->scopeDepth++;
            $this->scopeVarMaps[$this->scopeDepth] = [];

            foreach ($node->params as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $origName = $param->var->name;
                    if (!$this->isReservedVar($origName)) {
                        $newName = $this->genName();
                        $this->scopeVarMaps[$this->scopeDepth][$origName] = $newName;
                        $param->var->name = $newName;
                    }
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        // Rename private/protected properties
        if ($node instanceof Stmt\Property) {
            foreach ($node->props as $prop) {
                $name = $prop->name->toString();
                if (isset($this->propertyMap[$name])) {
                    $prop->name = new VarLikeIdentifier($this->propertyMap[$name]);
                }
            }
        }

        // Rename local variables (not $this, $wpdb, superglobals, etc.)
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $name = $node->name;
            if ($this->isReservedVar($name)) {
                return null;
            }

            // Check scope-specific map first
            for ($s = $this->scopeDepth; $s >= 1; $s--) {
                if (isset($this->scopeVarMaps[$s][$name])) {
                    $node->name = $this->scopeVarMaps[$s][$name];
                    return $node;
                }
            }
        }

        // Rename method calls
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
            if (isset($this->methodMap[$name])) {
                $node->name = new Node\Identifier($this->methodMap[$name]);
            }
        }

        // Rename property access
        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
            if (isset($this->propertyMap[$name])) {
                $node->name = new Node\Identifier($this->propertyMap[$name]);
            }
        }

        // Rename static property access (self::$prop, static::$prop, ClassName::$prop)
        if ($node instanceof Expr\StaticPropertyFetch && $node->name instanceof VarLikeIdentifier) {
            $name = $node->name->toString();
            if (isset($this->propertyMap[$name])) {
                $node->name = new VarLikeIdentifier($this->propertyMap[$name]);
            }
        } elseif ($node instanceof Expr\StaticPropertyFetch && is_string($node->name)) {
            if (isset($this->propertyMap[$node->name])) {
                $node->name = new VarLikeIdentifier($this->propertyMap[$node->name]);
            }
        }

        // Rename static method calls (self::method)
        if ($node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
            if (isset($this->methodMap[$name])) {
                $node->name = new Node\Identifier($this->methodMap[$name]);
            }
        }

        // Assignments — track new local variables
        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $name = $node->var->name;
            if (!$this->isReservedVar($name) && $this->scopeDepth > 0) {
                // Already renamed via param or previous assignment?
                for ($s = $this->scopeDepth; $s >= 1; $s--) {
                    if (isset($this->scopeVarMaps[$s][$name])) {
                        $node->var->name = $this->scopeVarMaps[$s][$name];
                        return $node;
                    }
                }
                // New variable — register it
                $newName = $this->genName();
                $this->scopeVarMaps[$this->scopeDepth][$name] = $newName;
                $node->var->name = $newName;
            }
        }

        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            unset($this->scopeVarMaps[$this->scopeDepth]);
            $this->scopeDepth--;
        }

        if ($node instanceof Stmt\Class_) {
            $this->currentClass = '';
        }

        return null;
    }

    private function isReservedVar(string $name): bool
    {
        if ($name === 'this') return true;
        if ($name === 'wpdb') return true;
        if ($name === 'post') return true;
        if ($name === 'GET' || $name === 'POST' || $name === 'REQUEST' || $name === 'SERVER'
            || $name === 'SESSION' || $name === 'COOKIE' || $name === 'FILES' || $name === 'ENV') return true;
        if ($name === 'GLOBALS') return true;
        // Common WordPress globals
        if (in_array($name, ['wpdb','post','current_user','wp_query','wp_rewrite',
            'wp_version','pagenow','allowedtags','allowedposttags','wp_locale',
            'wp_admin_bar','wp_roles','wp_filesystem','phpmailer'])) return true;
        return false;
    }
}

class CommentStripper extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        $node->setAttribute('comments', []);
        return null;
    }
}

function run_layer0(string $in, string $out): void
{
    $code = file_get_contents($in);
    $lexer = new Lexer\Emulative(PhpVersion::fromString('7.4'));
    $parser = (new ParserFactory())->createForHostVersion($lexer);
    $ast = $parser->parse($code);

    $t0 = new NodeTraverser();
    $t0->addVisitor(new CommentStripper());
    $ast = $t0->traverse($ast);

    $collector = new SymbolCollector();
    $tc = new NodeTraverser();
    $tc->addVisitor($collector);
    $ast = $tc->traverse($ast);

    $t1 = new NodeTraverser();
    $t1->addVisitor(new SymbolRenamer($collector->methodMap, $collector->propertyMap));
    $ast = $t1->traverse($ast);

    $printer = new PrettyPrinter();
    file_put_contents($out, $printer->prettyPrintFile($ast));
}

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $f = $argv[1];
    if (!file_exists($f)) { fwrite(STDERR, "Not found: $f\n"); exit(1); }
    run_layer0($f, $f);
    echo "Layer 0 done: $f\n";
}
