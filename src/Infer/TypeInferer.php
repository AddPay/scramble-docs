<?php

namespace Dedoc\Scramble\Infer;

use Dedoc\Scramble\Infer\Extensions\ExpressionExceptionExtension;
use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Handler\ArrayHandler;
use Dedoc\Scramble\Infer\Handler\ArrayItemHandler;
use Dedoc\Scramble\Infer\Handler\AssignHandler;
use Dedoc\Scramble\Infer\Handler\ClassHandler;
use Dedoc\Scramble\Infer\Handler\CreatesScope;
use Dedoc\Scramble\Infer\Handler\ExceptionInferringExtensions;
use Dedoc\Scramble\Infer\Handler\ExpressionTypeInferringExtensions;
use Dedoc\Scramble\Infer\Handler\FunctionLikeHandler;
use Dedoc\Scramble\Infer\Handler\NewHandler;
use Dedoc\Scramble\Infer\Handler\PropertyFetchHandler;
use Dedoc\Scramble\Infer\Handler\PropertyHandler;
use Dedoc\Scramble\Infer\Handler\ReturnHandler;
use Dedoc\Scramble\Infer\Handler\ThrowHandler;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\NodeTypesResolver;
use Dedoc\Scramble\Infer\Scope\PendingTypes;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\Reference\AbstractReferenceType;
use Dedoc\Scramble\Support\Type\UnknownType;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class TypeInferer extends NodeVisitorAbstract
{
    public Scope $scope;

    private array $handlers;

    private FileNameResolver $namesResolver;

    public function __construct(
        FileNameResolver $namesResolver,
        array $extensions,
        array $handlers,
        private ReferenceTypeResolver $referenceTypeResolver,
        private Index $index,
    ) {
        $this->namesResolver = $namesResolver;

        $this->handlers = [
            new FunctionLikeHandler(),
            new AssignHandler(),
            new NewHandler(),
            new ClassHandler(),
            new PropertyHandler(),
            new PropertyFetchHandler(),
            new ArrayHandler(),
            new ArrayItemHandler(),
            new ReturnHandler(),
            new ThrowHandler(),
            new ExpressionTypeInferringExtensions(array_values(array_filter(
                $extensions,
                fn ($ext) => $ext instanceof ExpressionTypeInferExtension,
            ))),
            new ExceptionInferringExtensions(array_values(array_filter(
                $extensions,
                fn ($ext) => $ext instanceof ExpressionExceptionExtension,
            ))),
            ...$handlers,
        ];
    }

    public function enterNode(Node $node)
    {
        $scope = $this->getOrCreateScope();

        foreach ($this->handlers as $handler) {
            if (! $handler->shouldHandle($node)) {
                continue;
            }

            if ($handler instanceof CreatesScope) {
                $this->scope = $handler->createScope($scope, $node);
            }

            if (method_exists($handler, 'enter')) {
                $handler->enter($node, $this->scope);
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        foreach ($this->handlers as $handler) {
            if (! $handler->shouldHandle($node)) {
                continue;
            }

            if (method_exists($handler, 'leave')) {
                $handler->leave($node, $this->scope);
            }

            if ($handler instanceof CreatesScope) {
                $this->scope = $this->scope->parentScope;
            }
        }

        return null;
    }

    public function afterTraverse(array $nodes)
    {
        /*
         * Now only one file a time gets traversed. So it is ok to simply take everything
         * added to index and check for reference types.
         *
         * At this point, if the function return types are not resolved, they aren't resolveable at all,
         * hence changed to the unknowns.
         *
         * When more files would be traversed in a single run (and index will be shared), this needs to
         * be re-implemented (maybe not).
         *
         * The intent here is to traverse symbols in index added through the file traversal. This logic
         * may be not applicable when analyzing multiple files per index. Pay attention to this as it may
         * hurt performance unless handled.
         */
        foreach ($this->index->functions as $functionType) {
            $functionReturnReference = ReferenceTypeResolver::hasResolvableReferences($functionType->getReturnType())
                ? $functionType->getReturnType()
                : null;

            if ($functionReturnReference) {
                $resolvedReference = $this->referenceTypeResolver->resolve($functionReturnReference);

                $functionType->setReturnType(
                    $resolvedReference instanceof AbstractReferenceType
                        ? new UnknownType('todo: make sure some context is here')
                        : $resolvedReference,
                );
            }
        }

        foreach ($this->index->classes as $classType) {
            $methodReturnReferences = collect($classType->methods)
                ->map(fn ($t) => $t->getReturnType())
                ->filter(ReferenceTypeResolver::hasResolvableReferences(...));

            foreach ($methodReturnReferences as $methodName => $methodReturnReference) {
                $resolvedReference = $this->referenceTypeResolver->resolve($methodReturnReference);

                $classType->methods[$methodName]->setReturnType(
                    $resolvedReference instanceof AbstractReferenceType
                        ? new UnknownType('todo: make sure some context is here')
                        : $resolvedReference,
                );
            }
        }
    }

    private function getOrCreateScope()
    {
        if (! isset($this->scope)) {
            $this->scope = new Scope(
                $this->index,
                new NodeTypesResolver,
                new PendingTypes,
                new ScopeContext,
                $this->namesResolver,
            );
        }

        return $this->scope;
    }
}
