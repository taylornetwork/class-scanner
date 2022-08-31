<?php

namespace Violet\ClassScanner\Tests;

use PhpParser\NameContext;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\ErrorHandler\Throwing;
use Violet\ClassScanner\NameResolver;
use Violet\ClassScanner\DefinitionFactory;
use Violet\ClassScanner\Exception\UnexpectedNodeException;

/**
 * DefinitionFactoryTest.
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2019 Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class DefinitionFactoryTest extends TestCase
{
    public function testInvalidClassNode(): void
    {
        $factory = new DefinitionFactory(new NameResolver(new NameContext(new Throwing())));

        $this->expectException(UnexpectedNodeException::class);
        $factory->createFromNode(new class() extends ClassLike {
            public function getType(): string
            {
                return '';
            }

            public function getSubNodeNames(): array
            {
                return ['name', 'stmts'];
            }
        });
    }

    public function testAnonymousClass(): void
    {
        $factory = new DefinitionFactory(new NameResolver(new NameContext(new Throwing())));

        $this->expectException(UnexpectedNodeException::class);
        $factory->createFromNode(new Class_(null));
    }
}
