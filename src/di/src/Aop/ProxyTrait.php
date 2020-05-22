<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Di\Aop;

use Closure;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\Annotation\AspectCollector;
use Hyperf\Di\ReflectionManager;
use Hyperf\Utils\ApplicationContext;

trait ProxyTrait
{
    /**
     * Cache the aspects for the proxy class.
     *
     * @var array
     */
    protected static $aspects;

    protected static function __proxyCall(
        string $className,
        string $method,
        array $arguments,
        Closure $closure
    ) {
        $proceedingJoinPoint = new ProceedingJoinPoint($closure, $className, $method, $arguments);
        $result = self::handleAround($proceedingJoinPoint);
        unset($proceedingJoinPoint);
        return $result;
    }

    /**
     * @TODO This method will be called everytime, should optimize it later.
     */
    protected static function __getParamsMap(string $className, string $method, array $args): array
    {
        $map = [
            'keys' => [],
            'order' => [],
        ];
        $reflectMethod = ReflectionManager::reflectMethod($className, $method);
        $reflectParameters = $reflectMethod->getParameters();
        $leftArgCount = count($args);
        foreach ($reflectParameters as $key => $reflectionParameter) {
            $arg = $reflectionParameter->isVariadic() ? $args : array_shift($args);
            if (! isset($arg) && $leftArgCount <= 0) {
                $arg = $reflectionParameter->getDefaultValue();
            }
            --$leftArgCount;
            $map['keys'][$reflectionParameter->getName()] = $arg;
            $map['order'][] = $reflectionParameter->getName();
        }
        return $map;
    }

    private static function handleAround(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $className = $proceedingJoinPoint->className;
        $methodName = $proceedingJoinPoint->methodName;
        if (! isset(static::$aspects[$className][$methodName])) {
            static::$aspects[$className][$methodName] = [];
            $aspects = array_unique(array_merge(static::getClassesAspects($className, $methodName), static::getAnnotationAspects($className, $methodName)));
            $queue = new \SplPriorityQueue();
            foreach ($aspects as $aspect) {
                $queue->insert($aspect, AspectCollector::getPriority($aspect));
            }
            while ($queue->valid()) {
                static::$aspects[$className][$methodName][] = $queue->current();
                $queue->next();
            }

            unset($annotationAspects, $aspects, $queue);
        }

        if (empty(static::$aspects[$className][$methodName])) {
            return $proceedingJoinPoint->processOriginalMethod();
        }

        return static::makePipeline()->via('process')
            ->through(static::$aspects[$className][$methodName])
            ->send($proceedingJoinPoint)
            ->then(function (ProceedingJoinPoint $proceedingJoinPoint) {
                return $proceedingJoinPoint->processOriginalMethod();
            });
    }

    private static function makePipeline(): Pipeline
    {
        $container = ApplicationContext::getContainer();
        if (method_exists($container, 'make')) {
            $pipeline = $container->make(Pipeline::class);
        } else {
            $pipeline = new Pipeline($container);
        }
        return $pipeline;
    }

    private static function getClassesAspects(string $className, string $method): array
    {
        $aspects = AspectCollector::get('classes', []);
        $matchedAspect = [];
        foreach ($aspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                if (Aspect::isMatch($className, $method, $rule)) {
                    $matchedAspect[] = $aspect;
                    break;
                }
            }
        }
        // The matched aspects maybe have duplicate aspect, should unique it when use it.
        return $matchedAspect;
    }

    private static function getAnnotationAspects(string $className, string $method): array
    {
        $matchedAspect = $annotations = $rules = [];

        $classAnnotations = AnnotationCollector::get($className . '._c', []);
        $methodAnnotations = AnnotationCollector::get($className . '._m.' . $method, []);
        $annotations = array_unique(array_merge(array_keys($classAnnotations), array_keys($methodAnnotations)));
        if (! $annotations) {
            return $matchedAspect;
        }

        $aspects = AspectCollector::get('annotations', []);
        foreach ($aspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                foreach ($annotations as $annotation) {
                    if (strpos($rule, '*') !== false) {
                        $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $rule);
                        $pattern = "/^{$preg}$/";
                        if (! preg_match($pattern, $annotation)) {
                            continue;
                        }
                    } elseif ($rule !== $annotation) {
                        continue;
                    }
                    $matchedAspect[] = $aspect;
                }
            }
        }
        // The matched aspects maybe have duplicate aspect, should unique it when use it.
        return $matchedAspect;
    }
}
