<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\AppFramework\Utility;

use ArrayAccess;
use Closure;
use OCP\AppFramework\QueryException;
use OCP\IContainer;
use Pimple\Container;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionNamedType;
use function class_exists;

/**
 * SimpleContainer is a simple implementation of a container on basis of Pimple
 */
class SimpleContainer implements ArrayAccess, ContainerInterface, IContainer {

	/**
	 * @var Container
	 *
	 * This is the global container containing globally registered services.
	 * These will be persisted across requests and shared between workers.
	 */
	protected $container;

	/**
	 * @var array[string]Container
	 *
	 * This is a list of containers that are specific to a thread.
	 */
	protected $threadContainers;

	/**
	 * @var array[string]Closure
	 *
	 * This is a list of closures that are used to build non-persistent services.
	 */
	protected $factories;

	public function __construct() {
		$this->container = new Container();
		$this->threadContainers = [];
		$this->factories = [];
	}

	/**
	 * @template T
	 * @param class-string<T>|string $id
	 * @return T|mixed
	 * @psalm-template S as class-string<T>|string
	 * @psalm-param S $id
	 * @psalm-return (S is class-string<T> ? T : mixed)
	 * @throws QueryException
	 */
	public function get(string $id) {
		return $this->query($id);
	}

	/**
	 * Query a service from the global container.
	 * If the service does not exist, it will be instantiated and stored
	 * as a global service in the global container.
	 *
	 * Use with caution. Global services are shared between all threads.
	 *
	 * @template T
	 * @param class-string<T>|string $id
	 * @return T|mixed
	 * @throws QueryException
	 */
	public function setGlobal(string $id) {
		$service = $this->get($id);
		$this->registerGlobalParameter($id, $service);
		return $service;
	}

	public function has(string $id): bool {
		// If a service is no registered but is an existing class, we can probably load it
		return isset($this->container[$id])
		    || isset($this->getThreadContainer()[$id])
			|| isset($this->factories[$id])
			|| class_exists($id);
	}

	public function hasInstance(string $id): bool {
		return isset($this->container[$id])
		    || isset($this->getThreadContainer()[$id]);
	}

	/**
	 * Get the local container for the current thread.
	 */
	public function getThreadContainer(): Container {
		$threadId = \ContextManager::id();
		if (!isset($this->threadContainers[$threadId])) {
			$this->threadContainers[$threadId] = new Container();
		}
		return $this->threadContainers[$threadId];
	}

	/**
	 * @param ReflectionClass $class the class to instantiate
	 * @return \stdClass the created class
	 * @suppress PhanUndeclaredClassInstanceof
	 */
	private function buildClass(ReflectionClass $class) {
		$constructor = $class->getConstructor();
		if ($constructor === null) {
			return $class->newInstance();
		}

		return $class->newInstanceArgs(array_map(function (ReflectionParameter $parameter) use ($class) {
			$parameterType = $parameter->getType();

			$resolveName = $parameter->getName();

			// try to find out if it is a class or a simple parameter
			if ($parameterType !== null && ($parameterType instanceof ReflectionNamedType) && !$parameterType->isBuiltin()) {
				$resolveName = $parameterType->getName();
			}

			try {
				$builtIn = $parameter->hasType() && ($parameter->getType() instanceof ReflectionNamedType)
					&& $parameter->getType()->isBuiltin();
				return $this->query($resolveName, !$builtIn);
			} catch (QueryException $e) {
				// Service not found, use the default value when available
				if ($parameter->isDefaultValueAvailable()) {
					return $parameter->getDefaultValue();
				}

				if ($parameterType !== null && ($parameterType instanceof ReflectionNamedType) && !$parameterType->isBuiltin()) {
					$resolveName = $parameter->getName();
					try {
						return $this->query($resolveName);
					} catch (QueryException $e2) {
						// don't lose the error we got while trying to query by type
						throw new QueryException($e2->getMessage(), (int) $e2->getCode(), $e);
					}
				}

				throw $e;
			}
		}, $constructor->getParameters()));
	}

	public function resolve($name) {
		$baseMsg = 'Could not resolve ' . $name . '!';
		try {
			$class = new ReflectionClass($name);
			if ($class->isInstantiable()) {
				return $this->buildClass($class);
			} else {
				throw new QueryException($baseMsg .
					' Class can not be instantiated');
			}
		} catch (ReflectionException $e) {
			throw new QueryException($baseMsg . ' ' . $e->getMessage());
		}
	}

	public function query(string $name, bool $autoload = true) {
		if (\ContextManager::id() === 0 && isset($this->factories[$name])) {
			// if (str_contains($name, 'Session')) {
			// 	error_log('WARNING: Instantiating a non-persistent service (' . $name . ') in the global container.');
			// 	ob_start();
			// 	debug_print_backtrace();
			// 	error_log(ob_get_clean());
			// }
		}

		$name = $this->sanitizeName($name);

		// Look for a superglobal object
		if (\OC::$server !== null && $this !== \OC::$server && \OC::$server->hasInstance($name)) {
			return \OC::$server->get($name);
		}

		// Look in globals first
		if (isset($this->container[$name])) {
			return $this->container[$name];
		}

		// Look in thread locals
		$threadContainer = $this->getThreadContainer();
		if (isset($threadContainer[$name])) {
			return $threadContainer[$name];
		}

		// Look for a local factory
		if (isset($this->factories[$name])) {
			$instance = $this->factories[$name]();
			$threadContainer[$name] = $instance;
			return $instance;
		}

		// Attempt to directly load the class
		if ($autoload) {
			$object = $this->resolve($name);
			$threadContainer[$name] = $object;
			return $object;
		}

		throw new QueryException('Could not resolve ' . $name . '!');
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function registerParameter($name, $value, $global = false) {
		$this->getThreadContainer()[$name] = $value;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function registerGlobalParameter($name, $value, $global = false) {
		$this->container[$name] = $value;
	}

	/**
	 * The given closure is called to create the specified service.
	 * The closure has to return the instance for the given service.
	 * Created instance will be stored as global in case $global is true.
	 *
	 * @param string $name name of the service to register another backend for
	 * @param Closure $closure the closure to be called on service creation
	 * @param bool $global
	 */
	public function registerService($name, Closure $closure, $global = false) {
		$wrapped = function () use ($closure) {
			return $closure($this);
		};
		$name = $this->sanitizeName($name);

		if ($global) {
			$this->container[$name] = $this->container->factory($wrapped);
		} else {
			$this->factories[$name] = $wrapped;
		}
	}

	/**
	 * See the docs of registerService for details.
	 */
	public function registerGlobalService(string $name, Closure $closure) {
		$this->registerService($name, $closure, true);
	}

	/**
	 * Shortcut for returning a service from a service under a different key,
	 * e.g. to tell the container to return a class when queried for an
	 * interface
	 * @param string $alias the alias that should be registered
	 * @param string $target the target that should be resolved instead
	 */
	public function registerAlias($alias, $target) {
		$this->registerService($alias, function (ContainerInterface $container) use ($target) {
			return $container->get($target);
		}, false);
	}

	/*
	 * @param string $name
	 * @return string
	 */
	protected function sanitizeName($name) {
		if (isset($name[0]) && $name[0] === '\\') {
			return ltrim($name, '\\');
		}
		return $name;
	}

	/**
	 * @deprecated 20.0.0 use \Psr\Container\ContainerInterface::has
	 */
	public function offsetExists($id): bool {
		return $this->has($id);
	}

	/**
	 * @deprecated 20.0.0 use \Psr\Container\ContainerInterface::get
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet($id) {
		return $this->get($id);
	}

	/**
	 * @deprecated 20.0.0 use \OCP\IContainer::registerService
	 */
	public function offsetSet($id, $service): void {
		$this->container->offsetSet($id, $service);
	}

	/**
	 * @deprecated 20.0.0
	 */
	public function offsetUnset($offset): void {
		$this->container->offsetUnset($offset);
	}
}
